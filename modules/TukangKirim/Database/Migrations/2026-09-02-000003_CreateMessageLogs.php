<?php

namespace Modules\TukangKirim\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMessageLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'category_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'template_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            // Snapshot nama, supaya log tetap terbaca bila kategori/template dihapus.
            'category_name'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'template_name'        => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'recipient_input'      => ['type' => 'VARCHAR', 'constraint' => 32],
            'recipient_normalized' => ['type' => 'VARCHAR', 'constraint' => 32],
            // Isi pesan dengan password sudah di-mask. Password asli tidak pernah disimpan.
            'message_masked' => ['type' => 'TEXT'],
            'has_password'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status'         => ['type' => 'ENUM', 'constraint' => ['success', 'failed', 'dryrun'], 'default' => 'failed'],
            'http_code'      => ['type' => 'INT', 'constraint' => 5, 'null' => true],
            'api_response'   => ['type' => 'TEXT', 'null' => true],
            'error_message'  => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('category_id', 'categories', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('template_id', 'templates', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('message_logs');
    }

    public function down(): void
    {
        $this->forge->dropTable('message_logs', true);
    }
}
