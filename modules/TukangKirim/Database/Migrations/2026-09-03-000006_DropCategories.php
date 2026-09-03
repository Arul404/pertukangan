<?php

namespace Modules\TukangKirim\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Kategori dihapus: template berdiri sendiri, tanpa pengelompokan.
 */
class DropCategories extends Migration
{
    public function up(): void
    {
        // Foreign key dilepas dulu, baru kolomnya boleh dibuang.
        $this->forge->dropForeignKey('message_logs', 'message_logs_category_id_foreign');
        $this->forge->dropColumn('message_logs', ['category_id', 'category_name']);

        $this->forge->dropForeignKey('templates', 'templates_category_id_foreign');
        $this->forge->dropColumn('templates', 'category_id');

        $this->forge->dropTable('categories', true);
    }

    /**
     * Rollback hanya mengembalikan strukturnya. Isi tabel categories dan nilai
     * category_id/category_name di baris lama sudah hilang dan tidak bisa
     * dipulihkan, jadi kolomnya sengaja dibuat null tanpa foreign key.
     */
    public function down(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'is_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('categories');

        $this->forge->addColumn('templates', [
            'category_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'id'],
        ]);

        $this->forge->addColumn('message_logs', [
            'category_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'id'],
            'category_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'account_name'],
        ]);
    }
}
