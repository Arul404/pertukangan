<?php

namespace Modules\TukangKirim\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->templates($now) as $template) {
            $exists = $this->db->table('templates')
                ->where('name', $template['name'])
                ->countAllResults() > 0;

            if (! $exists) {
                $this->db->table('templates')->insert($template);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function templates(string $now): array
    {
        $body = "Halo [nama],\n\n"
            . "Password akun [aplikasi] Anda telah direset oleh admin.\n\n"
            . "Username : [username]\n"
            . "Password : [password]\n\n"
            . "Mohon segera masuk dan ganti password Anda demi keamanan akun. "
            . "Jangan bagikan password ini kepada siapa pun.\n\n"
            . 'Terima kasih.';

        // Dipakai modul Tukang Admin: [email] & [password] diisi otomatis dari
        // hasil reset TTE (tidak ada isian manual).
        $tte = "Halo [nama],\n\n"
            . "Kata sandi akun TTE Kabupaten Magelang Anda telah direset oleh admin.\n\n"
            . "Email    : [email]\n"
            . "Password : [password]\n\n"
            . "Mohon segera masuk dan ganti kata sandi Anda demi keamanan akun. "
            . "Jangan bagikan kata sandi ini kepada siapa pun.\n\n"
            . 'Terima kasih.';

        return [
            [
                'name'       => 'Reset Password - Standar',
                'body'       => $body,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name'       => 'Reset Email & Password TTE',
                'body'       => $tte,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }
}
