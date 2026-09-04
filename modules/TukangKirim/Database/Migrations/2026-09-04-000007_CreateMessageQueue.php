<?php

namespace Modules\TukangKirim\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Antrean pesan keluar MaxChat.
 *
 * Pengiriman dipisah dari request web dan dijalankan satu-per-satu oleh worker
 * (spark tukangkirim:work) dengan jeda antar-kirim, supaya pesan tidak meluncur
 * berbarengan/beruntun yang berisiko diblokir WhatsApp. Baris dihapus segera
 * setelah diproses; jejak audit (tersamar) tetap di message_logs.
 *
 * `body` memuat teks pesan apa adanya (bisa berisi kata sandi) dan `secret`
 * memuat kata sandi untuk penyamaran log — keduanya hanya hidup sampai worker
 * memprosesnya, lalu barisnya dihapus.
 */
class CreateMessageQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'recipient'    => ['type' => 'VARCHAR', 'constraint' => 32],
            'body'         => ['type' => 'TEXT'],
            // Kata sandi untuk penyamaran log (opsional). Dihapus bersama baris.
            'secret'       => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            // Field log tetap (JSON): template_id, template_name, recipient_input,
            // recipient_normalized, message_masked, has_password.
            'log_context'  => ['type' => 'TEXT'],
            // queued | processing | failed (baris sukses langsung dihapus).
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'queued'],
            'attempts'     => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            // Boleh diproses mulai kapan (untuk pacing/penjadwalan).
            'available_at' => ['type' => 'DATETIME', 'null' => true],
            'locked_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'available_at']);
        $this->forge->createTable('message_queue');
    }

    public function down(): void
    {
        $this->forge->dropTable('message_queue', true);
    }
}
