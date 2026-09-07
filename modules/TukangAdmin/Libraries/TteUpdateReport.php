<?php

namespace Modules\TukangAdmin\Libraries;

/**
 * Menerjemahkan balikan {@see TteScraper::updateWhatsapp()} jadi peringatan
 * yang layak dibaca operator.
 *
 * Menyimpan form ubah pengguna di TTE berarti mengirim ULANG seluruh kolomnya,
 * jadi ada dua hal yang selalu perlu dilaporkan dan gampang terlupa: hasil yang
 * tidak bisa dipastikan (halaman gagal dibaca ulang sesudah simpan), dan kolom
 * lain yang ikut bergeser. Dua-duanya bukan kegagalan — pemanggilnya tetap
 * lanjut — tetapi diamnya berarti kerusakan baru ketahuan berminggu kemudian.
 *
 * Tinggal di sini, bukan disalin di tiap controller, dengan alasan yang sama
 * seperti {@see PhoneNumbers}: aturannya identik di Reset Password (TTE) dan
 * Reset Passphrase (BSrE), dan dua salinan berarti dua tempat yang bisa
 * menyimpang sendiri-sendiri.
 */
final class TteUpdateReport
{
    /**
     * @param array{verified: bool, collateral: array<string, array{0: string, 1: string}>} $result
     *
     * @return list<string> Kosong bila tidak ada yang perlu diperingatkan.
     */
    public static function notes(array $result): array
    {
        $notes = [];

        if (! $result['verified']) {
            $notes[] = 'Perubahan terkirim, tetapi hasilnya tidak bisa dipastikan karena halaman TTE '
                . 'gagal dibaca ulang. Periksa manual bila perlu.';
        }

        foreach ($result['collateral'] as $field => [$before, $after]) {
            $notes[] = 'Kolom "' . $field . '" ikut berubah dari "' . $before . '" menjadi "' . $after
                . '" — periksa di TTE.';
        }

        return $notes;
    }
}
