<?php

namespace Modules\TukangKirim\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAccountToMessageLogs extends Migration
{
    public function up(): void
    {
        // Sengaja tanpa foreign key: nama akun sudah disimpan sebagai snapshot
        // (pola yang sama dengan category_name/template_name), jadi log tetap
        // terbaca setelah akun dihapus tanpa perlu constraint di tabel berisi data.
        $this->forge->addColumn('message_logs', [
            'maxchat_account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'template_id'],
            'account_name'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'maxchat_account_id'],
            // Percobaan ke berapa dalam satu pengiriman; > 1 berarti akun sebelumnya gagal.
            'attempt_no' => ['type' => 'TINYINT', 'constraint' => 2, 'unsigned' => true, 'default' => 1, 'after' => 'error_message'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('message_logs', ['maxchat_account_id', 'account_name', 'attempt_no']);
    }
}
