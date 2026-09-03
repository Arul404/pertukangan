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
 * Akun BSrE — kredensial login portal (username/email + kata sandi) plus aksi
 * "Hubungkan": login ke Keycloak SSO memakai Direct Access Grant dengan kode
 * TOTP yang diisi manual, lalu menyimpan access_token + refresh_token ke session
 * agar dipakai ulang oleh Reset Passphrase tanpa OTP berulang.
 *
 * Kata sandi disimpan terenkripsi (lihat BsreCredentialModel); token sesi hanya
 * hidup di session, tidak pernah ditulis ke database. Saat access_token habis,
 * ia diperpanjang diam-diam lewat refresh_token selama refresh masih berlaku.
 */
class BsreAccount extends BaseController
{
    /** Session key penampung token Keycloak (access + refresh + waktu). */
    public const TOKEN_KEY = 'tukang_admin_bsre_token';

    /** Buffer detik sebelum kedaluwarsa agar token tidak dipakai mepet. */
    protected const SKEW = 30;

    protected BsreCredentialModel $credentials;

    public function __construct()
    {
        $this->credentials = new BsreCredentialModel();
    }

    public function index(): string
    {
        return view(Module::VIEWS . 'account_bsre/form', [
            'title'         => 'Akun BSrE',
            'credential'    => $this->credentials->current(),
            'baseUrl'       => config(BsreConfig::class)->baseURL,
            'hasEncryptKey' => BsreCredentialModel::hasEncryptionKey(),
            'connected'     => self::hasValidToken(),
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
            $data['password'] = BsreCredentialModel::encrypt($password);
        }

        if ($current !== null) {
            $this->credentials->update($current['id'], $data);
        } else {
            $this->credentials->insert($data);
        }

        // Kredensial berubah -> token lama tidak lagi tepercaya.
        session()->remove(self::TOKEN_KEY);

        return redirect()->to(module_url('akun-bsre'))
            ->with('success', 'Kredensial BSrE berhasil disimpan.');
    }

    /**
     * Hubungkan ke BSrE (AJAX): login Keycloak dengan kredensial tersimpan + TOTP,
     * lalu simpan token ke session.
     */
    public function login(): ResponseInterface|RedirectResponse
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url('akun-bsre'));
        }

        $current = $this->credentials->current();

        if ($current === null) {
            return $this->json(['ok' => false, 'error' => 'Belum ada kredensial BSrE tersimpan.']);
        }

        $otp = trim((string) $this->request->getPost('otp'));

        if ($otp === '') {
            return $this->json(['ok' => false, 'error' => 'Kode OTP (TOTP) wajib diisi.']);
        }

        try {
            $client = new BsreClient(null, $current['base_url'] ?? null);
            $tokens = $client->loginWithPassword(
                $current['username'],
                $this->credentials->plainPassword($current),
                $otp
            );

            $this->storeTokens($tokens);

            return $this->json(['ok' => true]);
        } catch (BsreClientException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Kesalahan tak terduga: ' . $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------------
    // Token session
    // ---------------------------------------------------------------------

    /**
     * @param array{access_token: string, refresh_token: ?string, expires_in: int, refresh_expires_in: int} $tokens
     */
    protected function storeTokens(array $tokens): void
    {
        $now = time();

        session()->set(self::TOKEN_KEY, [
            'access_token'    => $tokens['access_token'],
            'refresh_token'   => $tokens['refresh_token'],
            'access_expires'  => $now + (int) $tokens['expires_in'],
            'refresh_expires' => $now + (int) $tokens['refresh_expires_in'],
        ]);
    }

    /**
     * Access token yang siap pakai untuk /api/rest, atau null bila sesi habis.
     *
     * Bila access_token sudah/hampir kedaluwarsa tetapi refresh_token masih
     * berlaku, token diperpanjang diam-diam dan session diperbarui.
     */
    public static function currentAccessToken(): ?string
    {
        $bag = session(self::TOKEN_KEY);

        if (! is_array($bag) || empty($bag['access_token'])) {
            return null;
        }

        $now = time();

        if (($bag['access_expires'] ?? 0) > $now + self::SKEW) {
            return (string) $bag['access_token'];
        }

        // Access token habis: coba perpanjang lewat refresh_token.
        if (! empty($bag['refresh_token']) && ($bag['refresh_expires'] ?? 0) > $now + self::SKEW) {
            try {
                $tokens = (new BsreClient())->refresh((string) $bag['refresh_token']);

                session()->set(self::TOKEN_KEY, [
                    'access_token'    => $tokens['access_token'],
                    'refresh_token'   => $tokens['refresh_token'] ?? $bag['refresh_token'],
                    'access_expires'  => $now + (int) $tokens['expires_in'],
                    'refresh_expires' => $now + (int) $tokens['refresh_expires_in'],
                ]);

                return $tokens['access_token'];
            } catch (Throwable) {
                session()->remove(self::TOKEN_KEY);

                return null;
            }
        }

        // Keduanya habis.
        session()->remove(self::TOKEN_KEY);

        return null;
    }

    public static function hasValidToken(): bool
    {
        return self::currentAccessToken() !== null;
    }

    /**
     * Respons JSON standar dengan hash CSRF baru (Config\Security::$regenerate).
     *
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload): ResponseInterface
    {
        return $this->response->setJSON($payload + ['csrf' => csrf_hash()]);
    }
}
