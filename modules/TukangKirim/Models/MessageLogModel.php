<?php

namespace Modules\TukangKirim\Models;

use CodeIgniter\Model;

class MessageLogModel extends Model
{
    protected $table         = 'message_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'template_id', 'maxchat_account_id', 'template_name', 'account_name',
        'recipient_input', 'recipient_normalized', 'message_masked',
        'has_password', 'status', 'http_code', 'api_response', 'error_message',
        'attempt_no',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $updatedField  = ''; // log tidak pernah diubah setelah dibuat

    /**
     * Query terfilter untuk halaman riwayat.
     *
     * @param array{status?: string|null, maxchat_account_id?: int|string|null, q?: string|null} $filters
     */
    public function filtered(array $filters = []): self
    {
        $builder = $this->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');

        if (! empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }

        if (! empty($filters['maxchat_account_id'])) {
            $builder->where('maxchat_account_id', (int) $filters['maxchat_account_id']);
        }

        if (! empty($filters['q'])) {
            $builder->groupStart()
                ->like('recipient_input', $filters['q'])
                ->orLike('recipient_normalized', $filters['q'])
                ->groupEnd();
        }

        return $builder;
    }
}
