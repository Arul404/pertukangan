<?php

namespace Modules\TukangKirim\Models;

use CodeIgniter\Model;

class MaxchatAccountModel extends Model
{
    protected $table      = 'maxchat_accounts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // Kolom jejak pemakaian (last_used_at, last_status, last_error) sengaja tidak
    // ikut di sini: nilainya hanya boleh ditulis oleh touch(), bukan oleh form CRUD.
    protected $allowedFields = ['name', 'base_url', 'token', 'description', 'is_active'];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        // `id` hanya dipakai sebagai placeholder {id} pada aturan is_unique saat update.
        'id'        => 'permit_empty|is_natural_no_zero',
        'name'      => 'required|min_length[3]|max_length[100]|is_unique[maxchat_accounts.name,id,{id}]',
        'base_url'  => 'required|valid_url_strict|max_length[255]',
        'token'     => 'required|min_length[8]|max_length[255]',
        'is_active' => 'permit_empty|in_list[0,1]',
    ];

    protected $validationMessages = [
        'name' => [
            'required'   => 'Nama akun wajib diisi.',
            'min_length' => 'Nama akun minimal 3 karakter.',
            'is_unique'  => 'Nama akun ini sudah dipakai.',
        ],
        'base_url' => [
            'required'         => 'Base URL wajib diisi.',
            'valid_url_strict' => 'Base URL harus lengkap dengan http:// atau https://.',
        ],
        'token' => [
            'required'   => 'Token wajib diisi.',
            'min_length' => 'Token terlalu pendek, minimal 8 karakter.',
        ],
    ];

    /**
     * Antrean rotasi: akun aktif, yang paling lama tidak dipakai lebih dulu.
     *
     * MySQL menempatkan NULL paling awal pada pengurutan ASC, jadi akun yang
     * belum pernah dipakai otomatis mendapat giliran pertama.
     *
     * @return list<array<string, mixed>>
     */
    public function rotationQueue(): array
    {
        return $this->where('is_active', 1)
            ->orderBy('last_used_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Catat hasil pemakaian sebuah akun.
     *
     * Dipanggil untuk SETIAP percobaan — termasuk yang gagal — supaya akun
     * bermasalah turun ke ekor antrean dan tidak dicoba paling depan lagi.
     *
     * @param bool $advanceRotation false untuk Tes Koneksi: statusnya diperbarui,
     *                              tetapi gilirannya dalam rotasi tidak digeser.
     */
    public function touch(int $id, string $status, ?string $error, bool $advanceRotation = true): void
    {
        $data = [
            'last_status' => $status,
            'last_error'  => $error,
        ];

        if ($advanceRotation) {
            $data['last_used_at'] = date('Y-m-d H:i:s');
        }

        // Lewat builder, bukan update() milik model: kolom jejak ini di luar
        // $allowedFields dan tidak perlu melewati validasi.
        $this->db->table($this->table)->where('id', $id)->update($data);
    }

    public function activeCount(): int
    {
        return $this->where('is_active', 1)->countAllResults();
    }

    /**
     * Daftar ringkas untuk dropdown filter riwayat.
     *
     * @return list<array<string, mixed>>
     */
    public function forFilter(): array
    {
        return $this->select('id, name')->orderBy('name', 'ASC')->findAll();
    }
}
