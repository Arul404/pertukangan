<?php

namespace Modules\TukangKirim\Libraries;

use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Services;
use Modules\TukangKirim\Config\MaxChat as MaxChatConfig;
use Throwable;

/**
 * Pembungkus tipis untuk endpoint kirim pesan MaxChat.
 *
 * Setara dengan perintah:
 *   curl -X POST 'https://core.maxchat.id/diskominfo/api/messages' \
 *     -H 'accept: application/json' \
 *     -H 'Authorization: Bearer <token>' \
 *     -H 'Content-Type: application/json' \
 *     -d '{"to": "...", "type": "text", "text": "..."}'
 *
 * Kelas ini tidak memiliki kredensial: base URL dan token datang per-panggilan
 * dari satu baris tabel `maxchat_accounts`. Pemilihan akun dan failover adalah
 * urusan {@see MaxChatDispatcher}. Config MaxChat hanya menyumbang setelan
 * global: timeout, dryRun, dan countryCode.
 */
class MaxChatService
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_DRYRUN  = 'dryrun';

    protected MaxChatConfig $config;

    public function __construct(?MaxChatConfig $config = null)
    {
        $this->config = $config ?? config(MaxChatConfig::class);
    }

    public function isDryRun(): bool
    {
        return $this->config->dryRun;
    }

    /**
     * @param array<string, mixed> $account Satu baris tabel `maxchat_accounts`.
     */
    public function hasToken(array $account): bool
    {
        $token = trim((string) ($account['token'] ?? ''));

        return $token !== '' && $token !== 'ISI_TOKEN_DISINI';
    }

    /**
     * @param array<string, mixed> $account Satu baris tabel `maxchat_accounts`.
     */
    public function endpointFor(array $account): string
    {
        return rtrim((string) ($account['base_url'] ?? ''), '/') . '/messages';
    }

    /**
     * Kirim satu pesan teks lewat satu akun tertentu.
     *
     * @param array<string, mixed> $account   Satu baris tabel `maxchat_accounts`.
     * @param bool                 $forceSend Abaikan mode uji coba dan benar-benar
     *                                        kirim. Dipakai Tes Koneksi, yang tidak
     *                                        menguji apa pun bila requestnya dilewati.
     *
     * @return array{status: string, ok: bool, http_code: int|null, body: string|null, error: string|null}
     */
    public function sendTextVia(array $account, string $to, string $text, bool $forceSend = false): array
    {
        $payload = [
            'to'   => $to,
            'type' => 'text',
            'text' => $text,
        ];

        if ($this->isDryRun() && ! $forceSend) {
            return [
                'status'    => self::STATUS_DRYRUN,
                'ok'        => true,
                'http_code' => null,
                'body'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'error'     => null,
            ];
        }

        if (! $this->hasToken($account)) {
            return $this->failure('Token akun "' . ($account['name'] ?? '?') . '" belum diisi.');
        }

        try {
            $client = Services::curlrequest([
                'timeout'     => $this->config->timeout,
                'http_errors' => false, // status 4xx/5xx dikembalikan, bukan dilempar sebagai exception
            ], null, null, false);

            $response = $client->request('POST', $this->endpointFor($account), [
                'headers' => [
                    'accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $account['token'],
                ],
                'json' => $payload,
            ]);

            $code = $response->getStatusCode();
            $body = $response->getBody();

            return [
                'status'    => $code >= 200 && $code < 300 ? self::STATUS_SUCCESS : self::STATUS_FAILED,
                'ok'        => $code >= 200 && $code < 300,
                'http_code' => $code,
                'body'      => is_string($body) ? $body : '',
                'error'     => $code >= 200 && $code < 300 ? null : 'MaxChat membalas dengan HTTP ' . $code . '.',
            ];
        } catch (HTTPException $e) {
            return $this->failure('Gagal menghubungi MaxChat: ' . $e->getMessage());
        } catch (Throwable $e) {
            return $this->failure('Terjadi kesalahan saat mengirim: ' . $e->getMessage());
        }
    }

    /**
     * Normalisasi nomor tujuan ke format internasional tanpa tanda plus.
     * 0812xxx / +62812xxx / 62-812-xxx / 812xxx  ->  62812xxx
     */
    public function normalizeNumber(string $input): string
    {
        $cc     = $this->config->countryCode;
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $cc)) {
            return $digits;
        }

        // Nomor lokal diawali 0 -> ganti dengan kode negara.
        if (str_starts_with($digits, '0')) {
            return $cc . ltrim($digits, '0');
        }

        return $cc . $digits;
    }

    /**
     * Normalisasi sekaligus pastikan hasilnya nomor seluler Indonesia yang wajar.
     *
     * Mengembalikan null bila tidak lolos — mencegah MaxChat membalas 503 "Error
     * send message" gara-gara nomor cacat (mis. hasil ekstraksi tabel TTE yang
     * tergabung dengan angka kolom sebelah). Polanya dibangun dari countryCode
     * supaya aturan ini tetap menempel pada normaliser yang jadi sandarannya.
     */
    public function normalizedMobile(string $input): ?string
    {
        $normalized = $this->normalizeNumber($input);
        $pattern    = '/^' . preg_quote($this->config->countryCode, '/') . '8[1-9]\d{7,10}$/';

        return preg_match($pattern, $normalized) === 1 ? $normalized : null;
    }

    /**
     * @return array{status: string, ok: bool, http_code: null, body: null, error: string}
     */
    protected function failure(string $message): array
    {
        return [
            'status'    => self::STATUS_FAILED,
            'ok'        => false,
            'http_code' => null,
            'body'      => null,
            'error'     => $message,
        ];
    }
}
