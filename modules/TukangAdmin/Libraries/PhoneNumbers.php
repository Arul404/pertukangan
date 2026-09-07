<?php

namespace Modules\TukangAdmin\Libraries;

use Modules\TukangKirim\Config\MaxChat as MaxChatConfig;

/**
 * Aturan nomor HP yang dipakai bersama Reset Password (TTE) dan Reset Passphrase (BSrE).
 *
 * Dua-duanya menghadapi masalah yang sama: nomor yang diketik operator selalu
 * dipegang dalam bentuk ternormalisasi (62…), sementara sistem seberang menyimpan
 * bentuk lokal (08…). Membandingkannya sebagai teks membuat dua nomor yang SAMA
 * dianggap berbeda, dan mencarinya hanya dengan satu bentuk menghasilkan
 * "tidak ketemu" palsu. Karena aturannya identik di kedua modul, ia tinggal di
 * sini — bukan disalin dua kali dan berisiko menyimpang sendiri-sendiri.
 */
final class PhoneNumbers
{
    /**
     * Inti nomor tanpa kode negara maupun angka nol di depan (NSN).
     *
     * "081234567890", "6281234567890", "+62 812-3456-7890" -> "81234567890".
     */
    public static function nsn(?string $raw): string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $raw);

        return str_starts_with($digits, '62') ? substr($digits, 2) : ltrim($digits, '0');
    }

    /**
     * Apakah dua nomor merujuk nomor yang sama, apa pun formatnya?
     *
     * Nomor kosong/tak terbaca tidak pernah dianggap cocok — "tidak tahu" bukan
     * "sama", dan memperlakukannya sebagai cocok akan melewatkan review nomor.
     */
    public static function matches(?string $a, ?string $b): bool
    {
        $left = self::nsn($a);

        return $left !== '' && $left === self::nsn($b);
    }

    /**
     * Bentuk-bentuk nomor yang layak dicoba pada kotak pencarian sistem seberang.
     *
     * TTE menyimpan nomor dalam format lokal (085290382571) sementara kita
     * memegang bentuk ternormalisasi (6285290382571). Tanpa mencoba beberapa
     * bentuk, operator yang mengetik +62… akan dapat "tidak ketemu" palsu.
     *
     * @return list<string>
     */
    public static function variants(string $normalized): array
    {
        $cc    = (string) config(MaxChatConfig::class)->countryCode;
        $local = '0' . substr($normalized, strlen($cc));

        return array_values(array_unique([$local, $normalized, '+' . $normalized]));
    }
}
