<?php

namespace Modules\TukangAdmin\Libraries;

use RuntimeException;

/**
 * Kegagalan yang bisa diperkirakan saat memakai API portal BSrE: kredensial
 * salah, OTP salah, token kedaluwarsa, pengguna tidak ditemukan, sertifikat
 * tidak ada, atau bentuk balikan API berubah. Controller menangkapnya untuk
 * menampilkan pesan yang jelas ke operator, bukan stack trace.
 */
class BsreClientException extends RuntimeException
{
}
