<?php

namespace Modules\TukangAdmin\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\TukangAdmin\Config\Module;
use Modules\TukangAdmin\Libraries\TteScraper;
use Modules\TukangAdmin\Libraries\TteScraperException;
use Modules\TukangAdmin\Models\ResetDraftModel;
use Modules\TukangAdmin\Models\TteCredentialModel;
use Modules\TukangKirim\Libraries\MaxChatDispatcher;
use Modules\TukangKirim\Libraries\MaxChatService;
use Modules\TukangKirim\Libraries\PasswordGenerator;
use Modules\TukangKirim\Libraries\PlaceholderParser;
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

    public function __construct()
    {
        $this->templates   = new TemplateModel();
        $this->credentials = new TteCredentialModel();
        $this->parser      = new PlaceholderParser();
        $this->maxchat     = new MaxChatService();
        $this->dispatcher  = new MaxChatDispatcher();
        $this->drafts      = new ResetDraftModel();
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

        // Pencarian berdasarkan email, NIK, atau nomor HP (parameter q di halaman
        // /users). Untuk email/NIK, nomor tujuan diambil dari hasil pencarian;
        // untuk nohp, nomor yang diketik dipakai sebagai fallback bila tabel TTE
        // tidak menampilkan nomornya.
        $by         = strtolower(trim((string) $this->request->getPost('by'))) ?: 'email';
        $value      = trim((string) $this->request->getPost('value'));
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

        $labels = ['email' => 'Email', 'nik' => 'NIK', 'nohp' => 'Nomor HP'];

        if (! isset($labels[$by])) {
            $errors[] = 'Parameter pencarian tidak dikenal.';
        } elseif ($value === '') {
            $errors[] = $labels[$by] . ' wajib diisi.';
        } elseif ($by === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Masukkan alamat email yang valid.';
        } elseif ($by === 'nik' && preg_match('/^\d{6,20}$/', $value) !== 1) {
            $errors[] = 'NIK harus berupa angka (6–20 digit).';
        } elseif ($by === 'nohp' && preg_match('/^[\d\s+\-().]{7,20}$/', $value) !== 1) {
            $errors[] = 'Nomor HP tidak valid.';
        }

        // Template ini harus memuat [password] — kalau tidak, kata sandi yang
        // di-reset tidak akan pernah ikut terkirim ke penerima.
        if (! $this->parser->hasPassword($template['body'])) {
            $errors[] = 'Template harus memuat placeholder [password] agar kata sandi baru ikut terkirim.';
        }

        if ($errors !== []) {
            return $this->panel('error', null, $errors);
        }

        try {
            $scraper = new TteScraper(null, $credential['base_url'] ?? null);
            $scraper->login($credential['username'], $this->credentials->plainPassword($credential));
            $user = $scraper->findPenandatangan($value);
        } catch (TteScraperException $e) {
            return $this->panel('error', null, [$e->getMessage()]);
        } catch (Throwable $e) {
            return $this->panel('error', null, ['Kesalahan tak terduga saat menghubungi TTE: ' . $e->getMessage()]);
        }

        // Nomor tujuan: dari data pengguna; saat cari via nohp, nomor yang diketik
        // jadi fallback bila tabel TTE tidak menampilkan nomornya.
        $phone = $user['phone'] ?: ($by === 'nohp' ? $value : null);

        if (empty($phone)) {
            return $this->panel('error', null, [
                'Nomor HP tidak ditemukan pada data pengguna di TTE, sehingga kata sandi tidak bisa dikirim.',
            ]);
        }

        $normalized = $this->maxchat->normalizeNumber($phone);
        $password   = (new PasswordGenerator())->generate();

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
            'search_by'        => $by,
            'search_value'     => $value,
            // recipient_input pada riwayat memakai nomor mentah yang dipakai.
            'phone_input'      => $phone,
            'phone_normalized' => $normalized,
            'email'            => $user['email'],
            'name'             => $user['name'],
            'role'             => $user['role'],
            'change_url'       => $user['change_url'],
            'password'         => $password,
            'template_id'      => (int) $template['id'],
            'template_name'    => $template['name'],
            'text'             => $text,
        ];

        // Draft disimpan di DB dan id-nya ditanam di panel; dispatch nanti hanya
        // memproses draft dengan id ini, jadi tab lain tak bisa menimpanya.
        $draftId = $this->drafts->create(self::DRAFT_KIND, $draft);

        return $this->panel('preview', view(Module::VIEWS . 'reset/_panel_preview', [
            'draft'       => $draft,
            'draftId'     => $draftId,
            'maxchat'     => $this->maxchat,
            'nextAccount' => $this->dispatcher->peek(),
        ]));
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

        // Kata sandi berhasil diubah — kirim ke nomor HP lewat pipeline Tukang Kirim.
        $outcome = $this->dispatcher->send($draft['phone_normalized'], $draft['text']);
        $result  = $outcome['final'];

        $this->recordLogs($draft, $outcome);

        $failed = [];

        foreach ($outcome['attempts'] as $attempt) {
            if (! $attempt['result']['ok']) {
                $failed[] = [
                    'account' => $attempt['account']['name'],
                    'error'   => $attempt['result']['error'] ?? 'Penyebab tidak diketahui.',
                ];
            }
        }

        return $this->panel('result', view(Module::VIEWS . 'reset/_panel_result', [
            'result' => [
                'email'      => $draft['email'],
                'name'       => $draft['name'],
                'role'       => $draft['role'],
                'password'   => $draft['password'],
                'recipient'  => $draft['phone_normalized'],
                'text'       => $draft['text'],
                'status'     => $result['status'],
                'ok'         => $result['ok'],
                'http_code'  => $result['http_code'],
                'body'       => $result['body'],
                'error'      => $result['error'],
                'account'    => $outcome['ok'] ? ($outcome['account']['name'] ?? null) : null,
                'failed'     => $failed,
            ],
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
