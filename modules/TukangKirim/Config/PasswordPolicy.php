<?php

namespace Modules\TukangKirim\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Aturan pembuatan password acak untuk placeholder [password].
 *
 * @see \Modules\TukangKirim\Libraries\PasswordGenerator
 */
class PasswordPolicy extends BaseConfig
{
    /**
     * Panjang password yang di-generate. Tidak boleh kurang dari MIN_LENGTH.
     */
    public int $length = 12;

    /**
     * Batas bawah yang dipaksakan oleh kebutuhan: minimal 10 karakter.
     */
    public const MIN_LENGTH = 10;

    /**
     * Huruf kapital. Huruf ambigu (I, O) sengaja dibuang agar penerima tidak
     * salah baca saat mengetik ulang password dari pesan WhatsApp.
     */
    public string $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * Huruf kecil, tanpa l dan o.
     */
    public string $lower = 'abcdefghijkmnpqrstuvwxyz';

    /**
     * Angka, tanpa 0 dan 1.
     */
    public string $digits = '23456789';

    /**
     * Karakter spesial yang aman diketik ulang di keyboard ponsel.
     */
    public string $special = '!@#$%^&*?-_';
}
