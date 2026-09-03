<?php

namespace Modules\TukangAdmin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabel penyimpan satu set kredensial login TTE.
 *
 * Aplikasi ini dipakai satu operator secara lokal, jadi cukup satu baris aktif.
 * Kata sandi TTE tidak bisa di-hash (harus dipakai login ulang oleh scraper),
 * jadi disimpan terenkripsi lewat service `encrypter` CI4; lihat
 * {@see \Modules\TukangAdmin\Models\TteCredentialModel}.
 */
class CreateTteCredentials extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'username'   => ['type' => 'VARCHAR', 'constraint' => 150],
            // Ciphertext base64 dari kata sandi; panjang bebas, TEXT agar aman.
            'password'   => ['type' => 'TEXT'],
            // Base URL khusus (opsional). Kosong = pakai Config\Tte::$baseURL.
            'base_url'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('tte_credentials');
    }

    public function down(): void
    {
        $this->forge->dropTable('tte_credentials', true);
    }
}
