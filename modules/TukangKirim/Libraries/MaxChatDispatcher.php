<?php

namespace Modules\TukangKirim\Libraries;

use Modules\TukangKirim\Models\MaxchatAccountModel;

/**
 * Pemilih akun dan mitigasi gagal kirim.
 *
 * Rotasi: giliran jatuh ke akun aktif yang paling lama tidak dipakai
 * (round-robin lewat kolom `last_used_at`).
 *
 * Mitigasi: bila sebuah akun gagal, akun aktif berikutnya langsung dicoba di
 * dalam request yang sama sampai ada yang berhasil atau antrean habis. Setiap
 * percobaan dikembalikan lewat `attempts` supaya pemanggil dapat mencatatnya
 * satu per satu ke riwayat.
 *
 * Rotasi sengaja tidak dikunci secara transaksional: aplikasi ini dipakai satu
 * operator secara lokal, dan dua request bersamaan paling banter membuat
 * distribusi sedikit timpang — bukan kesalahan kirim.
 */
class MaxChatDispatcher
{
    protected MaxchatAccountModel $accounts;
    protected MaxChatService $service;

    public function __construct(?MaxchatAccountModel $accounts = null, ?MaxChatService $service = null)
    {
        $this->accounts = $accounts ?? new MaxchatAccountModel();
        $this->service  = $service ?? new MaxChatService();
    }

    /**
     * Akun terdepan di antrean tanpa mengonsumsi gilirannya, untuk ditampilkan
     * di halaman preview.
     *
     * @return array<string, mixed>|null
     */
    public function peek(): ?array
    {
        return $this->accounts->rotationQueue()[0] ?? null;
    }

    /**
     * Kirim satu pesan, berpindah akun bila gagal.
     *
     * @return array{
     *     ok: bool,
     *     final: array{status: string, ok: bool, http_code: int|null, body: string|null, error: string|null},
     *     account: array<string, mixed>|null,
     *     attempts: list<array{account: array<string, mixed>, result: array<string, mixed>}>
     * }
     */
    public function send(string $to, string $text): array
    {
        $queue = $this->accounts->rotationQueue();

        if ($queue === []) {
            return [
                'ok'       => false,
                'final'    => $this->noAccountFailure(),
                'account'  => null,
                'attempts' => [],
            ];
        }

        $attempts = [];
        $result   = $this->noAccountFailure();
        $account  = null;

        foreach ($queue as $candidate) {
            $account = $candidate;
            $result  = $this->service->sendTextVia($candidate, $to, $text);

            $this->accounts->touch((int) $candidate['id'], $result['status'], $result['error']);

            $attempts[] = ['account' => $candidate, 'result' => $result];

            if ($result['ok']) {
                break;
            }
        }

        return [
            'ok'       => $result['ok'],
            'final'    => $result,
            'account'  => $account,
            'attempts' => $attempts,
        ];
    }

    /**
     * Kirim lewat satu akun tertentu tanpa rotasi maupun failover — dipakai
     * tombol Tes Koneksi. Selalu benar-benar mengirim, termasuk saat mode uji
     * coba aktif, karena tes yang requestnya dilewati tidak membuktikan apa pun.
     *
     * @param array<string, mixed> $account
     *
     * @return array{status: string, ok: bool, http_code: int|null, body: string|null, error: string|null}
     */
    public function sendVia(array $account, string $to, string $text): array
    {
        $result = $this->service->sendTextVia($account, $to, $text, true);

        // Giliran rotasi tidak digeser: tes tidak boleh mengubah urutan antrean.
        $this->accounts->touch((int) $account['id'], $result['status'], $result['error'], false);

        return $result;
    }

    public function service(): MaxChatService
    {
        return $this->service;
    }

    /**
     * @return array{status: string, ok: bool, http_code: null, body: null, error: string}
     */
    protected function noAccountFailure(): array
    {
        return [
            'status'    => MaxChatService::STATUS_FAILED,
            'ok'        => false,
            'http_code' => null,
            'body'      => null,
            'error'     => 'Belum ada akun MaxChat aktif. Tambahkan akun di menu Akun MaxChat.',
        ];
    }
}
