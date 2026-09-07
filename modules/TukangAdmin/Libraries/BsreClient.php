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
 *   POST /api/rest/manage/user/list              -> cari pengguna (by email/NIK)
 *   GET  /api/rest/manage/user/details/{uid}     -> profil + sertifikat + status HP
 *   GET  /api/rest/manage/cert/passphrase/reset/{uid}/{serial}  -> kirim tautan reset
 *   POST /api/rest/manage/user/verify/phone      -> kirim tautan verifikasi HP (WA)
 *
 * Ditambah tiga panggilan TULIS untuk memperbaiki nomor HP yang salah:
 *
 *   POST /api/rest/manage/user/edit/{uid}        -> simpan perubahan (masuk antrean)
 *   POST /api/rest/manage/verify/user/update     -> permintaan perubahan yang menunggu
 *   POST /api/rest/manage/verify/user/approval   -> setujui permintaan itu
 *
 * Tiap panggilan /api/rest wajib membawa header Authorization (token) dan
 * X-USER-IP. Token diperoleh saat login dan dipakai ulang selama masih berlaku;
 * controller menyimpannya di session agar OTP tidak diminta berulang.
 *
 * Cookie jar cURL dipertahankan selama satu instance, sehingga handshake login
 * berbasis cookie (bila ada, mis. saat langkah OTP) tetap terbawa antar request.
 *
 * Seluruh path dan bentuk payload di atas DIREKAM dari portal (tangkapan HAR),
 * bukan ditebak — termasuk detail yang mudah salah kalau dikira-kira: `search`
 * dikirim kosong sementara nilainya hanya di `filters`, `approve` berupa string
 * "true", dan penyimpanan profil hanya membawa kolom tertentu. Pembaca balikan
 * tetap dibuat toleran (mencari nilai lewat beberapa kemungkinan key) supaya
 * perubahan kecil di sisi BSrE tidak langsung mematahkan modul ini.
 */
class BsreClient
{
    /** Kemungkinan nama kolom nomor HP pada profil pengguna. */
    protected const PHONE_KEYS = ['phone', 'noHp', 'phoneNumber', 'no_hp', 'telepon'];

    /** Nilai `status` sertifikat yang masih berlaku. */
    protected const CERT_ISSUED = 'ISSUE';

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
     * Cari satu pengguna berdasarkan email atau NIK, lalu ambil detail sertifikatnya.
     *
     * Meniru alur operator: pilih "Cari data berdasarkan" (Email/NIK) di
     * /users/list, buka baris yang cocok (Detail), lalu baca tab Sertifikat
     * Elektronik. `$filterKey` adalah key filter server untuk parameter terpilih.
     *
     * @return array{
     *     uid: string,
     *     name: string,
     *     email: string,
     *     phone: ?string,
     *     phoneVerified: bool,
     *     certificates: list<array{serial: string, jenis: ?string, notAfter: ?string, status: ?string, raw: array}>
     * }
     */
    public function findUser(string $value, string $filterKey = 'email'): array
    {
        $this->requireToken();

        // Bentuk body ini direkam dari portal: `search` dikirim KOSONG dan nilai
        // pencarian hanya ditaruh di `filters`. Mengisi `search` juga berarti
        // menebak-nebak perilaku yang tidak pernah kita amati.
        $list = $this->request('POST', $this->config->userListPath, [
            'search'  => '',
            'start'   => 0,
            'length'  => 10,
            'filters' => [$filterKey => $value],
        ]);

        $rows = $this->rowsOf($list['json']);

        if ($rows === []) {
            throw new BsreClientException('Tidak ada pengguna dengan ' . $filterKey . ' "' . $value . '".');
        }

        $uid = $this->uidForValue($rows, $value);

        if ($uid === null) {
            // Baris hasil pencarian tidak selalu memuat nilai yang dicari — pada
            // pencarian NIK, kolom nik/nip/phone dikirim kosong — jadi saat ada
            // beberapa baris, tak satu pun bisa dipastikan sebagai orang yang
            // dimaksud. Menebak salah satu di sinilah yang harus dihindari.
            throw new BsreClientException('Pencarian ' . $filterKey . ' "' . $value . '" menghasilkan '
                . count($rows) . ' pengguna dan tidak ada yang bisa dipastikan sebagai orang yang '
                . 'dimaksud. Persempit dengan email.');
        }

        // Fallback email hanya bermakna bila pencarian memang lewat email.
        return $this->userDetails($uid, $filterKey === 'email' ? $value : '');
    }

    /**
     * Detail pengguna (sertifikat + status verifikasi HP) berdasarkan uid.
     *
     * @return array{uid: string, name: string, email: string, nik: ?string, phone: ?string, phoneVerified: bool, certificates: list<array>}
     */
    public function userDetails(string $uid, string $emailFallback = ''): array
    {
        $data = $this->detailsPayload($uid);

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
                'status'   => $this->stringOrNull($this->valueOf($cert, ['status'])),
                'raw'      => $cert,
            ];
        }

        // Pemanggil mengambil sertifikat pertama sebagai target reset, jadi yang
        // berstatus terbit didahulukan: satu akun bisa punya sertifikat lama yang
        // sudah dicabut, dan urutan balikan server bukan jaminan apa pun.
        usort($certificates, static fn (array $a, array $b): int => (int) ($b['status'] === self::CERT_ISSUED)
            <=> (int) ($a['status'] === self::CERT_ISSUED));

        return [
            'uid'           => $uid,
            'name'          => (string) ($this->valueOf($profile, ['nama', 'name', 'fullname']) ?? $emailFallback),
            // `emailAddress` adalah nama yang BSrE pakai; tanpa itu, pencarian lewat
            // NIK/No HP menghasilkan draft ber-email kosong — dan email itulah yang
            // dipakai mencari antrean persetujuan nanti.
            'email'         => (string) ($this->valueOf($profile, ['emailAddress', 'email', 'emailDinas', 'mail']) ?? $emailFallback),
            // Antrean persetujuan perubahan dicari lewat NIK/email, jadi NIK ikut
            // dibawa pulang selagi profilnya memang sudah di tangan.
            'nik'           => $this->stringOrNull($this->valueOf($profile, ['nik', 'nomorNik', 'noIdentitas', 'no_identitas'])),
            'phone'         => $this->stringOrNull($this->valueOf($profile, self::PHONE_KEYS)),
            'phoneVerified' => (bool) ($this->valueOf($profile, ['phoneVerified', 'phone_verified', 'isPhoneVerified']) ?? false),
            'certificates'  => $certificates,
        ];
    }

    /**
     * Objek `data` pada balikan detail pengguna.
     */
    protected function detailsPayload(string $uid): mixed
    {
        $this->requireToken();

        $res = $this->request('GET', $this->config->userDetailsPath . rawurlencode($uid));

        return $this->dataOf($res['json']);
    }

    /**
     * Profil pengguna APA ADANYA, sebagaimana dikirim server.
     *
     * Berbeda dengan {@see self::userDetails()} yang menormalkan beberapa field
     * saja, ini mengembalikan seluruh objek profil — dibutuhkan {@see
     * self::updateUserPhone()} yang harus mengirim ulang kolom-kolom itu tanpa
     * perlu tahu artinya.
     *
     * @return array<string, mixed>
     */
    public function userProfile(string $uid): array
    {
        $data    = $this->detailsPayload($uid);
        $profile = $this->valueOf($data, ['profile', 'user', 'dataUser']) ?? $data;

        return is_array($profile) ? $profile : [];
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
    // Ubah data akun & persetujuannya
    // ---------------------------------------------------------------------

    /**
     * Simpan perubahan nomor HP pengguna (setara tab "Ubah Akun" -> Simpan).
     *
     * Portal tidak memantulkan seluruh profil: ia mengirim tepat kolom-kolom pada
     * {@see BsreConfig::$profileEditFields} dan tidak lebih. Payload di sini
     * disusun persis begitu, diambil dari profil yang BARU SAJA dikembalikan
     * server, dengan hanya kolom nomor yang diganti — supaya permintaan tulis ini
     * tidak pernah membawa nilai yang tidak berasal dari BSrE sendiri.
     *
     * Bila ada satu saja kolom wajib yang tidak ada pada profil, TIDAK ADA yang
     * dikirim. Prinsipnya sama dengan `editStrictFields` pada
     * {@see TteScraper::updateWhatsapp()}: lebih baik batal daripada menyimpan
     * profil dengan kolom hasil tebakan.
     *
     * Perubahan ini BELUM berlaku — ia masuk antrean dan harus disetujui lewat
     * {@see self::approveUserUpdate()}.
     *
     * @return array{before: ?string, after: string, message: string}
     */
    public function updateUserPhone(string $uid, string $phone): array
    {
        $path    = $this->requirePath($this->config->userUpdatePath, 'userUpdatePath', 'menyimpan perubahan data akun');
        $profile = $this->userProfile($uid);

        if ($profile === []) {
            throw new BsreClientException('Profil pengguna tidak terbaca dari BSrE, jadi perubahan '
                . 'nomor dibatalkan. Tidak ada data yang dikirim.');
        }

        $field   = $this->config->profilePhoneField;
        $wanted  = $this->editFields();
        $payload = [];
        $missing = [];

        foreach ($wanted as $key) {
            if (! array_key_exists($key, $profile)) {
                $missing[] = $key;

                continue;
            }

            $payload[$key] = $profile[$key];
        }

        if ($missing !== []) {
            throw new BsreClientException('Kolom ' . implode(', ', $missing) . ' tidak ada pada profil '
                . 'yang dikirim BSrE, jadi perubahan nomor dibatalkan agar data pengguna tidak tersimpan '
                . 'dengan kolom hasil tebakan. Tidak ada yang dikirim. Sesuaikan bsre.profileEditFields '
                . 'bila bentuk profilnya berubah.');
        }

        $before          = $this->stringOrNull($profile[$field] ?? null);
        $payload[$field] = $phone;

        $res = $this->request('POST', $path . rawurlencode($uid), $payload);

        if (! $this->isSuccess($res)) {
            throw new BsreClientException(
                $this->messageOf($res) ?? 'BSrE menolak perubahan nomor HP (HTTP ' . $res['code'] . ').'
            );
        }

        return [
            'before'  => $before,
            'after'   => $phone,
            'message' => $this->messageOf($res) ?? 'Perubahan nomor HP tersimpan dan menunggu persetujuan.',
        ];
    }

    /**
     * Kolom yang ikut dikirim saat menyimpan profil, sudah dipecah dan dirapikan.
     *
     * Kolom nomor selalu ikut walau tidak tercantum di config — tanpanya
     * perubahan yang hendak disimpan justru tidak akan terkirim.
     *
     * @return list<string>
     */
    protected function editFields(): array
    {
        $fields = [];

        foreach (explode(',', $this->config->profileEditFields) as $piece) {
            $piece = trim($piece);

            if ($piece !== '' && ! in_array($piece, $fields, true)) {
                $fields[] = $piece;
            }
        }

        if (! in_array($this->config->profilePhoneField, $fields, true)) {
            $fields[] = $this->config->profilePhoneField;
        }

        return $fields;
    }

    /**
     * Pastikan ada permintaan perubahan data milik $uid yang menunggu persetujuan.
     *
     * Setara membuka /app/users/update/list lalu mencari pengguna berdasarkan
     * NIK/email. Perannya KONFIRMASI, bukan mencari id: barisnya ber-`id` sama
     * persis dengan uid pengguna, jadi id-nya sudah kita pegang sejak awal. Yang
     * dibeli langkah ini adalah kepastian bahwa perubahannya memang masih
     * menunggu — tanpa itu, persetujuan bisa dikirim untuk sesuatu yang sudah
     * disetujui, kedaluwarsa, atau tidak pernah tersimpan.
     *
     * @return array{id: string, raw: array<string, mixed>}
     */
    public function findUpdateRequest(string $uid, string $value, string $filterKey = 'email'): array
    {
        $path = $this->requirePath($this->config->updateListPath, 'updateListPath', 'mencari permintaan perubahan');

        $list = $this->request('POST', $path, [
            'search'  => '',
            'start'   => 0,
            'length'  => 10,
            'filters' => [$filterKey => $value],
        ]);

        foreach ($this->rowsOf($list['json']) as $row) {
            if (is_array($row) && $this->uidOf($row) === $uid) {
                return ['id' => $uid, 'raw' => $row];
            }
        }

        throw new BsreClientException('Tidak ada permintaan perubahan data yang menunggu persetujuan '
            . 'untuk pengguna ini. Perubahannya mungkin sudah disetujui, atau belum tersimpan.');
    }

    /**
     * Setujui satu permintaan perubahan data (tombol "Verifikasi" pada detailnya).
     *
     * @return string Pesan sukses dari server.
     */
    public function approveUserUpdate(string $requestId): string
    {
        $path = $this->requirePath($this->config->updateVerifyPath, 'updateVerifyPath', 'menyetujui perubahan data');

        // `approve` dikirim portal sebagai string "true", bukan boolean — ditiru
        // apa adanya karena tak ada gunanya menguji apakah server juga menerima
        // bentuk lain pada permintaan yang menyetujui perubahan data.
        $res = $this->request('POST', $path, [
            'id'      => $requestId,
            'approve' => 'true',
            'message' => $this->config->approvalMessage,
        ]);

        if (! $this->isSuccess($res)) {
            throw new BsreClientException(
                $this->messageOf($res) ?? 'BSrE menolak persetujuan perubahan data (HTTP ' . $res['code'] . ').'
            );
        }

        return $this->messageOf($res) ?? 'Perubahan data akun telah disetujui.';
    }

    /**
     * Pastikan sebuah path endpoint sudah dikonfigurasi.
     *
     * Endpoint tulis sengaja kosong secara bawaan {@see \Modules\TukangAdmin\Config\Bsre}.
     * Pesan galatnya menyebut nama kunci .env-nya supaya operator tahu persis apa
     * yang kurang, bukan sekadar "gagal".
     */
    protected function requirePath(string $path, string $key, string $purpose): string
    {
        if (trim($path) === '') {
            throw new BsreClientException('Endpoint BSrE untuk ' . $purpose . ' belum dikonfigurasi. '
                . 'Isi `bsre.' . $key . '` di .env dengan path API yang dipakai portal.');
        }

        return $path;
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
     * uid dari baris yang memuat nilai pencarian (email/NIK); bila hanya satu
     * baris, ambil itu.
     *
     * @param list<mixed> $rows
     */
    protected function uidForValue(array $rows, string $value): ?string
    {
        $needle = strtolower(trim($value));
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
