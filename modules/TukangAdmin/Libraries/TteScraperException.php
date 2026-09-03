<?php

namespace Modules\TukangAdmin\Libraries;

use RuntimeException;

/**
 * Kegagalan yang bisa diperkirakan saat scraping TTE: kredensial salah, akun
 * tidak ditemukan, role tidak cocok, atau form berubah. Controller menangkapnya
 * untuk menampilkan pesan yang jelas ke operator, bukan stack trace.
 */
class TteScraperException extends RuntimeException
{
}
