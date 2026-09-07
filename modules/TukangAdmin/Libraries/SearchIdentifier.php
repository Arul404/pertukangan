<?php

namespace Modules\TukangAdmin\Libraries;

/**
 * Pengenal isian kolom cadangan: NIK (angka saja) atau email (mengandung @).
 *
 * Dipakai Reset Password (TTE) maupun Reset Passphrase (BSrE): pada kedua
 * halaman, kolom cadangan baru muncul setelah pencarian lewat nomor gagal, dan
 * operator tidak diminta memilih jenisnya. Deteksi di sisi klien hanya kosmetik;
 * inilah yang menentukan.
 */
final class SearchIdentifier
{
    /**
     * @return array{by: string, value: string}|array{error: string}
     */
    public static function detect(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ['error' => 'Isian NIK atau email masih kosong.'];
        }

        if (str_contains($raw, '@')) {
            if (! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                return ['error' => 'Alamat email tidak valid: "' . $raw . '".'];
            }

            return ['by' => 'email', 'value' => $raw];
        }

        $digits = (string) preg_replace('/[\s.\-]/', '', $raw);

        // Rentang 6–20, bukan tepat 16: baris TTE juga memuat NIP yang panjangnya
        // berbeda dari NIK, dan operator kadang memakai itu untuk menemukan akun.
        if (preg_match('/^\d{6,20}$/', $digits) === 1) {
            return ['by' => 'nik', 'value' => $digits];
        }

        return ['error' => 'Isian "' . $raw . '" tidak dikenali sebagai NIK (hanya angka) '
            . 'maupun email (mengandung @).'];
    }
}
