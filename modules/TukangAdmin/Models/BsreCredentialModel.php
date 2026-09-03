<?php

namespace Modules\TukangAdmin\Models;

use CodeIgniter\Model;
use Throwable;

/**
 * Satu set kredensial login portal BSrE (username/email + kata sandi).
 *
 * Kata sandi disimpan terenkripsi: enkripsi dilakukan di sini sebelum insert,
 * dekripsi lewat {@see self::plainPassword()} tepat saat klien hendak login.
 * Kolom `password` di tabel karena itu berisi ciphertext, bukan kata sandi asli.
 *
 * Polanya identik dengan {@see TteCredentialModel}; keduanya dibiarkan terpisah
 * agar kredensial TTE dan BSrE tidak pernah tercampur.
 */
class BsreCredentialModel extends Model
{
    protected $table         = 'bsre_credentials';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['username', 'password', 'base_url', 'is_active'];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'username' => 'required|max_length[150]',
        'password' => 'required',
    ];

    protected $validationMessages = [
        'username' => ['required' => 'Username/email wajib diisi.'],
        'password' => ['required' => 'Kata sandi wajib diisi.'],
    ];

    /**
     * Baris kredensial aktif, atau null bila belum ada.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return $this->where('is_active', 1)->orderBy('id', 'DESC')->first();
    }

    /**
     * Kata sandi asli hasil dekripsi dari sebuah baris.
     *
     * @param array<string, mixed> $row
     */
    public function plainPassword(array $row): string
    {
        return self::decrypt((string) ($row['password'] ?? ''));
    }

    /**
     * Apakah kunci enkripsi tersedia? Bila tidak, kata sandi terpaksa disimpan
     * apa adanya dan view kredensial menampilkan peringatan.
     */
    public static function hasEncryptionKey(): bool
    {
        try {
            $key = config('Encryption')->key ?? '';

            return $key !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Enkripsi kata sandi menjadi ciphertext base64.
     *
     * Bila service enkripsi tidak tersedia (kunci belum diset), kata sandi
     * disimpan sebagai base64 biasa dengan penanda "plain:" — tetap berfungsi
     * di lingkungan lokal, sambil view mengingatkan untuk menyetel kunci.
     */
    public static function encrypt(string $plain): string
    {
        try {
            return 'enc:' . base64_encode(service('encrypter')->encrypt($plain));
        } catch (Throwable) {
            return 'plain:' . base64_encode($plain);
        }
    }

    /**
     * Kebalikan dari {@see self::encrypt()}.
     */
    public static function decrypt(string $stored): string
    {
        if (str_starts_with($stored, 'plain:')) {
            return (string) base64_decode(substr($stored, 6), true);
        }

        if (str_starts_with($stored, 'enc:')) {
            try {
                return service('encrypter')->decrypt(base64_decode(substr($stored, 4), true));
            } catch (Throwable) {
                return '';
            }
        }

        // Nilai warisan tanpa penanda: anggap plaintext apa adanya.
        return $stored;
    }
}
