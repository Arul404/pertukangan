<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangAdmin\Config\Module;
use Modules\TukangAdmin\Controllers\ResetPassword;

/**
 * Kartu verifikasi nomor dan aturan pencocokannya, tanpa TTE maupun HTTP.
 *
 * Dua hal yang dijaga di sini mudah lolos dari mata:
 * 1. draftId harus benar-benar sampai ke tombol "Perbarui" — bila tidak,
 *    tombolnya tampil normal tetapi mengirim id kosong.
 * 2. 08… dan 62… bisa jadi nomor yang SAMA; menganggapnya berbeda membuat
 *    aplikasi menawarkan penulisan ke TTE yang sia-sia.
 *
 * @internal
 */
final class ResetPanelPhoneTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function draft(array $overrides = []): array
    {
        return array_merge([
            'phone_typed'      => '081234567890',
            'phone_input'      => '081234567890',
            'phone_normalized' => '6281234567890',
            'phone_tte'        => '085290382571',
            'edit_url'         => 'https://tte.magelangkab.go.id/users/update/8250',
            'wa_updated'       => false,
            'wa_notes'         => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function render(array $overrides = [], string $draftId = 'draft-abc-123'): string
    {
        $draft = $this->draft($overrides);

        return view(Module::VIEWS . 'reset/_card_phone', [
            'draft'        => $draft,
            'draftId'      => $draftId,
            'phoneMatches' => $this->phoneMatches($draft),
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function phoneMatches(array $draft): bool
    {
        return $this->getPrivateMethodInvoker(new ResetPassword(), 'phoneMatchesTte')($draft);
    }

    // -----------------------------------------------------------------
    // Aturan pencocokan nomor
    // -----------------------------------------------------------------

    /**
     * @dataProvider cocokProvider
     */
    public function testPencocokanNomorTteLintasFormat(?string $tte, bool $expected): void
    {
        $this->assertSame($expected, $this->phoneMatches($this->draft(['phone_tte' => $tte])));
    }

    public static function cocokProvider(): array
    {
        return [
            'sama, format lokal'         => ['081234567890', true],
            'sama, format internasional' => ['6281234567890', true],
            'sama, pakai plus'           => ['+6281234567890', true],
            'sama, pakai strip'          => ['0812-3456-7890', true],
            'nomor lain'                 => ['085290382571', false],
            'kosong'                     => ['', false],
            'tidak terbaca'              => [null, false],
        ];
    }

    // -----------------------------------------------------------------
    // Render kartu
    // -----------------------------------------------------------------

    public function testDraftIdSampaiKeTombolPerbarui(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('id="btn-update-wa"', $html);
        $this->assertStringContainsString('data-draft-id="draft-abc-123"', $html);
    }

    public function testNomorBerbedaMenawarkanPerbaruiDanLewati(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('btn-update-wa', $html);
        $this->assertStringContainsString('btn-skip-wa', $html);
        $this->assertStringContainsString('085290382571', $html);
    }

    public function testNomorCocokTidakMenawarkanPerbarui(): void
    {
        $html = $this->render(['phone_tte' => '081234567890']);

        $this->assertStringNotContainsString('btn-update-wa', $html);
        $this->assertStringContainsString('Nomor cocok', $html);
    }

    public function testFormatBerbedaTetapDianggapCocok(): void
    {
        $html = $this->render(['phone_tte' => '6281234567890']);

        $this->assertStringNotContainsString('btn-update-wa', $html);
        $this->assertStringContainsString('Nomor cocok', $html);
    }

    public function testTanpaTombolUbahDiTteHanyaMemberiKeterangan(): void
    {
        $html = $this->render(['edit_url' => null]);

        $this->assertStringNotContainsString('btn-update-wa', $html);
        $this->assertStringContainsString('tidak terbaca pada baris pengguna', $html);
    }

    public function testNomorTteKosongTetapMenawarkanPerbarui(): void
    {
        $html = $this->render(['phone_tte' => null]);

        $this->assertStringContainsString('btn-update-wa', $html);
        $this->assertStringContainsString('tidak terbaca', $html);
    }

    public function testSesudahDiperbaruiMenampilkanCatatanKolateral(): void
    {
        $html = $this->render([
            'wa_updated' => true,
            'phone_tte'  => '081234567890',
            'wa_notes'   => ['Kolom "namalengkap" ikut berubah dari "EDI" menjadi "" — periksa di TTE.'],
        ]);

        $this->assertStringNotContainsString('btn-update-wa', $html);
        $this->assertStringContainsString('sudah diperbarui', $html);
        $this->assertStringContainsString('ikut berubah', $html);
    }

    /**
     * Panel preview utuh, bukan hanya kartunya: memastikan $draftId benar-benar
     * menembus $this->include() ke partial. Bila tidak, tombol Perbarui tampil
     * normal tapi mengirim id kosong — gagal yang membingungkan.
     */
    public function testPanelPreviewUtuhMeneruskanDraftIdKeKartu(): void
    {
        $draft = $this->draft([
            'name'          => 'EDI WIDODO',
            'email'         => 'hediwidodo10@magelangkab.go.id',
            'role'          => 'penandatangan',
            'password'      => 'Rahasia123!',
            'text'          => 'Kata sandi baru Anda: Rahasia123!',
            'search_by'     => 'nik',
            'search_value'  => '3371022303750001',
        ]);

        $html = view(Module::VIEWS . 'reset/_panel_preview', [
            'draft'           => $draft,
            'draftId'         => 'draft-xyz-789',
            'maxchat'         => new \Modules\TukangKirim\Libraries\MaxChatService(),
            'nextAccount'     => ['name' => 'Akun Uji'],
            'phoneMatches'    => false,
            'needsWaDecision' => true,
        ]);

        // Kedua tombol memakai id draft yang sama.
        $this->assertSame(2, substr_count($html, 'data-draft-id="draft-xyz-789"'));

        // Tombol reset terkunci sampai Perbarui/Lewati diputuskan.
        $this->assertStringContainsString('data-needs-wa-decision="1"', $html);
        $this->assertStringContainsString('id="btn-update-wa"', $html);
    }

    public function testTujuanKirimSelaluNomorYangDiketik(): void
    {
        foreach ([['phone_tte' => '085290382571'], ['phone_tte' => null], ['wa_updated' => true]] as $case) {
            $html = $this->render($case);

            $this->assertStringContainsString('6281234567890', $html);
            $this->assertStringContainsString('nomor yang Anda ketik', $html);
        }
    }
}
