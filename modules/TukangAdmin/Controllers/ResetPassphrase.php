<?php

namespace Modules\TukangAdmin\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\TukangAdmin\Config\Bsre as BsreConfig;
use Modules\TukangAdmin\Config\Module;
use Modules\TukangAdmin\Libraries\BsreClient;
use Modules\TukangAdmin\Libraries\BsreClientException;
use Modules\TukangAdmin\Models\BsreCredentialModel;
use Throwable;

/**
 * Reset passphrase BSrE dalam satu halaman, dua langkah POST lewat fetch().
 *
 * Langkah 1 (search): pakai token BSrE tersimpan di session, cari pengguna
 * berdasarkan email, buka detailnya, dan tampilkan sertifikat + status verifikasi
 * nomor HP untuk dikonfirmasi. Belum ada yang diubah pada tahap ini.
 *
 * Langkah 2 (dispatch): jalankan aksi "Reset passphrase" pada sertifikat terpilih.
 * Dua kemungkinan, persis alur manual di portal:
 *   - HP belum terverifikasi -> picu verifikasi nomor (BSrE kirim tautan ke WA
 *     pengguna). Operator menunggu pengguna verifikasi dulu (Outcome 1).
 *   - HP sudah terverifikasi  -> BSrE langsung mengirim tautan reset passphrase
 *     ke email dinas pengguna (Outcome 2).
 *
 * Login + OTP tidak dilakukan di sini; token diperoleh sekali lewat menu Akun
 * BSrE dan dipakai ulang selama masih berlaku.
 */
class ResetPassphrase extends BaseController
{
    protected const DRAFT_KEY = 'tukang_admin_passphrase_draft';

    protected BsreCredentialModel $credentials;

    public function __construct()
    {
        $this->credentials = new BsreCredentialModel();
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
     * Langkah 1 — cari pengguna by email, susun draft (AJAX).
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

        // Parameter pencarian: email (default) atau nik. Meniru "Cari data
        // berdasarkan" di portal, untuk mitigasi bila operator lupa email.
        $searchParams = config(BsreConfig::class)->searchParams;
        $by           = strtolower(trim((string) $this->request->getPost('by'))) ?: 'email';
        $value        = trim((string) $this->request->getPost('value'));

        if (! array_key_exists($by, $searchParams)) {
            return $this->panel('error', null, ['Parameter pencarian tidak dikenal.']);
        }

        if ($value === '') {
            return $this->panel('error', null, ['Nilai pencarian wajib diisi.']);
        }

        if ($by === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->panel('error', null, ['Masukkan alamat email yang valid.']);
        }

        if ($by === 'nik' && preg_match('/^\d{6,20}$/', $value) !== 1) {
            return $this->panel('error', null, ['NIK harus berupa angka (6–20 digit).']);
        }

        $credential = $this->credentials->current();

        try {
            $client = (new BsreClient(null, $credential['base_url'] ?? null))->withToken($token);
            $user   = $client->findUser($value, $searchParams[$by]);
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
            'phone'         => $user['phone'],
            'phoneVerified' => $user['phoneVerified'],
            'serial'        => $target['serial'],
            'jenis'         => $target['jenis'],
        ];

        session()->set(self::DRAFT_KEY, $draft);

        return $this->panel('preview', view(Module::VIEWS . 'passphrase/_panel_preview', [
            'draft'        => $draft,
            'certificates' => $user['certificates'],
        ]));
    }

    /**
     * Langkah 2 — jalankan aksi reset passphrase / verifikasi HP (AJAX).
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

        $draft = session(self::DRAFT_KEY);

        // Draft sekali pakai: begitu diproses ia dibuang, jadi klik ganda atau
        // session kedaluwarsa tidak bisa memicu aksi kedua.
        if (! is_array($draft) || empty($draft['uid']) || empty($draft['serial'])) {
            return $this->panel('error', null, ['Draft sudah tidak ada. Silakan ulangi pencarian email.']);
        }

        $credential = $this->credentials->current();

        try {
            $client = (new BsreClient(null, $credential['base_url'] ?? null))->withToken($token);

            if (! $draft['phoneVerified']) {
                // Outcome 1: HP belum terverifikasi -> kirim tautan verifikasi WA.
                if (empty($draft['phone'])) {
                    session()->remove(self::DRAFT_KEY);

                    return $this->panel('error', null, [
                        'Nomor HP pengguna tidak terbaca, sehingga verifikasi tidak bisa dipicu.',
                    ]);
                }

                $message = $client->verifyPhone($draft['uid'], $draft['phone']);
                $outcome = 'verify';
            } else {
                // Outcome 2: HP sudah terverifikasi -> kirim tautan reset passphrase.
                $message = $client->resetPassphrase($draft['uid'], $draft['serial']);
                $outcome = 'reset';
            }
        } catch (BsreClientException $e) {
            session()->remove(self::DRAFT_KEY);

            return $this->panel('error', null, ['Gagal: ' . $e->getMessage()]);
        } catch (Throwable $e) {
            session()->remove(self::DRAFT_KEY);

            return $this->panel('error', null, ['Kesalahan tak terduga: ' . $e->getMessage()]);
        }

        session()->remove(self::DRAFT_KEY);

        return $this->panel('result', view(Module::VIEWS . 'passphrase/_panel_result', [
            'result' => [
                'outcome' => $outcome,
                'email'   => $draft['email'],
                'name'    => $draft['name'],
                'phone'   => $draft['phone'],
                'serial'  => $draft['serial'],
                'message' => $message,
            ],
        ]));
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
