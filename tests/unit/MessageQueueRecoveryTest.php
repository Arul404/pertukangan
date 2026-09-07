<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangKirim\Libraries\MaxChatService;
use Modules\TukangKirim\Libraries\SendQueueService;
use Modules\TukangKirim\Models\MessageLogModel;
use Modules\TukangKirim\Models\MessageQueueModel;

/**
 * Pemulihan baris yatim: baris yang tertinggal berstatus `processing` karena
 * worker mati di antara claimNext() dan drop().
 *
 * Diuji tanpa database — model antrean dan model log digantikan stub in-memory
 * lewat konstruktor SendQueueService, mengikuti pola MaxChatDispatcherTest.
 *
 * @internal
 */
final class MessageQueueRecoveryTest extends CIUnitTestCase
{
    private const SECRET = 'Rahasia123!';

    /**
     * @param list<array<string, mixed>> $rows isi tabel message_queue
     *
     * @return array{0: SendQueueService, 1: object, 2: object}
     */
    private function service(array $rows): array
    {
        $queue = new class ($rows) extends MessageQueueModel {
            /** @var list<int> id yang dihapus, berurutan */
            public array $dropped = [];

            /** @var list<int> id yang dikembalikan ke antrean */
            public array $requeued = [];

            /** @param list<array<string, mixed>> $rows */
            public function __construct(public array $rows)
            {
                // Sengaja tidak memanggil parent::__construct(): stub ini tidak
                // pernah menyentuh database.
            }

            public function orphans(): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn (array $r): bool => $r['status'] === 'processing',
                ));
            }

            public function requeue(int $id): int
            {
                $this->requeued[] = $id;

                foreach ($this->rows as $i => $r) {
                    if ((int) $r['id'] === $id) {
                        $this->rows[$i]['status']    = 'queued';
                        $this->rows[$i]['attempts']  = (int) $r['attempts'] + 1;
                        $this->rows[$i]['locked_at'] = null;

                        return $this->rows[$i]['attempts'];
                    }
                }

                return 0;
            }

            public function drop(int $id): void
            {
                $this->dropped[] = $id;
                $this->rows      = array_values(array_filter(
                    $this->rows,
                    static fn (array $r): bool => (int) $r['id'] !== $id,
                ));
            }
        };

        $logs = new class () extends MessageLogModel {
            /** @var list<array<string, mixed>> baris yang ditulis ke riwayat */
            public array $inserted = [];

            public function __construct()
            {
                // Stub in-memory; tidak menyentuh database.
            }

            public function insert($row = null, bool $returnID = true)
            {
                $this->inserted[] = (array) $row;

                return 1;
            }
        };

        return [new SendQueueService($queue, $logs), $queue, $logs];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $status, int $attempts): array
    {
        return [
            'id'          => $id,
            'recipient'   => '628123456789',
            'body'        => 'Kata sandi baru Anda: ' . self::SECRET,
            'secret'      => self::SECRET,
            'log_context' => json_encode([
                'template_id'          => 7,
                'template_name'        => 'Reset Sandi',
                'recipient_input'      => '08123456789',
                'recipient_normalized' => '628123456789',
                'message_masked'       => 'Kata sandi baru Anda: ********',
                'has_password'         => 1,
            ]),
            'status'    => $status,
            'attempts'  => $attempts,
            'locked_at' => '2026-09-05 10:00:00',
        ];
    }

    public function testHanyaBarisProcessingYangDianggapYatim(): void
    {
        [, $queue] = $this->service([
            $this->row(1, 'queued', 0),
            $this->row(2, 'processing', 0),
        ]);

        $this->assertSame([2], array_column($queue->orphans(), 'id'));
    }

    public function testRequeueMenaikkanAttemptsDanMelepasKunci(): void
    {
        [, $queue] = $this->service([$this->row(5, 'processing', 0)]);

        $this->assertSame(1, $queue->requeue(5));
        $this->assertSame('queued', $queue->rows[0]['status']);
        $this->assertNull($queue->rows[0]['locked_at']);
    }

    public function testYatimPertamaDikembalikanKeAntreanTanpaMenulisRiwayat(): void
    {
        [$service, $queue, $logs] = $this->service([$this->row(1, 'processing', 0)]);

        $this->assertSame(['requeued' => 1, 'abandoned' => 0], $service->reclaimOrphans());
        $this->assertSame([1], $queue->requeued);
        $this->assertSame([], $queue->dropped);

        // Belum menyerah: tidak ada yang perlu dicatat ke riwayat.
        $this->assertSame([], $logs->inserted);
    }

    public function testYatimBerulangDitinggalkanDanBarisnyaDihapus(): void
    {
        [$service, $queue, $logs] = $this->service([$this->row(1, 'processing', 1)]);

        $this->assertSame(['requeued' => 0, 'abandoned' => 1], $service->reclaimOrphans());
        $this->assertSame([], $queue->requeued);

        // Barisnya DIHAPUS, bukan ditandai gagal: kolom `secret` memuat kata
        // sandi asli dan tidak boleh menetap.
        $this->assertSame([1], $queue->dropped);
        $this->assertSame([], $queue->rows);

        $this->assertCount(1, $logs->inserted);
        $log = $logs->inserted[0];

        $this->assertSame(MaxChatService::STATUS_FAILED, $log['status']);
        $this->assertSame(2, $log['attempt_no']);
        $this->assertNull($log['maxchat_account_id']);
        $this->assertStringContainsString('ditinggalkan', (string) $log['error_message']);
        $this->assertStringContainsString('mungkin sudah terkirim', (string) $log['error_message']);
    }

    public function testKataSandiAsliTidakPernahMasukRiwayat(): void
    {
        [$service, , $logs] = $this->service([$this->row(1, 'processing', 1)]);

        $service->reclaimOrphans();

        $this->assertStringNotContainsString(
            self::SECRET,
            (string) json_encode($logs->inserted),
        );
    }

    public function testCampuranYatimDihitungTerpisah(): void
    {
        [$service, $queue] = $this->service([
            $this->row(1, 'processing', 0),  // dikembalikan
            $this->row(2, 'processing', 1),  // ditinggalkan
            $this->row(3, 'queued', 0),      // bukan yatim, tak disentuh
        ]);

        $this->assertSame(['requeued' => 1, 'abandoned' => 1], $service->reclaimOrphans());
        $this->assertSame([1], $queue->requeued);
        $this->assertSame([2], $queue->dropped);
        $this->assertSame([1, 3], array_column($queue->rows, 'id'));
    }

    public function testAntreanTanpaYatimTidakMelakukanApaPun(): void
    {
        [$service, $queue, $logs] = $this->service([$this->row(1, 'queued', 0)]);

        $this->assertSame(['requeued' => 0, 'abandoned' => 0], $service->reclaimOrphans());
        $this->assertSame([], $queue->requeued);
        $this->assertSame([], $queue->dropped);
        $this->assertSame([], $logs->inserted);
    }
}
