<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangKirim\Libraries\MaxChatDispatcher;
use Modules\TukangKirim\Libraries\MaxChatService;
use Modules\TukangKirim\Models\MaxchatAccountModel;

/**
 * Rotasi & failover diuji tanpa database dan tanpa HTTP: model dan service
 * digantikan stub lewat konstruktor dispatcher.
 *
 * @internal
 */
final class MaxChatDispatcherTest extends CIUnitTestCase
{
    /**
     * @param list<bool>           $outcomes tiap elemen = percobaan ke-n berhasil?
     * @param list<array<string, mixed>> $accounts
     */
    private function dispatcher(array $accounts, array $outcomes): array
    {
        $model = new class ($accounts) extends MaxchatAccountModel {
            /** @var list<array{id: int, status: string, advanced: bool}> */
            public array $touched = [];

            /** @param list<array<string, mixed>> $queue */
            public function __construct(private array $queue)
            {
                // Sengaja tidak memanggil parent::__construct(): stub ini tidak
                // pernah menyentuh database.
            }

            public function rotationQueue(): array
            {
                return $this->queue;
            }

            public function touch(int $id, string $status, ?string $error, bool $advanceRotation = true): void
            {
                $this->touched[] = ['id' => $id, 'status' => $status, 'advanced' => $advanceRotation];
            }
        };

        $service = new class ($outcomes) extends MaxChatService {
            /** @var list<string> nama akun yang benar-benar ditembak, berurutan */
            public array $calls = [];

            /** @param list<bool> $outcomes */
            public function __construct(private array $outcomes)
            {
                parent::__construct();
            }

            public function sendTextVia(array $account, string $to, string $text, bool $forceSend = false): array
            {
                $ok = array_shift($this->outcomes) ?? false;

                $this->calls[] = (string) $account['name'];

                return [
                    'status'    => $ok ? self::STATUS_SUCCESS : self::STATUS_FAILED,
                    'ok'        => $ok,
                    'http_code' => $ok ? 200 : 401,
                    'body'      => $ok ? '{"ok":true}' : '{"error":"unauthorized"}',
                    'error'     => $ok ? null : 'MaxChat membalas dengan HTTP 401.',
                ];
            }
        };

        return [new MaxChatDispatcher($model, $service), $model, $service];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function twoAccounts(): array
    {
        return [
            ['id' => 1, 'name' => 'Satu', 'base_url' => 'https://core.maxchat.id/diskominfo/api', 'token' => 'aaaaaaaa'],
            ['id' => 2, 'name' => 'Dua', 'base_url' => 'https://core.maxchat.id/diskominfo3/api', 'token' => 'bbbbbbbb'],
        ];
    }

    public function testAkunPertamaBerhasilTidakMenyentuhAkunKedua(): void
    {
        [$dispatcher, $model, $service] = $this->dispatcher($this->twoAccounts(), [true]);

        $outcome = $dispatcher->send('628123456789', 'Halo');

        $this->assertTrue($outcome['ok']);
        $this->assertCount(1, $outcome['attempts']);
        $this->assertSame('Satu', $outcome['account']['name']);
        $this->assertSame(['Satu'], $service->calls);
        $this->assertCount(1, $model->touched);
    }

    public function testGagalDiAkunPertamaDialihkanKeAkunKedua(): void
    {
        [$dispatcher, $model, $service] = $this->dispatcher($this->twoAccounts(), [false, true]);

        $outcome = $dispatcher->send('628123456789', 'Halo');

        $this->assertTrue($outcome['ok']);
        $this->assertCount(2, $outcome['attempts']);
        $this->assertSame('Dua', $outcome['account']['name']);
        $this->assertSame(['Satu', 'Dua'], $service->calls);

        // Percobaan gagal tetap tercatat sebagai baris pertama, supaya riwayat
        // memperlihatkan akun mana yang bermasalah.
        $this->assertFalse($outcome['attempts'][0]['result']['ok']);
        $this->assertSame('Satu', $outcome['attempts'][0]['account']['name']);

        // Akun yang gagal ikut di-touch agar turun ke ekor antrean.
        $this->assertSame([1, 2], array_column($model->touched, 'id'));
        $this->assertSame([true, true], array_column($model->touched, 'advanced'));
    }

    public function testSemuaAkunGagal(): void
    {
        [$dispatcher, , $service] = $this->dispatcher($this->twoAccounts(), [false, false]);

        $outcome = $dispatcher->send('628123456789', 'Halo');

        $this->assertFalse($outcome['ok']);
        $this->assertCount(2, $outcome['attempts']);
        $this->assertSame(['Satu', 'Dua'], $service->calls);
        $this->assertSame(MaxChatService::STATUS_FAILED, $outcome['final']['status']);
    }

    public function testTanpaAkunAktifTidakAdaRequestSamaSekali(): void
    {
        [$dispatcher, , $service] = $this->dispatcher([], [true]);

        $outcome = $dispatcher->send('628123456789', 'Halo');

        $this->assertFalse($outcome['ok']);
        $this->assertSame([], $outcome['attempts']);
        $this->assertNull($outcome['account']);
        $this->assertSame([], $service->calls);
        $this->assertStringContainsString('Akun MaxChat', (string) $outcome['final']['error']);
    }

    public function testPeekMengembalikanAkunTerdepanTanpaMengirim(): void
    {
        [$dispatcher, , $service] = $this->dispatcher($this->twoAccounts(), [true]);

        $this->assertSame('Satu', $dispatcher->peek()['name']);
        $this->assertSame([], $service->calls);
    }

    public function testTesKoneksiTidakMenggeserGiliranRotasi(): void
    {
        [$dispatcher, $model] = $this->dispatcher($this->twoAccounts(), [true]);

        $dispatcher->sendVia($this->twoAccounts()[1], '628123456789', 'Tes');

        $this->assertSame([['id' => 2, 'status' => MaxChatService::STATUS_SUCCESS, 'advanced' => false]], $model->touched);
    }
}
