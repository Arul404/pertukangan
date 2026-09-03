<?php

namespace Modules\TukangAdmin\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi scraping aplikasi TTE Kabupaten Magelang.
 *
 * Semua nilai dapat ditimpa lewat .env dengan prefix `tte.`
 * (contoh: `tte.baseURL = 'https://tte.magelangkab.go.id'`).
 *
 * Nama field & selector dikumpulkan di sini supaya, bila markup TTE berubah,
 * penyesuaian cukup di satu berkas — bukan tersebar di dalam TteScraper.
 *
 * @see \Modules\TukangAdmin\Libraries\TteScraper
 */
class Tte extends BaseConfig
{
    /**
     * Base URL aplikasi TTE, tanpa garis miring di ujung.
     */
    public string $baseURL = 'https://tte.magelangkab.go.id';

    /**
     * Path halaman login (relatif terhadap baseURL).
     */
    public string $loginPath = '/login';

    /**
     * Path daftar pengguna yang mendukung pencarian.
     */
    public string $usersPath = '/users';

    /**
     * Nama parameter pencarian pada halaman /users (kolom "Masukan kata kunci").
     */
    public string $searchParam = 'q';

    /**
     * Nama field form login TTE.
     */
    public string $loginField = 'login';
    public string $passwordField = 'password';

    /**
     * Nama hidden field token CSRF milik TTE. Nilainya berganti tiap request
     * dan divalidasi lewat session, jadi harus di-scrape ulang tiap POST.
     */
    public string $tokenField = 'csrf_token_tte';

    /**
     * Role yang boleh direset kata sandinya. Dicocokkan tanpa peduli
     * huruf besar/kecil terhadap teks pada baris hasil pencarian.
     */
    public string $targetRole = 'penandatangan';

    /**
     * Timeout tiap request dalam detik.
     */
    public int $timeout = 30;

    /**
     * User-Agent yang dipakai agar request tampak seperti browser biasa.
     */
    public string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Pertukangan-TukangAdmin';

    /**
     * Verifikasi sertifikat SSL host TTE. Biarkan true; jadikan false hanya
     * bila host memakai sertifikat yang tidak terpercaya di lingkungan lokal.
     */
    public bool $verifySsl = true;
}
