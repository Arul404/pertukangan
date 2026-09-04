<?php

namespace Modules\TukangAdmin\Models;

use CodeIgniter\Model;

/**
 * Penyimpan draft reset sekali-pakai (lihat migrasi CreateResetDrafts).
 *
 * Menggantikan draft-di-session agar aman saat beberapa tab/operator bekerja
 * bersamaan: {@see self::create()} membuat baris ber-id acak dan mengembalikan
 * id itu ke panel konfirmasi; {@see self::claim()} menukar id menjadi payload
 * secara atomik dan menandainya terpakai, sehingga tiap draft hanya bisa
 * diproses sekali dan hanya oleh tab yang menampilkannya.
 */
class ResetDraftModel extends Model
{
    protected $table            = 'reset_drafts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['id', 'kind', 'payload', 'created_at', 'used_at'];
    protected $useTimestamps    = false;

    /** Umur maksimum draft yang belum diproses (detik). */
    protected const TTL = 3600;

    /**
     * Simpan draft dan kembalikan id acak (kapabilitas) untuk memprosesnya.
     *
     * @param array<string, mixed> $payload
     */
    public function create(string $kind, array $payload): string
    {
        $this->purgeExpired();

        $id = bin2hex(random_bytes(24));

        $this->insert([
            'id'         => $id,
            'kind'       => $kind,
            'payload'    => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s'),
            'used_at'    => null,
        ]);

        return $id;
    }

    /**
     * Tukar id menjadi payload draft secara atomik dan tandai terpakai.
     *
     * Update ter-guard (used_at IS NULL) memastikan hanya satu pemanggil yang
     * menang bila tombol ditekan dua kali; sisanya mendapat null.
     *
     * @return array<string, mixed>|null Payload, atau null bila tak ada / sudah dipakai.
     */
    public function claim(string $kind, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $row = $this->where('id', $id)->where('kind', $kind)->first();

        if ($row === null || $row['used_at'] !== null) {
            return null;
        }

        // Klaim: hanya berhasil bila belum ada yang menandainya terpakai.
        $this->builder()
            ->where('id', $id)
            ->where('kind', $kind)
            ->where('used_at', null)
            ->update(['used_at' => date('Y-m-d H:i:s')]);

        if ($this->db->affectedRows() !== 1) {
            return null; // Kalah balapan dengan klik lain.
        }

        $payload = json_decode((string) $row['payload'], true);

        return is_array($payload) ? $payload : null;
    }

    /** Buang draft yang sudah terpakai atau kedaluwarsa agar tabel tetap kecil. */
    public function purgeExpired(): void
    {
        $this->builder()
            ->groupStart()
                ->where('used_at IS NOT NULL')
                ->orWhere('created_at <', date('Y-m-d H:i:s', time() - self::TTL))
            ->groupEnd()
            ->delete();
    }
}
