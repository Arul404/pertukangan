<?php

namespace Modules\TukangKirim\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Modules\TukangKirim\Config\Module;
use Modules\TukangKirim\Libraries\MaxChatDispatcher;
use Modules\TukangKirim\Models\MaxchatAccountModel;
use Modules\TukangKirim\Models\MessageLogModel;

/**
 * CRUD akun MaxChat. Tiap akun punya pasangan base URL + token sendiri, dan
 * pengiriman dirotasi di antara akun-akun yang aktif.
 */
class MaxchatAccounts extends BaseController
{
    protected MaxchatAccountModel $accounts;

    public function __construct()
    {
        $this->accounts = new MaxchatAccountModel();
    }

    public function index(): string
    {
        return view(Module::VIEWS . 'maxchat_accounts/index', [
            'title'    => 'Akun MaxChat',
            'accounts' => $this->accounts->orderBy('is_active', 'DESC')->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function create(): string
    {
        return view(Module::VIEWS . 'maxchat_accounts/form', [
            'title'   => 'Akun Baru',
            'account' => null,
        ]);
    }

    public function store(): RedirectResponse
    {
        $data = $this->collect();

        if (! $this->accounts->insert($data)) {
            return redirect()->back()->withInput()->with('errors', $this->accounts->errors());
        }

        return redirect()->to(module_url('accounts'))->with('success', 'Akun "' . $data['name'] . '" berhasil dibuat.');
    }

    public function edit(int $id): string
    {
        $account = $this->accounts->find($id);

        if ($account === null) {
            throw PageNotFoundException::forPageNotFound('Akun tidak ditemukan.');
        }

        return view(Module::VIEWS . 'maxchat_accounts/form', [
            'title'   => 'Ubah Akun',
            'account' => $account,
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        if ($this->accounts->find($id) === null) {
            throw PageNotFoundException::forPageNotFound('Akun tidak ditemukan.');
        }

        // `id` ikut dikirim agar placeholder {id} pada aturan is_unique terisi;
        // field ini otomatis dibuang oleh $allowedFields sebelum query UPDATE.
        $data       = $this->collect();
        $data['id'] = $id;

        if (! $this->accounts->update($id, $data)) {
            return redirect()->back()->withInput()->with('errors', $this->accounts->errors());
        }

        return redirect()->to(module_url('accounts'))->with('success', 'Akun "' . $data['name'] . '" berhasil diperbarui.');
    }

    public function delete(int $id): RedirectResponse
    {
        $account = $this->accounts->find($id);

        if ($account === null) {
            throw PageNotFoundException::forPageNotFound('Akun tidak ditemukan.');
        }

        // Riwayat lama tetap terbaca karena nama akun disimpan sebagai snapshot,
        // jadi penghapusan tidak diblokir jumlah log — yang dijaga hanya agar
        // aplikasi tidak kehilangan seluruh kemampuan kirim.
        if ((int) $account['is_active'] === 1 && $this->accounts->activeCount() <= 1) {
            return redirect()->to(module_url('accounts'))
                ->with('error', 'Akun "' . $account['name'] . '" adalah satu-satunya akun aktif. Tambahkan atau aktifkan akun lain lebih dulu.');
        }

        $this->accounts->delete($id);

        return redirect()->to(module_url('accounts'))->with('success', 'Akun "' . $account['name'] . '" dihapus.');
    }

    /**
     * Tes koneksi: kirim pesan uji nyata lewat satu akun, melewati rotasi.
     */
    public function test(int $id): RedirectResponse
    {
        $account = $this->accounts->find($id);

        if ($account === null) {
            throw PageNotFoundException::forPageNotFound('Akun tidak ditemukan.');
        }

        $dispatcher = new MaxChatDispatcher();
        $input      = trim((string) $this->request->getPost('to'));
        $normalized = $dispatcher->service()->normalizeNumber($input);

        if (strlen($normalized) < 10 || strlen($normalized) > 15) {
            return redirect()->to(module_url('accounts'))
                ->with('error', 'Nomor tujuan tes tidak valid. Gunakan format 08xxxxxxxxxx atau 628xxxxxxxxxx.');
        }

        $text   = 'Tes koneksi Pertukangan — akun "' . $account['name'] . '" — ' . date('d/m/Y H:i:s') . '.';
        $result = $dispatcher->sendVia($account, $normalized, $text);

        // Tes memakai kuota pesan sungguhan, jadi ikut dicatat di riwayat.
        (new MessageLogModel())->insert([
            'template_id'          => null,
            'maxchat_account_id'   => (int) $account['id'],
            'template_name'        => 'Tes Koneksi',
            'account_name'         => $account['name'],
            'recipient_input'      => $input,
            'recipient_normalized' => $normalized,
            'message_masked'       => $text,
            'has_password'         => 0,
            'status'               => $result['status'],
            'http_code'            => $result['http_code'],
            'api_response'         => $result['body'],
            'error_message'        => $result['error'],
            'attempt_no'           => 1,
        ]);

        if ($result['ok']) {
            return redirect()->to(module_url('accounts'))->with(
                'success',
                'Tes akun "' . $account['name'] . '" berhasil (HTTP ' . $result['http_code'] . '). Pesan uji dikirim ke ' . $normalized . '.',
            );
        }

        return redirect()->to(module_url('accounts'))->with(
            'error',
            'Tes akun "' . $account['name'] . '" gagal: ' . ($result['error'] ?? 'penyebab tidak diketahui.'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function collect(): array
    {
        $baseUrl = rtrim(trim((string) $this->request->getPost('base_url')), '/');

        // Alamat yang dibagikan MaxChat sudah memuat segmen `/messages`; kalau
        // ikut tersalin, buang di sini supaya endpointnya tidak jadi ganda.
        if (str_ends_with($baseUrl, '/messages')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/messages'));
        }

        return [
            'name'        => trim((string) $this->request->getPost('name')),
            'base_url'    => $baseUrl,
            'token'       => trim((string) $this->request->getPost('token')),
            'description' => trim((string) $this->request->getPost('description')) ?: null,
            'is_active'   => $this->request->getPost('is_active') !== null ? 1 : 0,
        ];
    }
}
