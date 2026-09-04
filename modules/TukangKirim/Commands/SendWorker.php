<?php

namespace Modules\TukangKirim\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Modules\TukangKirim\Config\MaxChat as MaxChatConfig;
use Modules\TukangKirim\Libraries\SendQueueService;
use Throwable;

/**
 * Worker antrean kirim MaxChat.
 *
 * Menguras tabel message_queue satu-per-satu dengan jeda antar-kirim (anti-
 * banned WhatsApp). Jalankan sebagai daemon:
 *
 *     php spark tukangkirim:work
 *
 * atau sekali-jalan (mis. dijadwalkan Task Scheduler tiap menit):
 *
 *     php spark tukangkirim:work --once
 *
 * Hanya satu instance yang boleh aktif; instance kedua akan keluar (kunci
 * MySQL GET_LOCK), jadi jadwal yang tumpang-tindih pun aman.
 */
class SendWorker extends BaseCommand
{
    protected $group       = 'TukangKirim';
    protected $name        = 'tukangkirim:work';
    protected $description  = 'Kirim pesan MaxChat dari antrean satu-per-satu dengan jeda (anti-banned).';
    protected $usage       = 'tukangkirim:work [--once]';
    protected $options     = ['--once' => 'Kuras antrean yang ada lalu keluar (untuk penjadwalan cron).'];

    protected const LOCK_NAME = 'tukangkirim_send_worker';

    public function run(array $params): int
    {
        $once   = array_key_exists('once', $params) || in_array('--once', $params, true);
        $config = config(MaxChatConfig::class);
        $db     = Database::connect();

        // Kunci instance-tunggal: worker kedua langsung keluar.
        $locked = $db->query('SELECT GET_LOCK(?, 0) AS l', [self::LOCK_NAME])->getRow();

        if ((int) ($locked->l ?? 0) !== 1) {
            CLI::error('Worker lain sedang berjalan. Keluar.');

            return EXIT_ERROR;
        }

        $service = new SendQueueService();

        CLI::write(($once ? 'Menguras antrean (--once)' : 'Worker antrean berjalan')
            . '. Jeda antar-kirim ' . $config->sendMinInterval . '–'
            . ($config->sendMinInterval + $config->sendJitter) . ' detik. Ctrl+C untuk berhenti.', 'green');

        try {
            while (true) {
                $summary = $this->processSafely($service);

                if ($summary === null) {
                    if ($once) {
                        CLI::write('Antrean kosong. Selesai.', 'green');
                        break;
                    }

                    sleep(max(1, $config->queueIdleSleep));
                    continue;
                }

                $tag = $summary['ok'] ? CLI::color('OK', 'green') : CLI::color('GAGAL', 'red');
                CLI::write(sprintf('[%s] id=%d -> %s (%s)', $tag, $summary['id'], $summary['recipient'], $summary['status']));

                // Pacing: beri jarak sebelum pesan berikutnya (kecuali dryrun).
                if ($summary['status'] !== 'dryrun') {
                    $wait = $config->sendMinInterval + random_int(0, max(0, $config->sendJitter));
                    CLI::write('  jeda ' . $wait . ' detik…', 'dark_gray');
                    sleep($wait);
                }
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }

        return EXIT_SUCCESS;
    }

    /**
     * @return array{id: int, ok: bool, recipient: string, status: string}|null
     */
    protected function processSafely(SendQueueService $service): ?array
    {
        try {
            return $service->processOne();
        } catch (Throwable $e) {
            // Jangan biarkan satu galat menjatuhkan worker; catat dan lanjut.
            CLI::error('Galat memproses antrean: ' . $e->getMessage());
            sleep(2);

            return ['id' => 0, 'ok' => false, 'recipient' => '-', 'status' => 'error'];
        }
    }
}
