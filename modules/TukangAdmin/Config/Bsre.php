<?php

namespace Modules\TukangAdmin\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi klien API portal BSrE (portal-bsre.bssn.go.id).
 *
 * Semua nilai dapat ditimpa lewat .env dengan prefix `bsre.`
 * (contoh: `bsre.baseURL = 'https://portal-bsre.bssn.go.id'`).
 *
 * Berbeda dengan TTE (yang berbasis HTML dan di-scrape), portal BSrE adalah SPA
 * Vue yang berkomunikasi lewat JSON API bertoken. Nama endpoint dikumpulkan di
 * sini supaya, bila path API berubah, penyesuaian cukup di satu berkas — bukan
 * tersebar di dalam {@see \Modules\TukangAdmin\Libraries\BsreClient}.
 */
class Bsre extends BaseConfig
{
    /**
     * Base URL portal BSrE, tanpa garis miring di ujung.
     */
    public string $baseURL = 'https://portal-bsre.bssn.go.id';

    /**
     * Endpoint token Keycloak SSO (Direct Access Grant).
     *
     * Portal BSrE tidak lagi memakai /api/login; autentikasi lewat Keycloak
     * (realm external-service, client `ams`). Login diotomasi dengan grant
     * `password` + `totp`; balikan memuat access_token yang dipakai sebagai
     * Bearer untuk /api/rest, plus refresh_token untuk perpanjangan diam-diam.
     */
    public string $ssoTokenURL = 'https://beid.bssn.go.id/realms/external-service/protocol/openid-connect/token';

    /** client_id publik Keycloak untuk portal BSrE. */
    public string $ssoClientId = 'ams';

    /** Scope OIDC yang diminta saat login. */
    public string $ssoScope = 'openid';

    /**
     * Nama field OTP pada permintaan token Keycloak. OTP login = TOTP
     * (authenticator app); Keycloak memakai field `totp` pada direct grant.
     */
    public string $otpField = 'totp';

    /**
     * Endpoint pencarian pengguna (POST). Body: {search, start, length, filters}.
     * Balikan: data.data.aaData[] berisi baris pengguna.
     */
    public string $userListPath = '/api/rest/manage/user/list';

    /**
     * Endpoint detail pengguna (GET). Diakhiri dengan uid.
     * Balikan: data.data.sertifikat[], data.data.profile.
     */
    public string $userDetailsPath = '/api/rest/manage/user/details/';

    /**
     * Endpoint reset passphrase (GET). Pola: {basis}/{mode}/{uid}/{serial}.
     */
    public string $passphraseResetPath = '/api/rest/manage/cert/passphrase/';

    /**
     * Endpoint verifikasi nomor HP pengguna (POST). Body: {phone, uid}.
     * Memicu BSrE mengirim tautan verifikasi ke WhatsApp pengguna.
     */
    public string $verifyPhonePath = '/api/rest/manage/user/verify/phone';

    /**
     * Mode reset passphrase pada path {@see self::$passphraseResetPath}.
     */
    public string $passphraseMode = 'reset';

    /**
     * Nilai header X-USER-IP yang wajib disertakan tiap panggilan /api/rest.
     * SPA mengisinya dari IP publik; untuk pemakaian server cukup localhost.
     */
    public string $userIp = '127.0.0.1';

    /**
     * Timeout tiap request dalam detik.
     */
    public int $timeout = 30;

    /**
     * User-Agent yang dipakai agar request tampak seperti browser biasa.
     */
    public string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Pertukangan-TukangAdmin';

    /**
     * Verifikasi sertifikat SSL host BSrE. Biarkan true; jadikan false hanya
     * bila host memakai sertifikat yang tidak terpercaya di lingkungan lokal.
     */
    public bool $verifySsl = true;
}
