<?php

namespace Modules\TukangKirim\Models;

use CodeIgniter\Model;

class TemplateModel extends Model
{
    protected $table         = 'templates';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'body', 'is_active'];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'name'      => 'required|min_length[3]|max_length[150]',
        'body'      => 'required|min_length[5]',
        'is_active' => 'permit_empty|in_list[0,1]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Nama template wajib diisi.',
        ],
        'body' => [
            'required'   => 'Isi pesan wajib diisi.',
            'min_length' => 'Isi pesan terlalu pendek.',
        ],
    ];

    /**
     * Semua template, urut nama.
     *
     * @return list<array<string, mixed>>
     */
    public function allOrdered(): array
    {
        return $this->orderBy('name', 'ASC')->findAll();
    }

    /**
     * Template aktif, untuk dropdown form kirim.
     *
     * @return list<array<string, mixed>>
     */
    public function activeForSend(): array
    {
        return $this->where('is_active', 1)
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}
