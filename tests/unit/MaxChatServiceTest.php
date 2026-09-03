<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangKirim\Config\MaxChat;
use Modules\TukangKirim\Libraries\MaxChatService;

/**
 * @internal
 */
final class MaxChatServiceTest extends CIUnitTestCase
{
    private function service(array $overrides = []): MaxChatService
    {
        $config = new MaxChat();

        foreach ($overrides as $key => $value) {
            $config->{$key} = $value;
        }

        return new MaxChatService($config);
    }

    /**
     * @dataProvider nomorProvider
     */
    public function testNormalisasiNomor(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->service()->normalizeNumber($input));
    }

    public static function nomorProvider(): array
    {
        return [
            'lokal 0'          => ['08123456789', '628123456789'],
            'sudah 62'         => ['628123456789', '628123456789'],
            'plus 62'          => ['+628123456789', '628123456789'],
            'pakai spasi/strip' => ['0812-3456-789', '628123456789'],
            'awalan 00'        => ['00628123456789', '628123456789'],
            'tanpa awalan'     => ['8123456789', '628123456789'],
            'kosong'           => ['', ''],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function account(string $baseUrl, string $token = 'token-uji-1234'): array
    {
        return ['id' => 1, 'name' => 'Akun Uji', 'base_url' => $baseUrl, 'token' => $token];
    }

    public function testEndpointDibangunDariBaseUrlAkun(): void
    {
        $account = $this->account('https://core.maxchat.id/diskominfo3/api/');

        $this->assertSame(
            'https://core.maxchat.id/diskominfo3/api/messages',
            $this->service()->endpointFor($account),
        );
    }

    public function testModeUjiCobaTidakMengirim(): void
    {
        $result = $this->service(['dryRun' => true])
            ->sendTextVia($this->account('https://core.maxchat.id/diskominfo/api'), '628123456789', 'Halo');

        $this->assertSame(MaxChatService::STATUS_DRYRUN, $result['status']);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['http_code']);
        $this->assertStringContainsString('"type": "text"', (string) $result['body']);
    }

    public function testTanpaTokenLangsungGagalTanpaRequest(): void
    {
        $account = $this->account('https://core.maxchat.id/diskominfo/api', 'ISI_TOKEN_DISINI');
        $result  = $this->service(['dryRun' => false])->sendTextVia($account, '628123456789', 'Halo');

        $this->assertFalse($result['ok']);
        $this->assertSame(MaxChatService::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('Token akun "Akun Uji" belum diisi', (string) $result['error']);
    }
}
