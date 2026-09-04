<?php

namespace Modules\TukangKirim\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Setelan runtime worker antrean (satu baris, id=1).
 *
 * Memungkinkan operator menjeda/melanjutkan pengiriman dan menyetel jeda dari
 * halaman web tanpa menyentuh .env. Worker membaca baris ini tiap iterasi
 * sehingga perubahan langsung berlaku. `stop_requested` membuat daemon keluar
 * dengan rapi. Nilai jeda kosong = pakai default Config\MaxChat.
 */
class CreateWorkerSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'paused'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'stop_requested' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'min_interval'   => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'jitter'         => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'heartbeat_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('worker_settings');

        // Satu baris tetap.
        $this->db->table('worker_settings')->insert([
            'id'         => 1,
            'paused'     => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('worker_settings', true);
    }
}
