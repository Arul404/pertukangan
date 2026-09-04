<?php

namespace Modules\TukangKirim\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\TukangKirim\Models\WorkerSettingsModel;

/**
 * Minta worker antrean berhenti dengan rapi.
 *
 * Menyetel flag stop_requested; daemon yang sedang berjalan membacanya pada
 * iterasi berikutnya, menyelesaikan pesan yang sedang diproses, lalu keluar.
 * Dipakai oleh worker-stop.bat maupun tombol di halaman Worker.
 */
class WorkerStop extends BaseCommand
{
    protected $group       = 'TukangKirim';
    protected $name        = 'tukangkirim:stop';
    protected $description = 'Minta worker antrean berhenti dengan rapi (set flag stop).';

    public function run(array $params): int
    {
        (new WorkerSettingsModel())->save1(['stop_requested' => 1]);

        CLI::write('Permintaan berhenti dikirim. Worker akan keluar setelah pesan berjalan selesai.', 'yellow');

        return EXIT_SUCCESS;
    }
}
