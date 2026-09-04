<?php

namespace Modules\TukangKirim\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\TukangKirim\Config\Module;
use Modules\TukangKirim\Libraries\MaxChatDispatcher;
use Modules\TukangKirim\Libraries\MaxChatService;
use Modules\TukangKirim\Libraries\PasswordGenerator;
use Modules\TukangKirim\Libraries\PlaceholderParser;
use Modules\TukangKirim\Libraries\SendQueueService;
use Modules\TukangKirim\Models\MaxchatAccountModel;
use Modules\TukangKirim\Models\MessageLogModel;
use Modules\TukangKirim\Models\TemplateModel;

/**
 * Alur kirim pesan: semuanya di satu halaman. Kolom kiri menyusun pesan, kolom
 * kanan berganti isi mengikuti tahapan (cara kerja -> verifikasi -> hasil).
 *
 * Preview dan pengiriman tetap dua permintaan terpisah ke server, hanya saja
 * dipanggil lewat fetch() sehingga halaman tidak pernah berpindah. Kunci alurnya
 * tidak berubah: yang benar-benar dikirim ke MaxChat adalah teks yang disimpan di
 * session saat preview, bukan yang dikirim ulang oleh form. Jadi apa yang sudah
 * diverifikasi operator persis sama dengan yang sampai ke penerima.
 */
class Send extends BaseController
{
    protected const DRAFT_KEY = 'send_draft';
    protected const INPUT_KEY = 'send_input';

    protected TemplateModel $templates;
    protected PlaceholderParser $parser;
    protected MaxChatService $maxchat;
    protected MaxChatDispatcher $dispatcher;
    protected SendQueueService $queue;

    public function __construct()
    {
        $this->templates = new TemplateModel();
        $this->parser    = new PlaceholderParser();
        // Service dipakai langsung hanya untuk hal yang tidak bergantung akun
        // (normalisasi nomor, mode uji coba); pengiriman lewat dispatcher/antrean.
        $this->maxchat    = new MaxChatService();
        $this->dispatcher = new MaxChatDispatcher();
        $this->queue      = new SendQueueService();
    }

    /**
     * Langkah 1 - form penyusun pesan.
     */
    public function index(): string
    {
        $templates = $this->templates->activeForSend();

        return view(Module::VIEWS . 'send/form', [
            'title'          => 'Kirim Pesan',
            'templates'      => $templates,
            'templateMap'    => $this->buildTemplateMap($templates),
            'old'            => session(self::INPUT_KEY) ?? [],
            'maxchat'        => $this->maxchat,
            'activeAccounts' => (new MaxchatAccountModel())->activeCount(),
        ]);
    }

    /**
     * Langkah 2 - susun pesan final, kembalikan sebagai panel verifikasi.
     */
    public function preview(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url());
        }

        $templateId = (int) $this->request->getPost('template_id');
        $recipient  = trim((string) $this->request->getPost('to'));
        $values     = (array) ($this->request->getPost('placeholders') ?? []);

        // Simpan isian mentah supaya tombol "Ubah" mengembalikan form apa adanya.
        session()->set(self::INPUT_KEY, [
            'template_id'  => $templateId,
            'to'           => $recipient,
            'placeholders' => $values,
        ]);

        $template = $templateId > 0 ? $this->templates->find($templateId) : null;

        if ($template === null) {
            return $this->panel('error', null, ['Template pesan belum dipilih atau sudah tidak tersedia.']);
        }

        $errors     = [];
        $normalized = $this->maxchat->normalizeNumber($recipient);

        if ($recipient === '') {
            $errors[] = 'Nomor tujuan wajib diisi.';
        } elseif (strlen($normalized) < 10 || strlen($normalized) > 15) {
            $errors[] = 'Nomor tujuan tidak valid. Gunakan format 08xxxxxxxxxx atau 628xxxxxxxxxx.';
        }

        $filled = [];

        foreach ($this->parser->manual($template['body']) as $name) {
            $value = trim((string) ($values[$name] ?? ''));

            if ($value === '') {
                $errors[] = 'Isian "' . $this->parser->label($name) . '" wajib diisi.';
            }

            $filled[$name] = $value;
        }

        if ($errors !== []) {
            return $this->panel('error', null, $errors);
        }

        // Password dibangkitkan di sini, tepat sebelum preview, dan hanya hidup
        // di session sampai pesan terkirim.
        $password = null;

        if ($this->parser->hasPassword($template['body'])) {
            $password           = (new PasswordGenerator())->generate();
            $filled['password'] = $password;
        }

        $text = $this->parser->render($template['body'], $filled);

        $draft = [
            'template_id'          => (int) $template['id'],
            'template_name'        => $template['name'],
            'recipient_input'      => $recipient,
            'recipient_normalized' => $normalized,
            'text'                 => $text,
            'password'             => $password,
        ];

        session()->set(self::DRAFT_KEY, $draft);

        return $this->panel('preview', view(Module::VIEWS . 'send/_panel_preview', [
            'draft'       => $draft,
            'maxchat'     => $this->maxchat,
            'nextAccount' => $this->dispatcher->peek(),
        ]));
    }

    /**
     * Langkah 3 - kirim persis teks yang sudah diverifikasi.
     *
     * Akun dipilih bergiliran oleh dispatcher, dan bila satu akun gagal akun
     * berikutnya langsung dicoba di sini juga. Tiap percobaan dicatat sebagai
     * satu baris riwayat supaya terlihat akun mana yang bermasalah.
     */
    public function dispatch(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url());
        }

        $draft = session(self::DRAFT_KEY);

        // Draft hanya ada sekali: sesudah terkirim ia dibuang, jadi klik ganda
        // atau session kedaluwarsa tidak bisa mengirim pesan yang sama dua kali.
        if (! is_array($draft) || empty($draft['text'])) {
            return $this->panel('error', null, ['Draft pesan sudah tidak ada. Silakan susun ulang pesannya.']);
        }

        $password = $draft['password'] ?? null;
        $mask     = static fn (?string $text): ?string => $password !== null && $text !== null
            ? str_replace($password, str_repeat('*', 8), $text)
            : $text;
        $masked = (string) $mask($draft['text']);

        // Template terakhir dipertahankan sebagai default untuk kiriman berikutnya;
        // nomor & isian placeholder dikosongkan karena berbeda tiap penerima.
        session()->set(self::INPUT_KEY, [
            'template_id'  => $draft['template_id'],
            'to'           => '',
            'placeholders' => [],
        ]);
        session()->remove(self::DRAFT_KEY);

        // Mode uji coba tetap sinkron (tidak benar-benar mengirim); pengiriman
        // nyata dimasukkan ANTREAN dan dikirim worker dengan jeda (anti-banned).
        if ($this->maxchat->isDryRun()) {
            $outcome = $this->dispatcher->send($draft['recipient_normalized'], $draft['text']);
            $result  = $outcome['final'];
            $logs    = new MessageLogModel();

            $row = static fn (array $res, ?array $account, int $no): array => [
                'template_id'          => $draft['template_id'],
                'maxchat_account_id'   => $account !== null ? (int) $account['id'] : null,
                'template_name'        => $draft['template_name'],
                'account_name'         => $account['name'] ?? null,
                'recipient_input'      => $draft['recipient_input'],
                'recipient_normalized' => $draft['recipient_normalized'],
                'message_masked'       => $masked,
                'has_password'         => $password !== null ? 1 : 0,
                'status'               => $res['status'],
                'http_code'            => $res['http_code'],
                'api_response'         => $mask($res['body']),
                'error_message'        => $res['error'],
                'attempt_no'           => $no,
            ];

            $logs->insert($row($result, $outcome['account'], 1));

            return $this->panel('result', view(Module::VIEWS . 'send/_panel_result', [
                'result' => [
                    'status'    => $result['status'],
                    'ok'        => $result['ok'],
                    'http_code' => $result['http_code'],
                    'body'      => $result['body'],
                    'error'     => $result['error'],
                    'recipient' => $draft['recipient_normalized'],
                    'text'      => $draft['text'],
                    'password'  => $password,
                    'account'   => $outcome['ok'] ? ($outcome['account']['name'] ?? null) : null,
                    'failed'    => [],
                ],
            ]));
        }

        $this->queue->enqueue($draft['recipient_normalized'], $draft['text'], $password, [
            'template_id'          => $draft['template_id'],
            'template_name'        => $draft['template_name'],
            'recipient_input'      => $draft['recipient_input'],
            'recipient_normalized' => $draft['recipient_normalized'],
            'message_masked'       => $masked,
            'has_password'         => $password !== null ? 1 : 0,
        ]);

        return $this->panel('result', view(Module::VIEWS . 'send/_panel_queued', [
            'result' => [
                'recipient' => $draft['recipient_normalized'],
                'text'      => $draft['text'],
                'password'  => $password,
                'pending'   => $this->queue->pending(),
            ],
        ]));
    }

    /**
     * Satu bentuk respons untuk kedua langkah: potongan HTML panel kanan yang
     * sudah dirender server, plus hash CSRF baru.
     *
     * Hash itu wajib ikut karena Config\Security::$regenerate bernilai true —
     * token berganti tiap POST, jadi tanpa ini tombol Kirim sesudah preview pasti
     * ditolak filter CSRF.
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

    /**
     * Data template untuk membangun kolom isian placeholder di sisi klien.
     *
     * Isi pesannya sengaja tidak ikut: sejak pratinjau langsung dihapus, browser
     * tidak perlu lagi tahu bunyi templatenya.
     *
     * @param list<array<string, mixed>> $templates
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildTemplateMap(array $templates): array
    {
        $map = [];

        foreach ($templates as $template) {
            $map[(int) $template['id']] = [
                'placeholders' => array_map(fn (string $n): array => [
                    'name'  => $n,
                    'label' => $this->parser->label($n),
                ], $this->parser->manual($template['body'])),
                'has_password' => $this->parser->hasPassword($template['body']),
            ];
        }

        return $map;
    }
}
