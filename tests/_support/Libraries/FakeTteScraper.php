<?php

namespace Tests\Support\Libraries;

use Modules\TukangAdmin\Config\Tte as TteConfig;
use Modules\TukangAdmin\Libraries\TteScraper;
use Modules\TukangAdmin\Libraries\TteScraperException;

/**
 * TteScraper tanpa jaringan: `request()` diganti halaman kalengan.
 *
 * Dengan begini isi POST yang benar-benar akan dikirim ke TTE bisa diperiksa
 * kunci-per-kunci di unit test — termasuk membuktikan kontrak "bila ada yang
 * tidak beres, TIDAK ADA yang dikirim" lewat log panggilan.
 */
final class FakeTteScraper extends TteScraper
{
    /** @var list<array{method: string, url: string, post: array<string, string>|null}> */
    public array $calls = [];

    /** @var list<array{code?: int, body?: string, url?: string, throw?: string}> */
    private array $responses;

    /**
     * @param list<array{code?: int, body?: string, url?: string, throw?: string}> $responses
     *        Dikonsumsi berurutan; yang terakhir dipakai ulang bila habis.
     */
    public function __construct(array $responses, ?TteConfig $config = null)
    {
        parent::__construct($config ?? new TteConfig(), 'https://tte.magelangkab.go.id');
        $this->responses = $responses;
    }

    /** @return list<array<string, string>> body tiap POST, berurutan */
    public function posts(): array
    {
        $out = [];

        foreach ($this->calls as $call) {
            if ($call['method'] === 'POST') {
                $out[] = $call['post'] ?? [];
            }
        }

        return $out;
    }

    protected function request(string $method, string $url, ?array $post = null): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'post' => $post];

        $response = count($this->responses) > 1
            ? array_shift($this->responses)
            : ($this->responses[0] ?? ['code' => 200, 'body' => '']);

        if (isset($response['throw'])) {
            throw new TteScraperException($response['throw']);
        }

        return [
            'url'  => $response['url'] ?? $url,
            'code' => $response['code'] ?? 200,
            'body' => $response['body'] ?? '',
        ];
    }
}
