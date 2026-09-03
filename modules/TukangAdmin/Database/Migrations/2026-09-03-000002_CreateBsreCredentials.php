<?php

namespace Modules\TukangAdmin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabel penyimpan satu set kredensial login portal BSrE.
 *
 * Strukturnya identik dengan tte_credentials: dipakai satu operator secara
 * lokal, jadi cukup satu baris aktif. Kata sandi BSrE tidak bisa di-hash (harus
 * dipakai login ulang oleh klien API), jadi disimpan terenkripsi lewat service
 * `encrypter` CI4; lihat {@see \Modules\TukangAdmin\Models\BsreCredentialModel}.
 */
class CreateBsreCredentials extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'username'   => ['type' => 'VARCHAR', 'constraint' => 150],
            // Ciphertext base64 dari kata sandi; panjang bebas, TEXT agar aman.
            'password'   => ['type' => 'TEXT'],
            // Base URL khusus (opsional). Kosong = pakai Config\Bsre::$baseURL.
            'base_url'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('bsre_credentials');
    }

    public function down(): void
    {
        $this->forge->dropTable('bsre_credentials', true);
    }
}
