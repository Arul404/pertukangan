<?php

namespace Modules\TukangKirim\Models;

use CodeIgniter\Model;

/**
 * Antrean pesan keluar MaxChat (lihat migrasi CreateMessageQueue).
 *
 * Worker tunggal (spark tukangkirim:work) mengambil baris paling lama yang sudah
 * boleh diproses, mengirimnya, mencatat ke message_logs, lalu menghapus barisnya.
 */
class MessageQueueModel extends Model
{
    protected $table         = 'message_queue';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'recipient', 'body', 'secret', 'log_context', 'status',
        'attempts', 'available_at', 'locked_at', 'created_at', 'updated_at',
    ];

    /**
     * Masukkan satu pesan ke antrean; kembalikan id-nya.
     *
     * @param array<string, mixed> $logContext Field log tetap (akan di-JSON-kan).
     */
    public function enqueue(string $recipient, string $body, ?string $secret, array $logContext): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->insert([
            'recipient'    => $recipient,
            'body'         => $body,
            'secret'       => $secret,
            'log_context'  => json_encode($logContext),
            'status'       => 'queued',
            'attempts'     => 0,
            'available_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], true);
    }

    /**
     * Ambil (klaim) satu pesan berikutnya yang siap diproses secara atomik.
     *
     * Update ter-guard (status='queued') memastikan hanya satu proses yang
     * memenangkan baris; worker dijalankan tunggal, ini lapis pengaman tambahan.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->where('status', 'queued')
            ->groupStart()
                ->where('available_at IS NULL')
                ->orWhere('available_at <=', $now)
            ->groupEnd()
            ->orderBy('id', 'ASC')
            ->first();

        if ($row === null) {
            return null;
        }

        $claimed = $this->builder()
            ->where('id', $row['id'])
            ->where('status', 'queued')
            ->update(['status' => 'processing', 'locked_at' => $now, 'updated_at' => $now]);

        if (! $claimed || $this->db->affectedRows() !== 1) {
            return null; // Diambil proses lain.
        }

        return $row;
    }

    /** Baris selesai (sukses): hapus agar teks/secret tidak menetap. */
    public function drop(int $id): void
    {
        $this->delete($id);
    }

    /** Tandai gagal permanen (audit kegagalan sudah tercatat di message_logs). */
    public function markFailed(int $id): void
    {
        $this->update($id, ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** Jumlah pesan yang masih menunggu/di-proses. */
    public function pendingCount(): int
    {
        return $this->whereIn('status', ['queued', 'processing'])->countAllResults();
    }
}
