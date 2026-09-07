<?php

namespace Modules\TukangAdmin\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\TukangAdmin\Config\Module;
use Modules\TukangAdmin\Libraries\PhoneNumbers;
use Modules\TukangAdmin\Libraries\SearchIdentifier;
use Modules\TukangAdmin\Libraries\TteScraper;
use Modules\TukangAdmin\Libraries\TteScraperException;
use Modules\TukangAdmin\Libraries\TteUpdateReport;
use Modules\TukangAdmin\Libraries\TteUserNotFoundException;
use Modules\TukangAdmin\Models\ResetDraftModel;
use Modules\TukangAdmin\Models\TteCredentialModel;
use Modules\TukangKirim\Libraries\MaxChatDispatcher;
use Modules\TukangKirim\Libraries\MaxChatService;
use Modules\TukangKirim\Libraries\PasswordGenerator;
use Modules\TukangKirim\Libraries\PlaceholderParser;
use Modules\TukangKirim\Libraries\SendQueueService;
use Modules\TukangKirim\Models\MaxchatAccountModel;
use Modules\TukangKirim\Models\MessageLogModel;
use Modules\TukangKirim\Models\TemplateModel;
use Throwable;

/**
 * Reset kata sandi TTE dalam satu halaman, dua langkah POST lewat fetch().
 *
 * Langkah 1 (search): scraper login ke TTE, mencari akun penandatangan lewat
 * email/NIK/nomor HP, mengambil email + nomornya, dan membangkitkan kata sandi
 * acak. Semuanya disimpan sebagai draft ber-id acak di DB dan ditampilkan untuk
 * dikonfirmasi — kata sandi TTE BELUM diubah pada tahap ini.
 *
 * Langkah 2 (dispatch): dengan id draft yang ditampilkan tab tersebut, baru di
 * sini kata sandi TTE benar-benar diganti, lalu kata sandi + email dikirim ke
 * nomor HP memakai pipeline kirim milik Tukang Kirim (template +
 * MaxChatDispatcher + MessageLogModel), lengkap dengan penyamaran kata sandi di
 * riwayat persis seperti Send::dispatch.
 *
 * Draft disimpan di DB (bukan session) dan bersifat sekali-pakai, jadi dua tab
 * pada browser yang sama tidak saling menimpa dan bisa berjalan berdampingan.
 */
class ResetPassword extends BaseController
{
    protected const DRAFT_KIND = 'tte';

    protected TemplateModel $templates;
    protected TteCredentialModel $credentials;
    protected PlaceholderParser $parser;
    protected MaxChatService $maxchat;
    protected MaxChatDispatcher $dispatcher;
    protected ResetDraftModel $drafts;
    protected SendQueueService $queue;

    public function __construct()
    {
        $this->templates   = new TemplateModel();
        $this->credentials = new TteCredentialModel();
        $this->parser      = new PlaceholderParser();
        $this->maxchat     = new MaxChatService();
        $this->dispatcher  = new MaxChatDispatcher();
        $this->drafts      = new ResetDraftModel();
        $this->queue       = new SendQueueService();
    }

    public function index(): string
    {
        return view(Module::VIEWS . 'reset/form', [
            'title'          => 'Reset Password',
            'templates'      => $this->templates->activeForSend(),
            'hasCredential'  => $this->credentials->current() !== null,
            'activeAccounts' => (new MaxchatAccountModel())->activeCount(),
            'maxchat'        => $this->maxchat,
        ]);
    }

    /**
     * Langkah 1 — cari akun, bangkitkan sandi, susun draft (AJAX).
     */
    public function search(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url());
        }

        // Nomor HP adalah input utama: itulah yang benar-benar dipegang operator
        // saat ada permintaan reset, dan itu pula tujuan pengirimannya. Kolom
        // cadangan (NIK/email) hanya dipakai bila pencarian nomor tidak menemukan
        // siapa pun — nomornya sendiri TETAP wajib diisi, karena justru
        // ketidakcocokan nomor itulah sebab pencarian pertama gagal.
        $phone      = trim((string) $this->request->getPost('phone'));
        $fallback   = trim((string) $this->request->getPost('fallback'));
        $templateId = (int) $this->request->getPost('template_id');

        $credential = $this->credentials->current();

        if ($credential === null) {
            return $this->panel('error', null, ['Belum ada kredensial TTE. Isi dulu di menu Akun TTE.']);
        }

        $template = $templateId > 0 ? $this->templates->find($templateId) : null;

        if ($template === null) {
            return $this->panel('error', null, ['Template pesan belum dipilih atau sudah tidak tersedia.']);
        }

        $errors = [];

        if ($phone === '') {
            $errors[] = 'Nomor HP wajib diisi.';
        }

        // Template ini harus memuat [password] — kalau tidak, kata sandi yang
        // di-reset tidak akan pernah ikut terkirim ke penerima.
        if (! $this->parser->hasPassword($template['body'])) {
            $errors[] = 'Template harus memuat placeholder [password] agar kata sandi baru ikut terkirim.';
        }

        if ($errors !== []) {
            return $this->panel('error', null, $errors);
        }

        // Validasi ketat dilakukan saat INPUT, bukan setelah pulang-pergi ke TTE:
        // operator langsung tahu nomornya cacat tanpa menunggu scraping.
        $normalized = $this->maxchat->normalizedMobile($phone);

        if ($normalized === null) {
            return $this->panel('error', null, [
                'Nomor HP tidak valid: "' . $phone . '". Gunakan format 08xx / 62xx dengan panjang wajar.',
            ]);
        }

        // Kata kunci pencarian: nomor lebih dulu, kolom cadangan hanya bila diisi.
        if ($fallback === '') {
            $by       = 'nohp';
            $queries  = $this->phoneVariants($normalized);
        } else {
            $detected = $this->detectFallback($fallback);

            if (isset($detected['error'])) {
                return $this->panel('error', null, [$detected['error']]);
            }

            $by      = $detected['by'];
            $queries = [$detected['value']];
        }

        try {
            $scraper = new TteScraper(null, $credential['base_url'] ?? null);
            $scraper->login($credential['username'], $this->credentials->plainPassword($credential));

            // Satu sesi, beberapa bentuk nomor: TTE menyimpan 08…, jadi operator
            // yang mengetik +62… tidak boleh dapat "tidak ketemu" palsu.
            $user      = null;
            $notFound  = null;

            foreach ($queries as $query) {
                try {
                    $user  = $scraper->findPenandatangan($query);
                    $value = $query;
                    break;
                } catch (TteUserNotFoundException $e) {
                    $notFound = $e;
                }
            }

            if ($user === null) {
                throw $notFound ?? new TteUserNotFoundException('Pencarian tidak menghasilkan apa pun.');
            }
        } catch (TteUserNotFoundException $e) {
            // Hanya jalur nomor yang menawarkan kolom cadangan; bila cadangan pun
            // sudah dipakai, ini galat biasa.
            if ($by === 'nohp') {
                return $this->panel('notfound', view(Module::VIEWS . 'reset/_panel_notfound', [
                    'phone'  => $phone,
                    'reason' => $e->getMessage(),
                ]));
            }

            return $this->panel('error', null, [$e->getMessage()]);
        } catch (TteScraperException $e) {
            // Markup berubah, hasil ganda, atau TTE tidak bisa dihubungi — jangan
            // pernah menyuruh operator "coba NIK" untuk sebab seperti ini.
            return $this->panel('error', null, [$e->getMessage()]);
        } catch (Throwable $e) {
            return $this->panel('error', null, ['Kesalahan tak terduga saat menghubungi TTE: ' . $e->getMessage()]);
        }

        $password = (new PasswordGenerator())->generate();

        // Nilai otomatis yang bisa mengisi placeholder template. Placeholder
        // manual di luar daftar ini dianggap tidak didukung modul ini.
        $auto = [
            'email'    => $user['email'],
            'nama'     => $user['name'],
            'nomor'    => $normalized,
            'no_hp'    => $normalized,
            'hp'       => $normalized,
            'telepon'  => $normalized,
            'password' => $password,
        ];

        foreach ($this->parser->manual($template['body']) as $name) {
            if (! array_key_exists($name, $auto)) {
                return $this->panel('error', null, [
                    'Placeholder [' . $name . '] pada template tidak didukung modul ini. '
                        . 'Gunakan hanya [email], [nama], [nomor], dan [password].',
                ]);
            }
        }

        $text = $this->parser->render($template['body'], $auto);

        $draft = [
            'search_by'    => $by,
            'search_value' => $value,
            // Tujuan pengiriman SELALU nomor yang diketik operator, tidak pernah
            // nomor dari TTE: nomor di TTE bisa saja usang — justru itu sebab
            // pencarian lewat nomor gagal dan operator memakai kolom cadangan.
            'phone_typed'      => $phone,
            'phone_input'      => $phone,
            'phone_normalized' => $normalized,
            // Nomor yang tercatat di TTE, untuk dibandingkan di panel.
            'phone_tte'        => $user['phone'],
            'email'            => $user['email'],
            'name'             => $user['name'],
            'role'             => $user['role'],
            'change_url'       => $user['change_url'],
            'edit_url'         => $user['edit_url'],
            'wa_updated'       => false,
            'wa_notes'         => [],
            'password'         => $password,
            'template_id'      => (int) $template['id'],
            'template_name'    => $template['name'],
            'text'             => $text,
        ];

        // Draft disimpan di DB dan id-nya ditanam di panel; dispatch nanti hanya
        // memproses draft dengan id ini, jadi tab lain tak bisa menimpanya.
        $draftId = $this->drafts->create(self::DRAFT_KIND, $draft);

        return $this->previewPanel($draft, $draftId);
    }

    /**
     * Langkah 2 — ubah kata sandi di TTE, lalu kirim ke nomor HP (AJAX).
     */
    public function dispatch(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url());
        }

        // Klaim draft berdasarkan id dari tab ini: atomik & sekali-pakai, jadi
        // klik ganda maupun tab lain tidak bisa memproses draft yang sama.
        $draft = $this->drafts->claim(self::DRAFT_KIND, (string) $this->request->getPost('draft_id'));

        if ($draft === null || empty($draft['change_url']) || empty($draft['password'])) {
            return $this->panel('error', null, [
                'Draft sudah tidak berlaku (kedaluwarsa, sudah diproses, atau digantikan pencarian lain). Silakan ulangi pencarian.',
            ]);
        }

        $credential = $this->credentials->current();

        if ($credential === null) {
            return $this->panel('error', null, ['Kredensial TTE hilang. Isi ulang di menu Akun TTE.']);
        }

        // Ubah kata sandi di TTE lebih dulu. Bila gagal, tidak ada yang dikirim.
        try {
            $scraper = new TteScraper(null, $credential['base_url'] ?? null);
            $scraper->login($credential['username'], $this->credentials->plainPassword($credential));
            $scraper->changePassword(['change_url' => $draft['change_url']], $draft['password']);
        } catch (TteScraperException $e) {
            return $this->panel('error', null, ['Reset gagal: ' . $e->getMessage()]);
        } catch (Throwable $e) {
            return $this->panel('error', null, ['Kesalahan tak terduga saat mengubah kata sandi: ' . $e->getMessage()]);
        }

        // Kata sandi TTE sudah berubah. Mode uji coba tetap sinkron (tidak benar-
        // benar mengirim) supaya operator langsung lihat sandinya untuk disampaikan
        // manual; pengiriman nyata dimasukkan ANTREAN dan dikirim worker dengan
        // jeda antar-kirim (anti-banned).
        if ($this->maxchat->isDryRun()) {
            $outcome = $this->dispatcher->send($draft['phone_normalized'], $draft['text']);
            $result  = $outcome['final'];

            $this->recordLogs($draft, $outcome);

            return $this->panel('result', view(Module::VIEWS . 'reset/_panel_result', [
                'result' => [
                    'email'     => $draft['email'],
                    'name'      => $draft['name'],
                    'role'      => $draft['role'],
                    'password'  => $draft['password'],
                    'recipient' => $draft['phone_normalized'],
                    'text'      => $draft['text'],
                    'status'    => $result['status'],
                    'ok'        => $result['ok'],
                    'http_code' => $result['http_code'],
                    'body'      => $result['body'],
                    'error'     => $result['error'],
                    'account'   => $outcome['ok'] ? ($outcome['account']['name'] ?? null) : null,
                    'failed'    => MaxChatDispatcher::failedAttempts($outcome),
                ],
            ]));
        }

        $masked = str_replace($draft['password'], str_repeat('*', 8), $draft['text']);

        $this->queue->enqueue($draft['phone_normalized'], $draft['text'], $draft['password'], [
            'template_id'          => $draft['template_id'],
            'template_name'        => $draft['template_name'],
            'recipient_input'      => $draft['phone_input'],
            'recipient_normalized' => $draft['phone_normalized'],
            'message_masked'       => $masked,
            'has_password'         => 1,
        ]);

        return $this->panel('result', view(Module::VIEWS . 'reset/_panel_queued', [
            'result' => [
                'email'     => $draft['email'],
                'name'      => $draft['name'],
                'role'      => $draft['role'],
                'password'  => $draft['password'],
                'recipient' => $draft['phone_normalized'],
                'text'      => $draft['text'],
                'pending'   => $this->queue->pending(),
            ],
        ]));
    }

    /**
     * Bentuk-bentuk nomor yang dicoba pada kotak pencarian TTE.
     *
     * @return list<string>
     *
     * @see PhoneNumbers::variants() aturannya, dipakai bersama Reset Passphrase.
     */
    protected function phoneVariants(string $normalized): array
    {
        return PhoneNumbers::variants($normalized);
    }

    /**
     * Tebak jenis isian kolom cadangan: NIK (angka saja) atau email (ada @).
     *
     * @return array{by: string, value: string}|array{error: string}
     *
     * @see SearchIdentifier::detect()
     */
    protected function detectFallback(string $raw): array
    {
        return SearchIdentifier::detect($raw);
    }

    /**
     * Langkah antara opsional — perbarui nomor WhatsApp di TTE (AJAX).
     *
     * Draft dibaca TANPA diklaim: bila langkah ini gagal, preview yang sudah sah
     * harus tetap utuh supaya operator bisa menekan "Lewati" dan melanjutkan.
     * Kehilangan draft gara-gara langkah opsional adalah yang justru dihindari.
     */
    public function updateWhatsapp(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url());
        }

        $draftId = (string) $this->request->getPost('draft_id');
        $draft   = $this->drafts->peek(self::DRAFT_KIND, $draftId);

        if ($draft === null) {
            return $this->panel('error', null, [
                'Draft sudah tidak berlaku (kedaluwarsa atau sudah diproses). Silakan ulangi pencarian.',
            ]);
        }

        if (! empty($draft['wa_updated'])) {
            return $this->previewPanel($draft, $draftId); // Idempoten.
        }

        if (empty($draft['edit_url'])) {
            return $this->panel('error', null, [
                'Tombol "ubah" tidak terbaca pada baris pengguna di TTE, jadi nomor tidak bisa diperbarui dari sini.',
            ]);
        }

        $credential = $this->credentials->current();

        if ($credential === null) {
            return $this->panel('error', null, ['Kredensial TTE hilang. Isi ulang di menu Akun TTE.']);
        }

        try {
            $scraper = new TteScraper(null, $credential['base_url'] ?? null);
            $scraper->login($credential['username'], $this->credentials->plainPassword($credential));
            $result = $scraper->updateWhatsapp(['edit_url' => $draft['edit_url']], $draft['phone_normalized']);
        } catch (TteScraperException $e) {
            return $this->panel('error', null, ['Gagal memperbarui nomor di TTE: ' . $e->getMessage()]);
        } catch (Throwable $e) {
            return $this->panel('error', null, ['Kesalahan tak terduga saat memperbarui nomor: ' . $e->getMessage()]);
        }

        $notes = TteUpdateReport::notes($result);

        $draft['wa_updated'] = true;
        $draft['phone_tte']  = $result['after'];
        $draft['wa_notes']   = $notes;

        if (! $this->drafts->updatePayload(self::DRAFT_KIND, $draftId, $draft)) {
            return $this->panel('error', null, [
                'Nomor di TTE sudah diperbarui, tetapi draft tidak bisa disimpan (mungkin sudah diproses tab lain). '
                . 'Ulangi pencarian sebelum mereset kata sandi.',
            ]);
        }

        return $this->previewPanel($draft, $draftId);
    }

    /**
     * Apakah nomor di TTE sama dengan tujuan kirim?
     *
     * Dibandingkan sebagai nomor, bukan sebagai teks: TTE bisa menyimpan 08…
     * sementara tujuan kirim selalu 62…, dan keduanya bisa saja nomor yang sama.
     * Menganggapnya berbeda berarti menawarkan penulisan ke TTE yang sia-sia.
     *
     * @param array<string, mixed> $draft
     */
    protected function phoneMatchesTte(array $draft): bool
    {
        return PhoneNumbers::matches($draft['phone_tte'] ?? null, (string) $draft['phone_normalized']);
    }

    /**
     * @param array<string, mixed> $draft
     */
    protected function previewPanel(array $draft, string $draftId): ResponseInterface
    {
        $matches = $this->phoneMatchesTte($draft);

        return $this->panel('preview', view(Module::VIEWS . 'reset/_panel_preview', [
            'draft'        => $draft,
            'draftId'      => $draftId,
            'maxchat'      => $this->maxchat,
            'nextAccount'  => $this->dispatcher->peek(),
            'phoneMatches' => $matches,
            // Selama nomor berbeda dan masih bisa diperbarui, tombol Reset dikunci:
            // Perbarui/Lewati harus jadi keputusan sadar sebelum langkah yang
            // tidak bisa dibatalkan.
            'needsWaDecision' => empty($draft['wa_updated']) && ! $matches && ! empty($draft['edit_url']),
        ]));
    }

    /**
     * Catat tiap percobaan kirim ke riwayat Tukang Kirim, dengan kata sandi
     * disamarkan — pola yang sama persis dengan Send::dispatch.
     *
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $outcome
     */
    protected function recordLogs(array $draft, array $outcome): void
    {
        $password = $draft['password'];
        $mask     = static fn (?string $text): ?string => $text !== null
            ? str_replace($password, str_repeat('*', 8), $text)
            : $text;

        $masked = (string) $mask($draft['text']);
        $logs   = new MessageLogModel();

        $row = static fn (array $attemptResult, ?array $account, int $no): array => [
            'template_id'          => $draft['template_id'],
            'maxchat_account_id'   => $account !== null ? (int) $account['id'] : null,
            'template_name'        => $draft['template_name'],
            'account_name'         => $account['name'] ?? null,
            'recipient_input'      => $draft['phone_input'],
            'recipient_normalized' => $draft['phone_normalized'],
            'message_masked'       => $masked,
            'has_password'         => 1,
            'status'               => $attemptResult['status'],
            'http_code'            => $attemptResult['http_code'],
            'api_response'         => $mask($attemptResult['body']),
            'error_message'        => $attemptResult['error'],
            'attempt_no'           => $no,
        ];

        if ($outcome['attempts'] === []) {
            $logs->insert($row($outcome['final'], null, 1));

            return;
        }

        foreach ($outcome['attempts'] as $index => $attempt) {
            $logs->insert($row($attempt['result'], $attempt['account'], $index + 1));
        }
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
