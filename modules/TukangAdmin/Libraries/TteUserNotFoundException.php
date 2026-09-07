<?php

namespace Modules\TukangAdmin\Libraries;

/**
 * Pencarian di TTE tidak menemukan pengguna yang cocok.
 *
 * Dipisahkan dari {@see TteScraperException} karena kelas induk juga dipakai
 * untuk kegagalan transport (cURL gagal, TTE mati). Tanpa pemisahan ini,
 * jaringan putus akan tampil ke operator sebagai "tidak ketemu" dan menyuruhnya
 * mencoba NIK — padahal NIK pun akan gagal dengan sebab yang sama.
 *
 * Pemanggil lama tetap aman: kelas ini turunan TteScraperException, jadi blok
 * catch yang sudah ada (mis. ResetPassphrase::tteRow) tetap menangkapnya.
 */
class TteUserNotFoundException extends TteScraperException
{
}
