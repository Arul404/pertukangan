<?php

namespace Modules\TukangAdmin\Libraries;

use Modules\TukangAdmin\Config\Bsre as BsreConfig;

/**
 * Klien API portal BSrE (portal-bsre.bssn.go.id).
 *
 * Portal BSrE adalah SPA Vue yang tidak merender HTML; seluruh aksi operator
 * (login, cari pengguna, buka detail, reset passphrase) dijalankan lewat JSON
 * API bertoken. Klien ini menirukan panggilan-panggilan itu memakai cURL:
 *
 *   POST /api/login                              -> token Bearer
 *   POST /api/rest/manage/user/list              -> cari pengguna (by email)
 *   GET  /api/rest/manage/user/details/{uid}     -> sertifikat + status HP
 *   GET  /api/rest/manage/cert/passphrase/reset/{uid}/{serial}  -> kirim tautan reset
 *   POST /api/rest/manage/user/verify/phone      -> kirim tautan verifikasi HP (WA)
 *
 * Tiap panggilan /api/rest wajib membawa header Authorization (token) dan
 * X-USER-IP. Token diperoleh saat login dan dipakai ulang selama masih berlaku;
 * controller menyimpannya di session agar OTP tidak diminta berulang.
 *
 * Cookie jar cURL dipertahankan selama satu instance, sehingga handshake login
 * berbasis cookie (bila ada, mis. saat langkah OTP) tetap terbawa antar request.
 *
 * CATATAN IMPLEMENTASI: bentuk pasti balikan API (mis. key pada baris hasil
 * pencarian, path field pada detail, dan protokol OTP saat login) difinalkan
 * dengan login sungguhan. Pembaca di bawah sengaja dibuat toleran: mencari nilai
 * lewat beberapa kemungkinan key, bukan satu key kaku.
 */
class BsreClient
{
    protected BsreConfig $config;
    protected string $baseUrl;
    protected string $cookieJar;

    /** Token Bearer lengkap (mis. "Bearer eyJ..."), null sebelum login. */
    protected ?string $token = null;

    public function __construct(?BsreConfig $config = null, ?string $baseUrl = null)
    {
        $this->config    = $config ?? config(BsreConfig::class);
        $this->baseUrl   = rtrim($baseUrl ?: $this->config->baseURL, '/');
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'bsre_');
    }

    public function __destruct()
    {
        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    // ---------------------------------------------------------------------
    // Autentikasi
    // ---------------------------------------------------------------------

    /**
     * Login ke Keycloak SSO dengan Direct Access Grant (grant `password`).
     *
     * OTP login berupa TOTP (authenticator app) yang diisi manual oleh operator;
     * Keycloak menerimanya lewat field `totp`. Balikan memuat access_token (dipakai
     * sebagai Bearer untuk /api/rest) dan refresh_token (untuk perpanjangan).
     *
     * @return array{
     *     access_token: string,
     *     refresh_token: ?string,
     *     expires_in: int,
     *     refresh_expires_in: int,
     *     token_type: string
     * }
     */
    public function loginWithPassword(string $username, string $password, ?string $otp = null): array
    {
        $form = [
            'grant_type' => 'password',
            'client_id'  => $this->config->ssoClientId,
            'scope'      => $this->config->ssoScope,
            'username'   => $username,
            'password'   => $password,
        ];

        if ($otp !== null && $otp !== '') {
            $form[$this->config->otpField] = $otp;
        }

        return $this->tokenGrant($form, 'Login BSrE gagal — periksa username, kata sandi, dan kode OTP.');
    }

    /**
     * Perbarui access_token memakai refresh_token, tanpa OTP ulang.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, refresh_expires_in: int, token_type: string}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenGrant([
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->config->ssoClientId,
            'refresh_token' => $refreshToken,
        ], 'Sesi BSrE tidak dapat diperpanjang. Silakan hubungkan ulang.');
    }

    /**
     * Kirim permintaan token ke Keycloak (form-urlencoded) dan normalkan balikannya.
     *
     * @param array<string, string> $form
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, refresh_expires_in: int, token_type: string}
     */
    protected function tokenGrant(array $form, string $failMessage): array
    {
        $res  = $this->ssoRequest($form);
        $json = $res['json'];

        $accessToken = is_array($json) ? ($json['access_token'] ?? null) : null;

        if (! is_string($accessToken) || $accessToken === '') {
            // Keycloak: {"error":"invalid_grant","error_description":"..."}.
            $desc = is_array($json)
                ? ($json['error_description'] ?? $json['error'] ?? null)
                : null;

            throw new BsreClientException(is_string($desc) && $desc !== '' ? $desc : $failMessage);
        }

        $this->setToken($accessToken);

        return [
            'access_token'       => $accessToken,
            'refresh_token'      => isset($json['refresh_token']) ? (string) $json['refresh_token'] : null,
            'expires_in'         => (int) ($json['expires_in'] ?? 0),
            'refresh_expires_in' => (int) ($json['refresh_expires_in'] ?? 0),
            'token_type'         => (string) ($json['token_type'] ?? 'Bearer'),
        ];
    }

    /**
     * Pasang token yang sudah tersimpan (mis. dari session) tanpa login ulang.
     */
    public function withToken(string $token): self
    {
        $this->setToken($token);

        return $this;
    }

    /** Simpan token dalam format "Bearer <token>" sebagaimana dipakai portal. */
    protected function setToken(string $token): void
    {
        $this->token = str_starts_with($token, 'Bearer ') ? $token : 'Bearer ' . $token;
    }

    // ---------------------------------------------------------------------
    // Operasi pengguna
    // ---------------------------------------------------------------------

    /**
     * Cari satu pengguna berdasarkan email, lalu ambil detail sertifikatnya.
     *
     * Meniru alur operator: cari di /users/list dengan parameter Email, buka
     * baris yang cocok (Detail), lalu baca tab Sertifikat Elektronik.
     *
     * @return array{
     *     uid: string,
     *     name: string,
     *     email: string,
     *     phone: ?string,
     *     phoneVerified: bool,
     *     certificates: list<array{serial: string, jenis: ?string, notAfter: ?string, raw: array}>
     * }
     */
    public function findUserByEmail(string $email): array
    {
        $this->requireToken();

        $list = $this->request('POST', $this->config->userListPath, [
            'search'  => $email,
            'start'   => 0,
            'length'  => 10,
            'filters' => ['email' => $email],
        ]);

        $rows = $this->rowsOf($list['json']);

        if ($rows === []) {
            throw new BsreClientException('Tidak ada pengguna dengan email "' . $email . '".');
        }

        $uid = $this->uidForEmail($rows, $email);

        if ($uid === null) {
            throw new BsreClientException(
                'Baris pengguna untuk "' . $email . '" ditemukan, tetapi id-nya tidak terbaca.'
            );
        }

        return $this->userDetails($uid, $email);
    }

    /**
     * Detail pengguna (sertifikat + status verifikasi HP) berdasarkan uid.
     *
     * @return array{uid: string, name: string, email: string, phone: ?string, phoneVerified: bool, certificates: list<array>}
     */
    public function userDetails(string $uid, string $emailFallback = ''): array
    {
        $this->requireToken();

        $res  = $this->request('GET', $this->config->userDetailsPath . rawurlencode($uid));
        $data = $this->dataOf($res['json']);

        $profile = $this->valueOf($data, ['profile', 'user', 'dataUser']) ?? $data;
        $certs   = $this->valueOf($data, ['sertifikat', 'certificates', 'certificate']) ?? [];

        $certificates = [];

        foreach (is_array($certs) ? $certs : [] as $cert) {
            if (! is_array($cert)) {
                continue;
            }

            $serial = $this->valueOf($cert, ['serialNumber', 'serial', 'serial_number']);

            if ($serial === null || $serial === '') {
                continue;
            }

            $certificates[] = [
                'serial'   => (string) $serial,
                'jenis'    => $this->stringOrNull($this->valueOf($cert, ['jenisSertifikat', 'jenis', 'type'])),
                'notAfter' => $this->stringOrNull($this->valueOf($cert, ['notAfterDate', 'notAfter', 'expiredDate'])),
                'raw'      => $cert,
            ];
        }

        return [
            'uid'           => $uid,
            'name'          => (string) ($this->valueOf($profile, ['nama', 'name', 'fullname']) ?? $emailFallback),
            'email'         => (string) ($this->valueOf($profile, ['email', 'emailDinas', 'mail']) ?? $emailFallback),
            'phone'         => $this->stringOrNull($this->valueOf($profile, ['noHp', 'phone', 'phoneNumber', 'no_hp', 'telepon'])),
            'phoneVerified' => (bool) ($this->valueOf($profile, ['phoneVerified', 'phone_verified', 'isPhoneVerified']) ?? false),
            'certificates'  => $certificates,
        ];
    }

    /**
     * Kirim tautan reset passphrase untuk sebuah sertifikat.
     *
     * Setara aksi "Aksi > Reset passphrase" pada HP yang sudah terverifikasi:
     * BSrE membalas notifikasi bahwa tautan sudah dikirim (Outcome 2).
     *
     * @return string Pesan sukses dari server.
     */
    public function resetPassphrase(string $uid, string $serial): string
    {
        $this->requireToken();

        $path = $this->config->passphraseResetPath
            . rawurlencode($this->config->passphraseMode) . '/'
            . rawurlencode($uid) . '/'
            . rawurlencode($serial);

        $res = $this->request('GET', $path);

        if (! $this->isSuccess($res)) {
            throw new BsreClientException(
                $this->messageOf($res) ?? 'BSrE menolak permintaan reset passphrase (HTTP ' . $res['code'] . ').'
            );
        }

        return $this->messageOf($res) ?? 'Tautan reset passphrase telah dikirim.';
    }

    /**
     * Picu verifikasi nomor HP pengguna.
     *
     * Setara dialog "verifikasi" yang muncul saat HP pengguna belum terverifikasi:
     * BSrE mengirim tautan verifikasi ke WhatsApp pengguna (Outcome 1).
     *
     * @return string Pesan dari server.
     */
    public function verifyPhone(string $uid, string $phone): string
    {
        $this->requireToken();

        $res = $this->request('POST', $this->config->verifyPhonePath, [
            'uid'   => $uid,
            'phone' => $phone,
        ]);

        if (! $this->isSuccess($res)) {
            throw new BsreClientException(
                $this->messageOf($res) ?? 'BSrE menolak permintaan verifikasi nomor HP (HTTP ' . $res['code'] . ').'
            );
        }

        return $this->messageOf($res)
            ?? 'Tautan verifikasi telah dikirim ke WhatsApp pengguna.';
    }

    // ---------------------------------------------------------------------
    // HTTP
    // ---------------------------------------------------------------------

    /**
     * Satu request cURL JSON. Menyertakan header token & X-USER-IP bila $auth.
     *
     * @param array<string, mixed>|null $body Payload JSON, atau null untuk GET.
     *
     * @return array{code: int, json: mixed, body: string}
     */
    protected function request(string $method, string $path, ?array $body = null, bool $auth = true): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;

        $headers = [
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($auth) {
            $headers[] = 'X-USER-IP: ' . $this->config->userIp;

            if ($this->token !== null) {
                $headers[] = 'Authorization: ' . $this->token;
            }
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_USERAGENT      => $this->config->userAgent,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? []));
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new BsreClientException('Gagal menghubungi BSrE: ' . $error);
        }

        $info = curl_getinfo($ch);
        curl_close($ch);

        return [
            'code' => (int) ($info['http_code'] ?? 0),
            'json' => json_decode((string) $raw, true),
            'body' => (string) $raw,
        ];
    }

    protected function requireToken(): void
    {
        if ($this->token === null) {
            throw new BsreClientException('Belum login ke BSrE. Hubungkan akun BSrE terlebih dahulu.');
        }
    }

    /**
     * Permintaan token ke Keycloak: POST form-urlencoded, tanpa header auth.
     *
     * @param array<string, string> $form
     *
     * @return array{code: int, json: mixed, body: string}
     */
    protected function ssoRequest(array $form): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->config->ssoTokenURL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_USERAGENT      => $this->config->userAgent,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifySsl ? 2 : 0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new BsreClientException('Gagal menghubungi SSO BSrE: ' . $error);
        }

        $info = curl_getinfo($ch);
        curl_close($ch);

        return [
            'code' => (int) ($info['http_code'] ?? 0),
            'json' => json_decode((string) $raw, true),
            'body' => (string) $raw,
        ];
    }

    // ---------------------------------------------------------------------
    // Pembaca balikan yang toleran terhadap variasi bentuk
    // ---------------------------------------------------------------------

    /**
     * Ambil nilai pertama yang ada dari beberapa kemungkinan key pada larik.
     *
     * @param list<string> $keys
     */
    protected function valueOf(mixed $data, array $keys): mixed
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                return $data[$key];
            }
        }

        return null;
    }

    /** Objek `data` pada balikan API, atau larik itu sendiri bila tak ada. */
    protected function dataOf(mixed $json): mixed
    {
        if (is_array($json) && array_key_exists('data', $json) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    /** Baris hasil pencarian pengguna (aaData) pada beberapa lokasi. */
    protected function rowsOf(mixed $json): array
    {
        $data = $this->dataOf($json);
        $rows = $this->valueOf($data, ['aaData', 'rows', 'items', 'list']);

        if ($rows === null && is_array($data) && array_is_list($data)) {
            $rows = $data;
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * uid dari baris yang emailnya cocok; bila hanya satu baris, ambil itu.
     *
     * @param list<mixed> $rows
     */
    protected function uidForEmail(array $rows, string $email): ?string
    {
        $needle = strtolower(trim($email));
        $single = count($rows) === 1 ? $this->uidOf($rows[0]) : null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (str_contains(strtolower(json_encode($row) ?: ''), $needle)) {
                $uid = $this->uidOf($row);

                if ($uid !== null) {
                    return $uid;
                }
            }
        }

        return $single;
    }

    /** id pengguna dari satu baris hasil pencarian. */
    protected function uidOf(mixed $row): ?string
    {
        $uid = $this->valueOf($row, ['id', 'uid', 'userId', 'user_id']);

        return $uid !== null && $uid !== '' ? (string) $uid : null;
    }

    /**
     * Apakah request dianggap sukses? 2xx dan, bila ada, field success bukan false.
     *
     * @param array{code: int, json: mixed, body: string} $res
     */
    protected function isSuccess(array $res): bool
    {
        if ($res['code'] < 200 || $res['code'] >= 300) {
            return false;
        }

        $success = $this->valueOf($res['json'], ['success', 'status']);

        if ($success === false || $success === 'error' || $success === 0) {
            return false;
        }

        return true;
    }

    /**
     * Pesan yang dapat ditampilkan dari balikan API.
     *
     * @param array{json?: mixed} $res
     */
    protected function messageOf(array $res): ?string
    {
        $message = $this->valueOf($res['json'] ?? null, ['message', 'msg', 'error', 'statusText']);

        return is_string($message) && $message !== '' ? $message : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
