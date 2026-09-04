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

        $ctx     = json_decode((string) $row['log_context'], true) ?: [];
        $secret  = $row['secret'] ?? null;
        $outcome = $this->dispatcher->send($row['recipient'], (string) $row['body']);

        $mask = static fn (?string $text): ?string => $secret !== null && $secret !== '' && $text !== null
            ? str_replace($secret, str_repeat('*', 8), $text)
            : $text;

        $mkRow = static fn (array $res, ?array $account, int $no): array => [
            'template_id'          => $ctx['template_id'] ?? null,
            'maxchat_account_id'   => $account !== null ? (int) $account['id'] : null,
            'template_name'        => $ctx['template_name'] ?? null,
            'account_name'         => $account['name'] ?? null,
            'recipient_input'      => $ctx['recipient_input'] ?? $row['recipient'],
            'recipient_normalized' => $ctx['recipient_normalized'] ?? $row['recipient'],
            'message_masked'       => $ctx['message_masked'] ?? null,
            'has_password'         => $ctx['has_password'] ?? 0,
            'status'               => $res['status'],
            'http_code'            => $res['http_code'],
            'api_response'         => $mask($res['body']),
            'error_message'        => $res['error'],
            'attempt_no'           => $no,
        ];

        if ($outcome['attempts'] === []) {
            $this->logs->insert($mkRow($outcome['final'], null, 1));
        } else {
            foreach ($outcome['attempts'] as $index => $attempt) {
                $this->logs->insert($mkRow($attempt['result'], $attempt['account'], $index + 1));
            }
        }

        // Baris dihapus apa pun hasilnya: teks + secret tidak boleh menetap, dan
        // audit (tersamar) sudah tercatat. Kegagalan terlihat di Riwayat Kirim.
        $this->queue->drop((int) $row['id']);

        return [
            'id'        => (int) $row['id'],
            'ok'        => (bool) $outcome['ok'],
            'recipient' => (string) $row['recipient'],
            'status'    => (string) $outcome['final']['status'],
        ];
    }
}
