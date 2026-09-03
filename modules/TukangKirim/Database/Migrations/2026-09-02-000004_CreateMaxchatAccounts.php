<?php

namespace Modules\TukangKirim\Database\Migrations;

use CodeIgniter\Database\Migration;
use Modules\TukangKirim\Config\MaxChat as MaxChatConfig;

class CreateMaxchatAccounts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 100],
            // Tanpa segmen "/messages" — tiap akun punya segmen path sendiri,
            // mis. .../diskominfo/api dan .../diskominfo3/api.
            'base_url'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'token'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'is_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            // Kunci rotasi round-robin: giliran jatuh ke akun yang paling lama tidak dipakai.
            'last_used_at' => ['type' => 'DATETIME', 'null' => true],
            'last_status'  => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'last_error'   => ['type' => 'TEXT', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->addKey(['is_active', 'last_used_at']);
        $this->forge->createTable('maxchat_accounts');

        $this->seedFromEnv();
    }

    public function down(): void
    {
        $this->forge->dropTable('maxchat_accounts', true);
    }

    /**
     * Pindahkan akun tunggal yang selama ini hidup di .env ke dalam tabel,
     * supaya instalasi yang sudah berjalan tidak kehilangan kemampuan kirim
     * begitu migrasi ini dijalankan.
     */
    protected function seedFromEnv(): void
    {
        $config = config(MaxChatConfig::class);
        $token  = trim($config->token);

        if ($token === '' || $token === 'ISI_TOKEN_DISINI') {
            return;
        }

        $builder = $this->db->table('maxchat_accounts');

        if ($builder->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $builder->insert([
            'name'        => 'Akun Utama',
            'base_url'    => rtrim($config->baseURL, '/'),
            'token'       => $token,
            'description' => 'Dipindahkan otomatis dari maxchat.token di berkas .env.',
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
}
