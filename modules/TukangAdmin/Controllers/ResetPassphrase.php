<?php

namespace Modules\TukangAdmin\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\TukangAdmin\Config\Bsre as BsreConfig;
use Modules\TukangAdmin\Config\Module;
use Modules\TukangAdmin\Libraries\BsreClient;
use Modules\TukangAdmin\Libraries\BsreClientException;
use Modules\TukangAdmin\Libraries\PhoneNumbers;
use Modules\TukangAdmin\Libraries\SearchIdentifier;
use Modules\TukangAdmin\Libraries\TteScraper;
use Modules\TukangAdmin\Libraries\TteScraperException;
use Modules\TukangAdmin\Libraries\TteUpdateReport;
use Modules\TukangAdmin\Libraries\TteUserNotFoundException;
use Modules\TukangAdmin\Models\BsreCredentialModel;
use Modules\TukangAdmin\Models\ResetDraftModel;
use Modules\TukangAdmin\Models\TteCredentialModel;
use Modules\TukangKirim\Libraries\MaxChatService;
use Throwable;

/**
 * Reset passphrase BSrE dalam satu halaman, beberapa langkah POST lewat fetch().
 *
 * Pencarian mengikuti pola Reset Password TTE: NOMOR HP adalah input utama —
 * itulah yang benar-benar dipegang operator saat ada permintaan — dan kolom
 * cadangan NIK/Email baru muncul bila pencarian lewat nomor tidak menemukan
 * siapa pun. Nomor yang diketik itu pula yang jadi acuan: nomor pada akun TTE
 * MAUPUN BSrE dibandingkan dengannya, dan bila berbeda operator memutuskan
 * memperbarui atau melewatinya SEBELUM langkah yang tidak bisa dibatalkan.
 *
 * TTE SELALU dibuka lebih dulu, apa pun kunci pencariannya — nomor, NIK, atau
 * email. Kolom cadangan bukan berarti "lewati TTE" melainkan "cari di TTE pakai
 * kunci lain": nomor yang usang di TTE justru sebab paling sering pencarian
 * lewat nomor gagal, jadi melompatinya berarti meninggalkan sebabnya utuh dan
 * permintaan berikutnya untuk orang yang sama akan gagal dengan cara yang sama.
 * Email dari TTE itu pula yang dipakai mencari di BSrE, karena pencarian BSrE
 * lewat NIK diketahui rapuh ({@see BsreClient::findUser()}).
 *
 * Alur manual di portal yang dipindahkan ke sini:
 *
 *   1. Tab "Ubah Akun" -> kolom Nomor Handphone -> Simpan, LALU
 *      /app/users/update/list -> Detail -> Verifikasi       ({@see self::updatePhone()})
 *   2. Tab Sertifikat Elektronik -> Aksi > Reset passphrase ({@see self::dispatch()})
 *
 * Menyimpan nomor dan menyetujuinya adalah SATU tombol, bukan dua: perubahan
 * data akun di BSrE tidak berlaku sampai disetujui, jadi berhenti di antara
 * keduanya berarti meninggalkan akun dengan nomor lama yang masih menjadi tujuan
 * tautan verifikasi. Persetujuan yang gagal punya jalur ulang tersendiri
 * ({@see self::approveUpdate()}), dan itulah satu-satunya cara keadaan `approve`
 * masih bisa muncul.
 *
 * Langkah 2 adalah SATU aksi dengan dua kemungkinan, persis seperti di portal:
 *   - HP belum terverifikasi -> muncul dialog konfirmasi; menekan "Verifikasi"
 *     membuat BSrE mengirim tautan verifikasi ke WhatsApp pengguna. Passphrase
 *     belum direset, dan alurnya SELESAI di sini — operator tidak menunggui
 *     pengguna membuka tautannya. Bila nanti nomornya sudah terverifikasi, reset
 *     dilakukan lewat pencarian baru dari awal.
 *   - HP sudah terverifikasi -> tidak ada dialog verifikasi; BSrE langsung
 *     mengirim tautan reset passphrase ke email dinas pengguna.
 *
 * Login + OTP tidak dilakukan di sini; token diperoleh sekali lewat menu Akun
 * BSrE dan dipakai ulang selama masih berlaku.
 *
 * Draft konfirmasi disimpan di DB (bukan session), jadi dua tab pada browser yang
 * sama tidak saling menimpa. Langkah-langkah antara membacanya lewat peek() tanpa
 * mengklaim — kegagalan langkah opsional tidak boleh menghanguskan draft yang
 * masih sah — sementara langkah terakhir memanggil claim(): kedua cabangnya
 * sama-sama mengirim sesuatu ke pengguna, jadi dua-duanya harus sekali-pakai.
 */
class ResetPassphrase extends BaseController
{
    protected const DRAFT_KIND = 'passphrase';

    /** Label kunci pencarian, dipakai menyusun keterangan "Ditemukan via …". */
    protected const BY_LABELS = ['nohp' => 'No HP', 'nik' => 'NIK', 'email' => 'Email'];

    protected BsreCredentialModel $credentials;
    protected ResetDraftModel $drafts;
    protected MaxChatService $maxchat;

    public function __construct()
    {
        $this->credentials = new BsreCredentialModel();
        $this->drafts      = new ResetDraftModel();
        $this->maxchat     = new MaxChatService();
    }

    public function index(): string
    {
        return view(Module::VIEWS . 'passphrase/form', [
            'title'         => 'Reset Passphrase',
            'hasCredential' => $this->credentials->current() !== null,
            'connected'     => BsreAccount::hasValidToken(),
        ]);
    }

    /**
     * Langkah 1 — cari akun lewat nomor HP (atau NIK/email), susun draft (AJAX).
     */
    public function search(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url('passphrase'));
        }

        $token = BsreAccount::currentAccessToken();

        if ($token === null) {
            return $this->panel('error', null, ['Belum terhubung ke BSrE. Login dulu di menu Akun BSrE.']);
        }

        // Nomor HP wajib walau pencarian akhirnya lewat NIK/email: nomor itulah
        // yang nanti dibandingkan dengan data BSrE dan, bila perlu, ditulis ke
        // sana. Tanpa nomor, review nomor tidak punya acuan apa pun.
        $phone    = trim((string) $this->request->getPost('phone'));
        $fallback = trim((string) $this->request->getPost('fallback'));

        if ($phone === '') {
            return $this->panel('error', null, ['Nomor HP wajib diisi.']);
        }

        // Validasi ketat saat INPUT, bukan setelah pulang-pergi ke TTE/BSrE:
        // operator langsung tahu nomornya cacat tanpa menunggu scraping.
        $normalized = $this->maxchat->normalizedMobile($phone);

        if ($normalized === null) {
            return $this->panel('error', null, [
                'Nomor HP tidak valid: "' . $phone . '". Gunakan format 08xx / 62xx dengan panjang wajar.',
            ]);
        }

        $searchParams = config(BsreConfig::class)->searchParams;

        // Kunci pencarian TTE: beberapa bentuk nomor, atau NIK/email bila
        // pencarian lewat nomor tadi tidak menemukan siapa pun.
        if ($fallback === '') {
            $by      = 'nohp';
            $value   = $phone;
            $queries = PhoneNumbers::variants($normalized);
        } else {
            $detected = SearchIdentifier::detect($fallback);

            if (isset($detected['error'])) {
                return $this->panel('error', null, [$detected['error']]);
            }

            $by      = $detected['by'];
            $value   = $detected['value'];
            $queries = [$value];
        }

        // TTE lebih dulu — SELALU, termasuk lewat kolom cadangan. Di sanalah
        // nomor pengguna dicocokkan, dan email yang dikembalikannya adalah kunci
        // paling andal untuk mencari di BSrE.
        $tte          = $this->tteRow($queries);
        $tteRow       = $tte['user'] ?? null;
        $tteError     = null;
        $resolvedFrom = null;

        if ($tteRow !== null) {
            $bsreValue     = $tteRow['email'];
            $bsreFilterKey = $searchParams['email'];
            $resolvedFrom  = self::BY_LABELS[$by] . ' ' . $value . ' → ' . $tteRow['email']
                . ($tteRow['name'] !== '' ? ' (' . $tteRow['name'] . ')' : '');
        } elseif ($by === 'nohp') {
            // Jalur nomor buntu: BSrE memang tidak bisa dicari lewat nomor, jadi
            // tanpa email dari TTE tidak ada yang bisa dilanjutkan. "Tidak ketemu"
            // memunculkan kolom cadangan; sebab lain tidak — jangan pernah
            // menyuruh operator "coba NIK" karena markup TTE berubah.
            if (isset($tte['notfound'])) {
                return $this->panel('notfound', view(Module::VIEWS . 'passphrase/_panel_notfound', [
                    'phone'  => $phone,
                    'reason' => $tte['notfound'],
                ]));
            }

            return $this->panel('error', null, [$tte['error']]);
        } else {
            // Jalur cadangan: TTE buntu bukan penghenti — NIK/email yang diketik
            // operator masih bisa dipakai mencari di BSrE, dan nomor TTE tinggal
            // dilaporkan tidak bisa diperiksa.
            $bsreValue     = $value;
            $bsreFilterKey = $searchParams[$by];
            $tteError      = $tte['notfound'] ?? $tte['error'];
        }

        try {
            $user = $this->client($token)->findUser($bsreValue, $bsreFilterKey);
        } catch (BsreClientException $e) {
            return $this->panel('error', null, [$e->getMessage()]);
        } catch (Throwable $e) {
            return $this->panel('error', null, ['Kesalahan tak terduga saat menghubungi BSrE: ' . $e->getMessage()]);
        }

        if ($user['certificates'] === []) {
            return $this->panel('error', null, ['Pengguna ditemukan, tetapi tidak punya sertifikat elektronik yang bisa direset.']);
        }

        // Sertifikat pertama dipakai sebagai target reset (umumnya satu aktif).
        $target = $user['certificates'][0];

        $draft = [
            'uid'           => $user['uid'],
            'email'         => $user['email'],
            'name'          => $user['name'],
            'nik'           => $user['nik'],
            'phoneVerified' => $user['phoneVerified'],
            'serial'        => $target['serial'],
            'jenis'         => $target['jenis'],
            'cert_count'    => count($user['certificates']),

            // Nomor yang diketik operator — acuan review, dan calon nilai baru.
            'phone_typed'      => $phone,
            'phone_input'      => $phone,
            'phone_normalized' => $normalized,
            // Nomor yang tercatat di masing-masing sistem, untuk dibandingkan
            // di panel — dan, bila berbeda, ditimpa dengan nomor yang diketik.
            'phone_bsre'       => $user['phone'],
            'phone_tte'        => $tteRow['phone'] ?? null,
            'tte_edit_url'     => $tteRow['edit_url'] ?? null,

            'search_by'     => $by,
            'search_value'  => $value,
            'resolved_from' => $resolvedFrom,

            // Penanda kemajuan langkah; menentukan tombol mana yang ditawarkan.
            'phone_update_submitted' => false,
            'update_request_id'      => null,
            'update_approved'        => false,
            'approve_error'          => null,
            'tte_updated'            => false,
            'tte_error'              => $tteError,
            'wa_skipped'             => false,
            'notes'                  => [],
        ];

        // Draft disimpan di DB dan id-nya ditanam di panel; langkah berikutnya
        // hanya menyentuh draft dengan id ini, jadi tab lain tak bisa menimpanya.
        $draftId = $this->drafts->create(self::DRAFT_KIND, $draft);

        return $this->previewPanel($draft, $draftId);
    }

    /**
     * Langkah antara — simpan nomor baru di BSrE, lalu setujui perubahannya (AJAX).
     *
     * Dua panggilan, SATU tombol. Perubahan data akun tidak berlaku sampai
     * disetujui, jadi berhenti sesudah menyimpan berarti meninggalkan akun dengan
     * nomor lama masih terpasang — dan tautan verifikasi tetap akan dikirim ke
     * nomor lama itu. Operator yang menekan "Perbarui" bermaksud nomornya
     * berlaku, bukan mengantre.
     *
     * Draft dibaca TANPA diklaim: bila langkah ini gagal, preview yang sudah sah
     * harus tetap utuh supaya operator bisa menekan "Lewati" dan melanjutkan.
     */
    public function updatePhone(): ResponseInterface|RedirectResponse
    {
        return $this->step(function (array $draft, string $draftId, BsreClient $client): array|ResponseInterface {
            $pending = $this->pendingSystems($draft);

            // TTE lebih dulu: itu tujuan awalnya — nomornya dicocokkan di sana
            // supaya pencarian lewat nomor berikutnya tidak gagal lagi. Langkah
            // ini tidak pernah melempar, jadi tidak bisa menahan yang berikutnya.
            if (in_array('tte', $pending, true)) {
                $draft = $this->updateTte($draft);
            }

            // BSrE menyusul. Galatnya sengaja dibiarkan naik ke step() sebagai
            // panel error; perbaikan TTE yang sudah berhasil ikut hilang bersama
            // draft yang tidak jadi disimpan — dan itu tidak apa-apa, karena
            // menulis nomor yang sama ke TTE untuk kedua kalinya tidak berbahaya
            // ({@see TteScraper::updateWhatsapp()} berhenti bila sudah sama).
            if (in_array('bsre', $pending, true)) {
                $draft = $this->updateBsre($draft, $client);
            }

            return $draft;
        }, 'Gagal memperbarui nomor: ');
    }

    /**
     * Simpan nomor baru di BSrE, lalu setujui perubahannya.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    protected function updateBsre(array $draft, BsreClient $client): array
    {
        // Sudah pernah dikirim (mis. yang gagal justru persetujuannya) -> jangan
        // menyimpan nomor untuk kedua kalinya, langsung ke persetujuannya.
        if (empty($draft['phone_update_submitted'])) {
            // BSrE ditulis dalam bentuk lokal 08…: itulah yang portal kirim saat
            // menyimpan maupun saat memverifikasi nomor, walau profilnya sendiri
            // bisa menyimpan 62…. Meniru portal lebih aman daripada mengikuti
            // bentuk nilai lama seperti yang dilakukan pada TTE.
            $result = $client->updateUserPhone(
                (string) $draft['uid'],
                '0' . PhoneNumbers::nsn((string) $draft['phone_normalized']),
            );

            $draft['phone_update_submitted'] = true;
            $draft['notes'][]                = $result['message'];
        }

        try {
            return $this->approve($draft, $client);
        } catch (BsreClientException $e) {
            // Nomornya SUDAH tersimpan di BSrE. Apa pun nasib persetujuannya,
            // draft wajib pulang membawa fakta itu — bila galatnya dilempar
            // keluar, {@see self::step()} tidak menyimpan apa pun dan tekanan
            // berikutnya mengirim perubahan nomor untuk kedua kalinya. Panel
            // berhenti di keadaan `approve` yang menawarkan jalur ulang.
            $draft['approve_error'] = $e->getMessage();

            return $draft;
        }
    }

    /**
     * Simpan nomor baru di TTE (form "ubah pengguna").
     *
     * TIDAK PERNAH MELEMPAR. Nomor di TTE tidak menentukan apa pun pada reset
     * passphrase — tautan reset ke email, tautan verifikasi ke nomor BSrE — jadi
     * kegagalannya cuma dicatat, dan alurnya jalan terus. Melemparnya justru
     * akan membuang keberhasilan langkah lain bersama draft yang tidak jadi
     * disimpan {@see self::step()}.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    protected function updateTte(array $draft): array
    {
        $tte  = new TteCredentialModel();
        $cred = $tte->current();

        if ($cred === null) {
            $draft['tte_error'] = 'Nomor di TTE tidak diperbarui: kredensial TTE belum diisi.';

            return $draft;
        }

        try {
            $scraper = new TteScraper(null, $cred['base_url'] ?? null);
            $scraper->login($cred['username'], $tte->plainPassword($cred));
            $result = $scraper->updateWhatsapp(
                ['edit_url' => (string) $draft['tte_edit_url']],
                (string) $draft['phone_normalized'],
            );
        } catch (TteScraperException $e) {
            $draft['tte_error'] = $e->getMessage();

            return $draft;
        } catch (Throwable $e) {
            $draft['tte_error'] = 'Kesalahan tak terduga saat memperbarui nomor di TTE: ' . $e->getMessage();

            return $draft;
        }

        $draft['tte_updated'] = true;
        $draft['phone_tte']   = $result['after'];
        $draft['tte_error']   = null;
        $draft['notes']       = array_merge($draft['notes'], TteUpdateReport::notes($result));

        return $draft;
    }

    /**
     * Jalur ulang — setujui perubahan yang persetujuan otomatisnya gagal (AJAX).
     *
     * Persetujuan biasanya sudah dijalankan {@see self::updatePhone()} sebagai
     * bagian dari langkah yang sama; endpoint ini hanya terpakai bila di sana
     * gagal, dan itulah satu-satunya cara keadaan `approve` masih bisa muncul.
     */
    public function approveUpdate(): ResponseInterface|RedirectResponse
    {
        return $this->step(function (array $draft, string $draftId, BsreClient $client): array|ResponseInterface {
            if (! empty($draft['update_approved'])) {
                return $draft; // Idempoten.
            }

            if (empty($draft['phone_update_submitted'])) {
                return $this->panel('error', null, [
                    'Belum ada perubahan data yang perlu disetujui. Perbarui nomornya lebih dulu.',
                ]);
            }

            return $this->approve($draft, $client);
        }, 'Gagal menyetujui perubahan data di BSrE: ');
    }

    /**
     * "Lewati" — nomor di BSrE dibiarkan apa adanya (AJAX).
     *
     * Berbeda dengan Reset Password, ini BUKAN pilihan tanpa akibat: tautan
     * verifikasi akan dikirim BSrE ke nomor yang tercatat di sana, bukan ke nomor
     * yang diketik operator. Karena itu keputusannya dicatat di draft (bukan
     * sekadar disembunyikan di sisi klien) supaya panel yang dirender ulang
     * sesudahnya tetap ingat, dan peringatannya tetap terbaca.
     */
    public function skipUpdate(): ResponseInterface|RedirectResponse
    {
        return $this->step(static function (array $draft): array {
            $draft['wa_skipped'] = true;

            return $draft;
        }, 'Gagal menyimpan pilihan: ');
    }

    /**
     * Langkah terakhir — aksi "Reset passphrase" pada sertifikat terpilih (AJAX).
     *
     * Satu aksi, dua cabang, persis seperti di portal — dan KEDUANYA titik akhir:
     *   - HP belum terverifikasi -> tautan verifikasi dikirim ke WhatsApp pengguna,
     *     selesai. Passphrase belum direset.
     *   - HP sudah terverifikasi -> tautan reset dikirim ke email pengguna, selesai.
     *
     * Cabang verifikasi sengaja TIDAK menunggu pengguna membuka tautannya: pengguna
     * sering tidak bisa memverifikasi saat itu juga, dan operator tidak mungkin
     * menunggui panel terbuka. Bila nanti nomornya sudah terverifikasi, reset
     * dilakukan lewat pencarian baru dari awal. Karena itu draft diklaim di depan
     * untuk kedua cabang: sekali tekan, satu akibat.
     */
    public function dispatch(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url('passphrase'));
        }

        $token = BsreAccount::currentAccessToken();

        if ($token === null) {
            return $this->panel('error', null, ['Sesi BSrE berakhir. Login ulang di menu Akun BSrE.']);
        }

        $draftId = (string) $this->request->getPost('draft_id');
        $draft   = $this->drafts->peek(self::DRAFT_KIND, $draftId);

        if ($draft === null || empty($draft['uid']) || empty($draft['serial'])) {
            return $this->staleDraft();
        }

        // Diperiksa SEBELUM draft diklaim: menghanguskan draft lalu menyuruh
        // operator "perbarui nomornya dulu" berarti menyuruh sesuatu yang sudah
        // tidak mungkin ia lakukan tanpa mengulang pencarian.
        $phone = trim((string) ($draft['phone_bsre'] ?? ''));

        if (! $draft['phoneVerified'] && $phone === '') {
            return $this->panel('error', null, [
                'Nomor HP pada akun BSrE tidak terbaca, sehingga verifikasi tidak bisa dipicu. '
                . 'Perbarui nomornya lebih dulu lewat tombol di kartu nomor.',
            ]);
        }

        // Klaim: atomik & sekali-pakai, jadi klik ganda maupun tab lain tidak bisa
        // memicu dua kiriman dari draft yang sama. Kedua cabang sama-sama mengirim
        // sesuatu ke pengguna, jadi dua-duanya harus dilindungi.
        if ($this->drafts->claim(self::DRAFT_KIND, $draftId) === null) {
            return $this->staleDraft();
        }

        $client = $this->client($token);

        try {
            if (! $draft['phoneVerified']) {
                // Cabang 1: HP belum terverifikasi -> kirim tautan verifikasi WA.
                $message = $client->verifyPhone((string) $draft['uid'], $phone);

                return $this->panel('result', view(Module::VIEWS . 'passphrase/_panel_result', [
                    'result' => [
                        'outcome' => 'verify',
                        'email'   => $draft['email'],
                        'name'    => $draft['name'],
                        'phone'   => $phone,
                        'serial'  => $draft['serial'],
                        'message' => $message,
                    ],
                    'draftId' => $draftId,
                ]));
            }

            // Cabang 2: HP sudah terverifikasi -> tautan reset ke email pengguna.
            $message = $client->resetPassphrase((string) $draft['uid'], (string) $draft['serial']);
        } catch (BsreClientException $e) {
            return $this->panel('error', null, ['Gagal: ' . $e->getMessage()]);
        } catch (Throwable $e) {
            return $this->panel('error', null, ['Kesalahan tak terduga: ' . $e->getMessage()]);
        }

        return $this->panel('result', view(Module::VIEWS . 'passphrase/_panel_result', [
            'result' => [
                'outcome' => 'reset',
                'email'   => $draft['email'],
                'name'    => $draft['name'],
                'phone'   => $draft['phone_bsre'],
                'serial'  => $draft['serial'],
                'message' => $message,
            ],
            'draftId' => $draftId,
        ]));
    }

    // ---------------------------------------------------------------------
    // Bagian dalam
    // ---------------------------------------------------------------------

    /**
     * Kerangka langkah antara: baca draft tanpa mengklaim, jalankan $work,
     * simpan hasilnya, lalu render ulang panel.
     *
     * Ketiga langkah antara berbagi kerangka yang sama persis — mengulanginya
     * tiga kali berarti tiga tempat yang bisa lupa memakai peek() alih-alih
     * claim(), dan draft yang hangus gara-gara langkah opsional adalah justru
     * yang dihindari di sini.
     *
     * @param callable(array<string, mixed>, string, BsreClient): (array<string, mixed>|ResponseInterface) $work
     */
    protected function step(callable $work, string $failPrefix): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url('passphrase'));
        }

        $token = BsreAccount::currentAccessToken();

        if ($token === null) {
            return $this->panel('error', null, ['Sesi BSrE berakhir. Login ulang di menu Akun BSrE.']);
        }

        $draftId = (string) $this->request->getPost('draft_id');
        $draft   = $this->drafts->peek(self::DRAFT_KIND, $draftId);

        if ($draft === null) {
            return $this->staleDraft();
        }

        try {
            $result = $work($draft, $draftId, $this->client($token));
        } catch (BsreClientException $e) {
            return $this->panel('error', null, [$failPrefix . $e->getMessage()]);
        } catch (Throwable $e) {
            return $this->panel('error', null, ['Kesalahan tak terduga: ' . $e->getMessage()]);
        }

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        // Langkah idempoten (mis. Perbarui yang ditekan dua kali) mengembalikan
        // draft yang sama persis. Menyimpannya tetap akan gagal — UPDATE dengan
        // nilai identik melaporkan 0 baris terpengaruh — dan operator melihat
        // "draft tidak bisa disimpan" padahal tidak ada yang salah.
        if ($result === $draft) {
            return $this->previewPanel($result, $draftId);
        }

        if (! $this->drafts->updatePayload(self::DRAFT_KIND, $draftId, $result)) {
            return $this->panel('error', null, [
                'Langkahnya berhasil di BSrE, tetapi draft tidak bisa disimpan (mungkin sudah diproses '
                . 'tab lain). Ulangi pencarian sebelum melanjutkan.',
            ]);
        }

        return $this->previewPanel($result, $draftId);
    }

    /**
     * Setujui perubahan data yang menunggu, lalu baca ulang keadaan dari BSrE.
     *
     * Setara membuka /app/users/update/list, mencari pengguna, lalu menekan
     * Verifikasi. Antrean persetujuannya dicari berdasarkan NIK/email — sama
     * seperti yang dilakukan operator di portal.
     *
     * Dipakai dua pemanggil: {@see self::updatePhone()} yang menjalankannya
     * otomatis sesudah menyimpan, dan {@see self::approveUpdate()} sebagai jalur
     * ulang bila yang otomatis gagal.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    protected function approve(array $draft, BsreClient $client): array
    {
        $searchParams = config(BsreConfig::class)->searchParams;

        if (! empty($draft['nik'])) {
            [$value, $filterKey] = [(string) $draft['nik'], $searchParams['nik']];
        } else {
            [$value, $filterKey] = [(string) $draft['email'], $searchParams['email']];
        }

        $request = $client->findUpdateRequest((string) $draft['uid'], $value, $filterKey);
        $message = $client->approveUserUpdate($request['id']);

        $draft['update_request_id'] = $request['id'];
        $draft['update_approved']   = true;
        // Percobaan ulang yang berhasil harus menghapus jejak kegagalan sebelumnya,
        // supaya panel tidak menyimpan peringatan yang sudah tidak benar lagi.
        $draft['approve_error']     = null;
        $draft['notes'][]           = $message;

        // Sesudah disetujui, nomor di BSrE sudah berubah — tetapi statusnya
        // belum tentu terverifikasi. Keadaan sebenarnya dibaca dari BSrE,
        // bukan disimpulkan di sini.
        return $this->syncFromBsre($draft, $client);
    }

    /**
     * Segarkan nomor & status verifikasi dari BSrE.
     *
     * Sesudah setiap langkah, tombol berikutnya ditentukan keadaan yang BSrE
     * laporkan — bukan asumsi kode tentang apa yang seharusnya terjadi.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    protected function syncFromBsre(array $draft, BsreClient $client): array
    {
        $fresh = $client->userDetails((string) $draft['uid'], (string) $draft['email']);

        $draft['phone_bsre']    = $fresh['phone'];
        $draft['phoneVerified'] = $fresh['phoneVerified'];

        if ($fresh['nik'] !== null) {
            $draft['nik'] = $fresh['nik'];
        }

        return $draft;
    }

    /**
     * Panel konfirmasi beserta keadaan yang menentukan tombol berikutnya.
     *
     * @param array<string, mixed> $draft
     */
    protected function previewPanel(array $draft, string $draftId): ResponseInterface
    {
        $typed = (string) $draft['phone_normalized'];

        return $this->panel('preview', view(Module::VIEWS . 'passphrase/_panel_preview', [
            'draft'   => $draft,
            'draftId' => $draftId,
            'pending' => $this->pendingSystems($draft),
            'stage'   => $this->stageFor($draft),
            // Perbandingan nomor dihitung di sini, bukan di view: aturannya
            // ({@see PhoneNumbers::matches()}) lintas format dan bukan urusan
            // penyaji.
            'matches' => [
                'tte'  => PhoneNumbers::matches($draft['phone_tte'] ?? null, $typed),
                'bsre' => PhoneNumbers::matches($draft['phone_bsre'] ?? null, $typed),
            ],
        ]));
    }

    /**
     * Sistem mana yang nomornya masih perlu ditulis: 'tte' dan/atau 'bsre'.
     *
     * Urutannya bermakna — TTE lebih dulu, sebagaimana alurnya dikerjakan.
     *
     * @param array<string, mixed> $draft
     *
     * @return list<string>
     */
    protected function pendingSystems(array $draft): array
    {
        $typed   = (string) $draft['phone_normalized'];
        $pending = [];

        // TTE hanya bisa ditulis lewat form "ubah pengguna", jadi barisnya harus
        // ketemu DAN tombol ubahnya terbaca. `tte_error` berarti sudah pernah
        // dicoba dan gagal — menahannya lagi cuma membuat operator menekan tombol
        // yang sama berulang tanpa hasil, padahal TTE tidak menghalangi reset.
        if (! empty($draft['tte_edit_url'])
            && empty($draft['tte_updated'])
            && empty($draft['tte_error'])
            && ! PhoneNumbers::matches($draft['phone_tte'] ?? null, $typed)) {
            $pending[] = 'tte';
        }

        // BSrE selalu bisa ditulis lewat API, jadi cukup kecocokan + kemajuan.
        if (empty($draft['phone_update_submitted'])
            && ! PhoneNumbers::matches($draft['phone_bsre'] ?? null, $typed)) {
            $pending[] = 'bsre';
        }

        return $pending;
    }

    /**
     * Langkah mana yang sedang ditunggu: decide | approve | act.
     *
     * Urutannya tidak bisa dilompati. Selama masih ada nomor yang berbeda dan
     * belum diputuskan, yang ditawarkan hanya Perbarui/Lewati — keduanya harus
     * jadi keputusan sadar sebelum langkah yang tidak bisa dibatalkan.
     *
     * Persetujuan diperiksa DULUAN karena sejak nomor ditulis ke dua sistem,
     * "menunggu persetujuan" dan "masih ada yang berbeda" bisa benar bersamaan —
     * mis. BSrE tersimpan tetapi persetujuannya gagal sementara TTE juga belum
     * cocok. Perubahan BSrE yang menggantung lebih mendesak: selama belum
     * disetujui, nomor lamanyalah yang masih berlaku di sana.
     *
     * @param array<string, mixed> $draft
     */
    protected function stageFor(array $draft): string
    {
        if (! empty($draft['phone_update_submitted']) && empty($draft['update_approved'])) {
            return 'approve';
        }

        if ($this->pendingSystems($draft) !== [] && empty($draft['wa_skipped'])) {
            return 'decide';
        }

        return 'act';
    }

    /**
     * Cari baris penandatangan di TTE (scraping, memakai kredensial Akun TTE).
     *
     * Barisnya dibawa pulang UTUH — email, nama, nomor, dan tautan "ubah" — bukan
     * sekadar emailnya: nomor yang tercatat di TTE itulah yang dibandingkan di
     * panel, dan tautan ubah itulah yang dipakai memperbaikinya. Dulu keduanya
     * dibuang di sini, sehingga TTE hanya bisa dibaca dan tidak pernah bisa
     * dibetulkan.
     *
     * Beberapa kata kunci dicoba dalam SATU sesi login. Pada pencarian lewat
     * nomor itu berarti beberapa BENTUK nomor — TTE menyimpan 08… sementara kita
     * memegang 62…, dan tanpa ini operator dapat "tidak ketemu" palsu; pada
     * pencarian lewat NIK/email cukup satu. Hasil "tidak ketemu" dipisahkan dari
     * galat lain karena keduanya ditangani berbeda — yang satu memunculkan kolom
     * cadangan, yang lain tidak.
     *
     * @param list<string> $queries
     *
     * @return array{user: array<string, mixed>}|array{notfound: string}|array{error: string}
     */
    protected function tteRow(array $queries): array
    {
        $tte  = new TteCredentialModel();
        $cred = $tte->current();

        if ($cred === null) {
            return ['error' => 'Pencarian di TTE butuh kredensial TTE. Isi dulu di Tukang Admin → Akun TTE.'];
        }

        try {
            $scraper = new TteScraper(null, $cred['base_url'] ?? null);
            $scraper->login($cred['username'], $tte->plainPassword($cred));

            $found    = null;
            $notFound = null;

            foreach ($queries as $query) {
                try {
                    $found = $scraper->findPenandatangan($query);

                    break;
                } catch (TteUserNotFoundException $e) {
                    $notFound = $e;
                }
            }

            if ($found === null) {
                return ['notfound' => ($notFound ?? new TteUserNotFoundException(
                    'Pencarian tidak menghasilkan apa pun.'
                ))->getMessage()];
            }
        } catch (TteScraperException $e) {
            // Markup berubah, hasil ganda, atau TTE tidak bisa dihubungi — jangan
            // pernah menyuruh operator "coba NIK" untuk sebab seperti ini.
            return ['error' => 'Pencarian di TTE gagal: ' . $e->getMessage()];
        } catch (Throwable $e) {
            return ['error' => 'Kesalahan tak terduga saat menghubungi TTE: ' . $e->getMessage()];
        }

        if (empty($found['email'])) {
            return ['notfound' => 'Akun ditemukan di TTE, tetapi emailnya tidak terbaca — '
                . 'padahal email itulah yang dipakai mencari di BSrE.'];
        }

        return ['user' => $found];
    }

    protected function client(string $token): BsreClient
    {
        $credential = $this->credentials->current();

        return (new BsreClient(null, $credential['base_url'] ?? null))->withToken($token);
    }

    protected function staleDraft(): ResponseInterface
    {
        return $this->panel('error', null, [
            'Draft sudah tidak berlaku (kedaluwarsa, sudah diproses, atau digantikan pencarian lain). '
            . 'Silakan ulangi pencarian.',
        ]);
    }

    /**
     * Satu bentuk respons AJAX: potongan HTML panel + hash CSRF baru (wajib
     * karena Config\Security::$regenerate = true).
     *
     * @param list<string> $errors
     */
    protected function panel(string $state, ?string $html = null, array $errors = []): ResponseInterface
    {
        return $this->response->setJSON([
            'state'  => $state,
            'html'   => $html,
            'errors' => $errors,
            'csrf'   => csrf_hash(),
        ]);
    }
}
