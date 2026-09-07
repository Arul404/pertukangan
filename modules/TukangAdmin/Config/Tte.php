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

    /**
     * Kata kunci tombol "ubah kata sandi" pada baris /users.
     *
     * Ditulis sebagai string dipisah koma, bukan array, karena
     * BaseConfig::initEnvValue() hanya menimpa kunci array yang SUDAH ada —
     * lewat .env sebuah array tidak bisa ditambah isinya, sementara docblock
     * kelas ini menjanjikan semua nilai bisa ditimpa.
     */
    public string $passwordLinkNeedles = 'sandi,password';

    /**
     * Kata kunci tombol "ubah data pengguna" pada baris /users.
     *
     * Sebuah tautan dianggap tombol ubah HANYA bila cocok di sini DAN tidak
     * cocok dengan passwordLinkNeedles. Pada markup TTE saat ini labelnya
     * "UBAH" (href .../users/update/8250) sedangkan tombol sandi "KATA SANDI"
     * (href .../users/password/8250), jadi keduanya sudah terpisah sendirinya —
     * daftar tolak dipasang supaya pemisahan itu terjamin secara struktur, bukan
     * kebetulan.
     */
    public string $editLinkNeedles = 'ubah,edit';

    /**
     * Nama kolom nomor WhatsApp pada form ubah pengguna TTE. Form dicari lewat
     * nama kolom ini, bukan lewat pola URL, supaya tidak ada URL tulis yang
     * ditebak-tebak.
     */
    public string $whatsappField = 'nowhatsapp';

    /**
     * Bentuk nomor yang ditulis ke TTE: 'auto' mengikuti format nilai lama
     * (TTE menyimpan 085290382571, jadi jangan lawan konvensinya sendiri),
     * 'local' selalu 08…, 'international' selalu 62….
     */
    public string $whatsappFormat = 'auto';

    /**
     * Menyimpan form ubah berarti mengirim ULANG semua kolomnya. Bila true,
     * penyimpanan dibatalkan saat ada kolom yang nilainya tidak bisa dipastikan
     * dari markup (mis. <select> yang dipilih JavaScript) — tidak ada yang
     * dikirim, daripada berisiko menimpa data pengguna dengan nilai tebakan.
     */
    public bool $editStrictFields = true;
}
