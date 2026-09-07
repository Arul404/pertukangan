<?php

namespace Modules\TukangKirim\Models;

use CodeIgniter\Model;

/**
 * Antrean pesan keluar MaxChat (lihat migrasi CreateMessageQueue).
 *
 * Worker tunggal (spark tukangkirim:work) mengambil baris paling lama yang sudah
 * boleh diproses, mengirimnya, mencatat ke message_logs, lalu menghapus barisnya.
 *
 * Daur hidup baris yatim: bila worker mati di antara claimNext() dan drop(),
 * barisnya tertinggal berstatus `processing` dan tak akan diambil siapa pun lagi
 * (claimNext hanya melihat `queued`). Baris seperti itu dipulihkan lewat
 * {@see self::orphans()} + {@see self::requeue()}, yang dipanggil
 * SendQueueService::reclaimOrphans() saat kunci worker baru diperoleh.
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

    /**
     * Baris yang tertinggal berstatus `processing` — yatim.
     *
     * Hanya benar bila pemanggil sedang memegang GET_LOCK worker: saat itu tidak
     * ada proses lain yang mengirim, jadi setiap baris `processing` pasti sisa
     * proses yang mati di tengah jalan. Tanpa kunci itu, hasilnya bisa memuat
     * baris yang sedang benar-benar diproses worker lain.
     *
     * @return list<array<string, mixed>>
     */
    public function orphans(): array
    {
        return $this->where('status', 'processing')->orderBy('id', 'ASC')->findAll();
    }

    /**
     * Kembalikan satu baris yatim ke antrean dan naikkan hitungan percobaannya.
     *
     * @return int nilai `attempts` yang baru
     */
    public function requeue(int $id): int
    {
        $now      = date('Y-m-d H:i:s');
        $attempts = (int) ($this->find($id)['attempts'] ?? 0) + 1;

        $this->update($id, [
            'status'       => 'queued',
            'attempts'     => $attempts,
            'locked_at'    => null,
            'available_at' => $now,
            'updated_at'   => $now,
        ]);

        return $attempts;
    }

    /** Jumlah pesan yang masih menunggu/di-proses. */
    public function pendingCount(): int
    {
        return $this->whereIn('status', ['queued', 'processing'])->countAllResults();
    }
}
