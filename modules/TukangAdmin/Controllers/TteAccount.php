<?php

namespace Modules\TukangAdmin\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\TukangAdmin\Config\Module;
use Modules\TukangAdmin\Config\Tte as TteConfig;
use Modules\TukangAdmin\Libraries\TteScraper;
use Modules\TukangAdmin\Libraries\TteScraperException;
use Modules\TukangAdmin\Models\TteCredentialModel;
use Throwable;

/**
 * Kredensial login TTE — satu baris, dikelola lewat satu form.
 *
 * Kata sandi disimpan terenkripsi (lihat TteCredentialModel). Karena scraper
 * harus bisa login ulang, kata sandi tidak bisa di-hash: risiko ini disampaikan
 * ke operator lewat peringatan di view bila kunci enkripsi belum diset.
 */
class TteAccount extends BaseController
{
    protected TteCredentialModel $credentials;

    public function __construct()
    {
        $this->credentials = new TteCredentialModel();
    }

    public function index(): string
    {
        return view(Module::VIEWS . 'account/form', [
            'title'          => 'Akun TTE',
            'credential'     => $this->credentials->current(),
            'baseUrl'        => config(TteConfig::class)->baseURL,
            'hasEncryptKey'  => TteCredentialModel::hasEncryptionKey(),
        ]);
    }

    /**
     * Simpan kredensial. Bila sudah ada baris aktif, ia diperbarui; kata sandi
     * dibiarkan apa adanya bila field dikosongkan saat menyunting.
     */
    public function save(): RedirectResponse
    {
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');
        $baseUrl  = trim((string) $this->request->getPost('base_url'));

        $current = $this->credentials->current();

        $errors = [];

        if ($username === '') {
            $errors[] = 'Username/email wajib diisi.';
        }

        // Kata sandi wajib saat pertama kali; boleh kosong saat menyunting
        // (berarti "pertahankan yang lama").
        if ($password === '' && $current === null) {
            $errors[] = 'Kata sandi wajib diisi.';
        }

        if ($errors !== []) {
            return redirect()->back()->withInput()->with('errors', $errors);
        }

        $data = [
            'username'  => $username,
            'base_url'  => $baseUrl !== '' ? $baseUrl : null,
            'is_active' => 1,
        ];

        if ($password !== '') {
            $data['password'] = TteCredentialModel::encrypt($password);
        }

        if ($current !== null) {
            // allowedFields membuang 'password' bila tidak diset, jadi aman.
            $this->credentials->update($current['id'], $data);
        } else {
            $this->credentials->insert($data);
        }

        return redirect()->to(module_url('akun'))
            ->with('success', 'Kredensial TTE berhasil disimpan.');
    }

    /**
     * Uji login TTE dengan kredensial tersimpan (AJAX).
     */
    public function test(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url('akun'));
        }

        $current = $this->credentials->current();

        if ($current === null) {
            return $this->response->setJSON([
                'ok'    => false,
                'error' => 'Belum ada kredensial TTE tersimpan.',
                'csrf'  => csrf_hash(),
            ]);
        }

        try {
            $scraper = new TteScraper(null, $current['base_url'] ?? null);
            $scraper->login($current['username'], $this->credentials->plainPassword($current));

            return $this->response->setJSON([
                'ok'   => true,
                'csrf' => csrf_hash(),
            ]);
        } catch (TteScraperException $e) {
            return $this->response->setJSON([
                'ok'    => false,
                'error' => $e->getMessage(),
                'csrf'  => csrf_hash(),
            ]);
        } catch (Throwable $e) {
            return $this->response->setJSON([
                'ok'    => false,
                'error' => 'Kesalahan tak terduga: ' . $e->getMessage(),
                'csrf'  => csrf_hash(),
            ]);
        }
    }
}
