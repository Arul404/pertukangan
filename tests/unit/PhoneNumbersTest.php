<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangAdmin\Libraries\PhoneNumbers;

/**
 * Aturan nomor yang dipakai bersama Reset Password (TTE) dan Reset Passphrase (BSrE).
 *
 * Aturan ini tampak sepele tetapi menyangga dua keputusan mahal: apakah kita
 * menawarkan penulisan ke sistem seberang (matches), dan apakah pencarian
 * dianggap gagal (variants). Salah sedikit, operator melihat "tidak ketemu"
 * palsu atau diminta memperbaiki nomor yang sebenarnya sudah benar.
 *
 * @internal
 */
final class PhoneNumbersTest extends CIUnitTestCase
{
    /**
     * @dataProvider nsnProvider
     */
    public function testNsnMenanggalkanKodeNegaraDanNolDepan(?string $raw, string $expected): void
    {
        $this->assertSame($expected, PhoneNumbers::nsn($raw));
    }

    public static function nsnProvider(): array
    {
        return [
            'lokal'          => ['081234567890', '81234567890'],
            'internasional'  => ['6281234567890', '81234567890'],
            'pakai plus'     => ['+6281234567890', '81234567890'],
            'pakai strip'    => ['0812-3456-7890', '81234567890'],
            'pakai spasi'    => ['+62 812 3456 7890', '81234567890'],
            'kosong'         => ['', ''],
            'tidak terbaca'  => [null, ''],
        ];
    }

    /**
     * @dataProvider matchesProvider
     */
    public function testPencocokanLintasFormat(?string $a, ?string $b, bool $expected): void
    {
        $this->assertSame($expected, PhoneNumbers::matches($a, $b));
    }

    public static function matchesProvider(): array
    {
        return [
            'lokal vs internasional' => ['081234567890', '6281234567890', true],
            'plus vs lokal'          => ['+6281234567890', '081234567890', true],
            'berstrip vs polos'      => ['0812-3456-7890', '6281234567890', true],
            'nomor berbeda'          => ['085290382571', '6281234567890', false],
            // "Tidak tahu" bukan "sama": memperlakukannya cocok akan melewatkan
            // review nomor pada akun yang justru paling perlu diperiksa.
            'kiri kosong'            => ['', '6281234567890', false],
            'kiri null'              => [null, '6281234567890', false],
            'kanan kosong'           => ['6281234567890', '', false],
        ];
    }

    public function testVariantsMencakupBentukLokalDanInternasional(): void
    {
        $variants = PhoneNumbers::variants('6281234567890');

        $this->assertSame(['081234567890', '6281234567890', '+6281234567890'], $variants);
    }
}
