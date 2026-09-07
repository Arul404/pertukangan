<?php

namespace Modules\TukangAdmin\Libraries;

/**
 * Satu kata kunci mencocokkan lebih dari satu penandatangan.
 *
 * Kotak pencarian TTE (`?q=`) adalah teks bebas, jadi satu nilai bisa mengenai
 * beberapa baris. Memilih diam-diam yang pertama berarti berpotensi mereset kata
 * sandi — dan kini juga MENULIS nomor WhatsApp — ke orang yang salah. Untuk
 * operasi yang tidak bisa dibatalkan, berhenti dan minta kata kunci yang lebih
 * spesifik jauh lebih baik daripada menebak.
 */
class TteAmbiguousMatchException extends TteScraperException
{
}
