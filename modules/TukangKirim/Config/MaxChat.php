<?php

namespace Modules\TukangKirim\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi gateway MaxChat.
 *
 * Semua nilai di bawah ini dapat ditimpa lewat .env dengan prefix `maxchat.`
 * (contoh: `maxchat.token = 'xxx'`), sehingga token tidak pernah ikut ter-commit.
 *
 * Kredensial runtime TIDAK lagi diambil dari sini: base URL dan token tiap akun
 * tersimpan di tabel `maxchat_accounts` dan dikelola lewat menu Akun MaxChat.
 * Yang tersisa di sini adalah setelan global (timeout, dryRun, countryCode) plus
 * dua nilai warisan di bawah yang hanya dipakai sekali sebagai bibit akun pertama.
 */
class MaxChat extends BaseConfig
{
    /**
     * Warisan: base URL akun tunggal sebelum multi-akun. Hanya dibaca oleh
     * migrasi CreateMaxchatAccounts untuk membuat baris akun pertama.
     */
    public string $baseURL = 'https://core.maxchat.id/diskominfo/api';

    /**
     * Warisan: token akun tunggal sebelum multi-akun. Sama seperti $baseURL,
     * hanya dipakai migrasi sebagai nilai awal — bukan token yang dipakai kirim.
     */
    public string $token = '';

    /**
     * Timeout request dalam detik.
     */
    public int $timeout = 20;

    /**
     * Bila true, pesan tidak benar-benar dikirim ke MaxChat. Request dilewati
     * dan hasilnya dicatat sebagai status `dryrun`. Berguna untuk uji coba
     * alur tanpa membakar kuota pesan.
     */
    public bool $dryRun = false;

    /**
     * Kode negara default untuk normalisasi nomor lokal (08xx -> 628xx).
     */
    public string $countryCode = '62';

    // ---------------------------------------------------------------------
    // Antrean kirim (anti-banned)
    // ---------------------------------------------------------------------

    /**
     * Jeda MINIMUM antar pengiriman nyata oleh worker antrean (detik). Mencegah
     * pesan meluncur berbarengan/beruntun yang berisiko diblokir WhatsApp.
     */
    public int $sendMinInterval = 12;

    /**
     * Tambahan jeda acak 0..N detik pada tiap kirim (jitter) supaya pola tidak
     * kaku seperti bot. Jeda efektif = sendMinInterval + rand(0, sendJitter).
     */
    public int $sendJitter = 8;

    /**
     * Jeda worker saat antrean kosong sebelum mengecek lagi (detik).
     */
    public int $queueIdleSleep = 5;

    /**
     * Perintah PHP untuk meluncurkan worker dari tombol "Nyalakan" di web.
     *
     * Default 'php' mengandalkan PATH (biasanya cukup di Laragon). Bila server
     * web tidak menemukan php, isi path lengkap di .env, contoh:
     * `maxchat.phpBinary = 'C:\laragon\bin\php\php-8.2.30\php.exe'`.
     */
    public string $phpBinary = 'php';
}
