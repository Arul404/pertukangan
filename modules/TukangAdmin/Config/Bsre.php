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
     * Parameter pencarian yang didukung → key filter pada body {@see $userListPath}.
     *
     * Setara pilihan "Cari data berdasarkan" di portal (Email atau NIK). Kedua key
     * di bawah DIREKAM dari portal: ia mengirim `search` KOSONG dan menaruh
     * nilainya hanya di `filters`. Bila suatu saat key filternya berubah, cukup
     * sesuaikan di sini (mis. lewat `.env`: `bsre.searchParams.nik = 'nomorNik'`).
     *
     * @var array<string, string>
     */
    public array $searchParams = [
        'email' => 'email',
        'nik'   => 'nik',
    ];

    /**
     * Endpoint detail pengguna (GET). Diakhiri dengan uid.
     * Balikan: data.data.sertifikat[], data.data.profile.
     */
    public string $userDetailsPath = '/api/rest/manage/user/details/';

    /**
     * Endpoint reset passphrase (GET). Pola: {basis}/{mode}/{uid}/{serial}.
     */
    public string $passphraseResetPath = '/api/rest/manage/cert/passphrase/';

    // -----------------------------------------------------------------------
    // Ubah data akun & persetujuannya
    //
    // Alur manual di portal: tab "Ubah Akun" -> kolom Nomor Handphone -> Simpan,
    // lalu perubahan itu HARUS disetujui di /app/users/update/list (cari user ->
    // Detail -> Verifikasi) sebelum berlaku. Path di bawah adalah panggilan API di
    // balik langkah-langkah itu, DIREKAM dari portal (HAR) — bukan tebakan.
    //
    // Catatan dari rekaman: klik "Detail" pada daftar perubahan tidak memanggil
    // API sama sekali (barisnya sudah memuat semua), jadi tidak ada
    // "updateDetailsPath" di sini.
    // -----------------------------------------------------------------------

    /**
     * Endpoint simpan perubahan data pengguna (POST). Diakhiri dengan uid.
     */
    public string $userUpdatePath = '/api/rest/manage/user/edit/';

    /**
     * Endpoint daftar permintaan perubahan (POST), setara /app/users/update/list.
     * Body-nya sebentuk dengan {@see self::$userListPath}.
     */
    public string $updateListPath = '/api/rest/manage/verify/user/update';

    /**
     * Endpoint persetujuan permintaan perubahan (POST).
     *
     * Body: {id, approve, message}. `id` adalah uid PENGGUNA — daftar perubahan
     * tidak memakai id permintaan tersendiri — dan `approve` dikirim portal
     * sebagai string "true", bukan boolean.
     */
    public string $updateVerifyPath = '/api/rest/manage/verify/user/approval';

    /**
     * Pesan yang menyertai persetujuan, sebagaimana dikirim portal.
     */
    public string $approvalMessage = 'Data terverifikasi';

    /**
     * Nama kolom "Nomor Handphone" pada profil BSrE.
     */
    public string $profilePhoneField = 'phone';

    /**
     * Kolom yang ikut dikirim saat menyimpan perubahan data akun.
     *
     * Portal TIDAK memantulkan seluruh profil — ia mengirim tepat kolom-kolom ini
     * dan tidak lebih. Menyertakan kolom lain (status, role, certificateStatus,
     * linkAktif, …) berarti mengirim sesuatu yang portal sendiri tak pernah
     * kirim, pada permintaan yang menulis data pengguna.
     *
     * Ditulis sebagai string dipisah koma, bukan array, karena
     * BaseConfig::initEnvValue() hanya menimpa kunci array yang SUDAH ada — lewat
     * .env sebuah array tidak bisa ditambah isinya.
     */
    public string $profileEditFields = 'ktpId,fotoId,videoId,nik,nip,emailAddress,nama,phone,'
        . 'provinsi,jabatanOrganisasi,organisasi,organisasiUnit';

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
     *
     * SPA mengisinya dengan IP publik operator; untuk pemakaian server localhost
     * terbukti diterima, termasuk pada permintaan yang menulis data. Bila suatu
     * saat operasi tulis ditolak tanpa sebab jelas, inilah tombol pertama yang
     * dicoba.
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
