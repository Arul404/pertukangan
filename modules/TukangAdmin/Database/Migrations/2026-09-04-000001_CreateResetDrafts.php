<?php

namespace Modules\TukangAdmin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Draft reset sekali-pakai, dipindah dari session ke DB agar aman-konkuren.
 *
 * Menyimpan draft di session (satu key tetap) membuat dua tab pada browser yang
 * sama saling menimpa draft dan mengunci sesi. Dengan menyimpannya di sini dan
 * mengembalikan `id` acak ke panel konfirmasi, tiap tab memegang draftnya
 * sendiri: dispatch hanya memproses draft yang persis ia tampilkan (sekali pakai
 * lewat kolom used_at), dan bagian lambat (scraping/API) tak lagi menyentuh
 * session. Lihat {@see \Modules\TukangAdmin\Models\ResetDraftModel}.
 */
class CreateResetDrafts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            // Token acak (hex) yang jadi kapabilitas untuk memproses draft ini.
            'id'         => ['type' => 'VARCHAR', 'constraint' => 64],
            // Pemisah antar fitur: 'tte' atau 'passphrase'.
            'kind'       => ['type' => 'VARCHAR', 'constraint' => 20],
            // Draft ter-serialisasi JSON.
            'payload'    => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'used_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at');
        $this->forge->createTable('reset_drafts');
    }

    public function down(): void
    {
        $this->forge->dropTable('reset_drafts', true);
    }
}
