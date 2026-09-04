<?php

namespace Modules\TukangKirim\Models;

use CodeIgniter\Model;
use Modules\TukangKirim\Config\MaxChat as MaxChatConfig;

/**
 * Setelan runtime worker antrean (baris tunggal id=1).
 *
 * Dibaca worker tiap iterasi (jeda/lanjut, jeda antar-kirim, permintaan stop)
 * dan diperbarui dari halaman Worker. Nilai jeda kosong = pakai Config\MaxChat.
 */
class WorkerSettingsModel extends Model
{
    protected $table         = 'worker_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'paused', 'stop_requested', 'min_interval', 'jitter', 'heartbeat_at', 'updated_at',
    ];

    /** Baris setelan, dibuat dengan default bila belum ada. */
    public function current(): array
    {
        $row = $this->find(1);

        if ($row === null) {
            // Builder langsung: allowedFields tidak memuat `id`, jadi Model::insert
            // akan membuangnya. INSERT IGNORE aman bila baris ternyata sudah ada.
            $this->db->table($this->table)->ignore(true)->insert([
                'id'         => 1,
                'paused'     => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $row = $this->find(1);
        }

        return $row ?? [
            'id'             => 1,
            'paused'         => 0,
            'stop_requested' => 0,
            'min_interval'   => null,
            'jitter'         => null,
            'heartbeat_at'   => null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save1(array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->current();               // pastikan baris ada
        $this->update(1, $data);
    }

    /** Catat denyut worker (dipakai untuk indikator "aktif"). */
    public function heartbeat(): void
    {
        $this->current();
        $this->update(1, ['heartbeat_at' => date('Y-m-d H:i:s')]);
    }

    /** Jeda minimum efektif (override DB, jika ada; jika tidak, dari config). */
    public function effectiveMinInterval(MaxChatConfig $config): int
    {
        $v = $this->current()['min_interval'];

        return $v !== null ? (int) $v : $config->sendMinInterval;
    }

    /** Jitter efektif (override DB, jika ada; jika tidak, dari config). */
    public function effectiveJitter(MaxChatConfig $config): int
    {
        $v = $this->current()['jitter'];

        return $v !== null ? (int) $v : $config->sendJitter;
    }
}
