<?php

namespace Modules\TukangKirim\Libraries;

use Modules\TukangKirim\Models\MessageLogModel;
use Modules\TukangKirim\Models\MessageQueueModel;

/**
 * Jembatan antara antrean dan pengiriman nyata.
 *
 * Controller memanggil {@see self::enqueue()} alih-alih mengirim langsung; worker
 * memanggil {@see self::processOne()} berulang (dengan jeda) untuk menguras
 * antrean satu-per-satu. Penyamaran kata sandi di log ditangani di sini memakai
 * `secret` yang ikut disimpan pada baris antrean, lalu barisnya dihapus.
 *
 * {@see self::reclaimOrphans()} membereskan baris yang ditinggalkan worker yang
 * mati di tengah kirim; panggil sekali setiap kunci worker baru diperoleh.
 */
class SendQueueService
{
    protected MessageQueueModel $queue;
    protected MessageLogModel $logs;
    protected MaxChatDispatcher $dispatcher;

    public function __construct(
        ?MessageQueueModel $queue = null,
        ?MessageLogModel $logs = null,
        ?MaxChatDispatcher $dispatcher = null
    ) {
        $this->queue      = $queue ?? new MessageQueueModel();
        $this->logs       = $logs ?? new MessageLogModel();
        $this->dispatcher = $dispatcher ?? new MaxChatDispatcher();
    }

    /**
     * Masukkan satu pesan ke antrean.
     *
     * @param array<string, mixed> $logContext template_id, template_name,
     *                                          recipient_input, recipient_normalized,
     *                                          message_masked, has_password.
     */
    public function enqueue(string $recipient, string $body, ?string $secret, array $logContext): int
    {
        return $this->queue->enqueue($recipient, $body, $secret, $logContext);
    }

    public function pending(): int
    {
        return $this->queue->pendingCount();
    }

    /**
     * Proses satu pesan berikutnya: kirim (dengan rotasi/failover akun), catat
     * ke message_logs, lalu hapus barisnya. Mengembalikan ringkasan, atau null
     * bila antrean kosong.
     *
     * @return array{id: int, ok: bool, recipient: string, status: string}|null
     */
    public function processOne(): ?array
    {
        $row = $this->queue->claimNext();

        if ($row === null) {
            return null;
        }

        $ctx       = json_decode((string) $row['log_context'], true) ?: [];
        $secret    = $row['secret'] ?? null;
        $recipient = (string) $row['recipient'];
        $outcome   = $this->dispatcher->send($recipient, (string) $row['body']);

        if ($outcome['attempts'] === []) {
            $this->logs->insert($this->logRow($ctx, $recipient, $outcome['final'], null, 1, $secret));
        } else {
            foreach ($outcome['attempts'] as $index => $attempt) {
                $this->logs->insert(
                    $this->logRow($ctx, $recipient, $attempt['result'], $attempt['account'], $index + 1, $secret),
                );
            }
        }

        // Baris dihapus apa pun hasilnya: teks + secret tidak boleh menetap, dan
        // audit (tersamar) sudah tercatat. Kegagalan terlihat di Riwayat Kirim.
        $this->queue->drop((int) $row['id']);

        return [
            'id'        => (int) $row['id'],
            'ok'        => (bool) $outcome['ok'],
            'recipient' => $recipient,
            'status'    => (string) $outcome['final']['status'],
        ];
    }

    /**
     * Pulihkan baris yang ditinggalkan worker yang mati di tengah pengiriman.
     *
     * WAJIB dipanggil hanya saat memegang GET_LOCK worker — lihat
     * {@see MessageQueueModel::orphans()} untuk alasannya.
     *
     * Baris yang belum pernah diulang dikembalikan ke antrean. Yang sudah pernah
     * dianggap beracun (pengiriman itu sendiri yang merobohkan worker): dicatat
     * ke riwayat sebagai gagal, lalu dihapus — bukan ditandai `failed` — supaya
     * kata sandi di kolom `secret` tidak menetap tanpa batas waktu.
     *
     * Catatan: mengembalikan baris ke antrean bisa menyebabkan kirim ganda bila
     * worker mati SESUDAH MaxChat menerima pesan tapi SEBELUM barisnya dihapus.
     * Itu diterima secara sadar: pesan yang tidak pernah tiba mengunci pengguna
     * (kata sandi TTE sudah terlanjur diubah), sedangkan pesan ganda hanya
     * membingungkan. Batas satu kali ulang membatasi paparannya.
     *
     * @param int $maxAttempts jumlah percobaan maksimum sebelum ditinggalkan
     *
     * @return array{requeued: int, abandoned: int}
     */
    public function reclaimOrphans(int $maxAttempts = 2): array
    {
        $requeued  = 0;
        $abandoned = 0;

        foreach ($this->queue->orphans() as $row) {
            $id      = (int) $row['id'];
            $attempt = (int) ($row['attempts'] ?? 0) + 1;

            if ($attempt < $maxAttempts) {
                $this->queue->requeue($id);
                $requeued++;

                continue;
            }

            $ctx = json_decode((string) $row['log_context'], true) ?: [];

            $this->logs->insert($this->logRow(
                $ctx,
                (string) $row['recipient'],
                [
                    'status'    => MaxChatService::STATUS_FAILED,
                    'http_code' => null,
                    'body'      => null,
                    // Sengaja menyebut kemungkinan "sudah terkirim": memang tidak
                    // bisa dipastikan, dan operator harus tahu ambiguitas itu.
                    'error'     => 'Pesan ditinggalkan: worker berhenti di tengah pengiriman sebanyak '
                        . $attempt . ' kali. Pesan mungkin sudah terkirim sebagian — periksa riwayat '
                        . 'dan konfirmasi ke penerima.',
                ],
                null,
                $attempt,
                $row['secret'] ?? null,
            ));

            $this->queue->drop($id);
            $abandoned++;
        }

        return ['requeued' => $requeued, 'abandoned' => $abandoned];
    }

    /**
     * Satu baris message_logs dari satu percobaan kirim.
     *
     * Dipakai processOne() (hasil nyata) dan reclaimOrphans() (hasil sintetis),
     * jadi penyamaran kata sandi hanya hidup di satu tempat.
     *
     * @param array<string, mixed>      $ctx     isi log_context baris antrean
     * @param array<string, mixed>      $res     hasil kirim: status, http_code, body, error
     * @param array<string, mixed>|null $account akun yang dipakai, null bila tidak ada
     * @param string|null               $secret  kata sandi yang harus disamarkan dari body
     *
     * @return array<string, mixed>
     */
    protected function logRow(array $ctx, string $recipient, array $res, ?array $account, int $no, ?string $secret): array
    {
        $body = $res['body'] ?? null;

        if ($secret !== null && $secret !== '' && $body !== null) {
            $body = str_replace($secret, str_repeat('*', 8), $body);
        }

        return [
            'template_id'          => $ctx['template_id'] ?? null,
            'maxchat_account_id'   => $account !== null ? (int) $account['id'] : null,
            'template_name'        => $ctx['template_name'] ?? null,
            'account_name'         => $account['name'] ?? null,
            'recipient_input'      => $ctx['recipient_input'] ?? $recipient,
            'recipient_normalized' => $ctx['recipient_normalized'] ?? $recipient,
            'message_masked'       => $ctx['message_masked'] ?? null,
            'has_password'         => $ctx['has_password'] ?? 0,
            'status'               => $res['status'],
            'http_code'            => $res['http_code'],
            'api_response'         => $body,
            'error_message'        => $res['error'],
            'attempt_no'           => $no,
        ];
    }
}
