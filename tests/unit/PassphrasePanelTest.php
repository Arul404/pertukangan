<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangAdmin\Config\Module;
use Modules\TukangAdmin\Controllers\ResetPassphrase;
use Modules\TukangAdmin\Libraries\PhoneNumbers;

/**
 * Panel Reset Passphrase: urutan langkah dan kartu review nomor, tanpa HTTP.
 *
 * Tiga hal yang dijaga di sini mudah lolos dari mata:
 *
 * 1. Urutan langkah tidak boleh bisa dilompati. Tombol Reset yang aktif terlalu
 *    dini berarti operator memicu langkah tak-terbatalkan pada akun yang nomornya
 *    belum diperiksa.
 * 2. Tepat SATU tombol aksi per keadaan. Dua tombol sekaligus berarti operator
 *    menebak-nebak mana yang benar.
 * 3. Keadaan `approve` bukan lagi langkah biasa. Menyimpan nomor sekaligus
 *    menyetujuinya, jadi keadaan itu hanya tercapai bila persetujuan otomatisnya
 *    gagal — dan panelnya wajib menjelaskan bahwa nomornya SUDAH tersimpan,
 *    supaya operator tidak mengirimnya untuk kedua kalinya.
 * 4. "Lewati" di sini TIDAK sama dengan "Lewati" pada Reset Password: bila nomor
 *    belum terverifikasi, melewatinya membuat tautan verifikasi dikirim ke nomor
 *    LAMA. Dialog konfirmasinya wajib menyebut itu — kalau tidak, kegagalannya
 *    diam dan baru ketahuan saat pengguna mengeluh tidak menerima apa-apa.
 *
 * @internal
 */
final class PassphrasePanelTest extends CIUnitTestCase
{
    private const DRAFT_ID = 'draft-abc-123';

    /**
     * @param array<string, mixed> $overrides
     */
    private function draft(array $overrides = []): array
    {
        return array_merge([
            'uid'           => '8250',
            'name'          => 'EDI WIDODO',
            'email'         => 'hediwidodo10@magelangkab.go.id',
            'nik'           => '3371022303750001',
            'serial'        => '5A1B2C3D4E5F',
            'jenis'         => 'Sertifikat Elektronik',
            'cert_count'    => 1,
            'phoneVerified' => false,

            'phone_typed'      => '081234567890',
            'phone_input'      => '081234567890',
            'phone_normalized' => '6281234567890',
            'phone_bsre'       => '085290382571',
            // Cocok dengan yang diketik (lintas format) — supaya tiap kasus di
            // bawah menguji tepat satu sebab. Kasus TTE menimpanya sendiri.
            'phone_tte'        => '081234567890',
            'tte_edit_url'     => '/users/edit/42',

            'search_by'     => 'nohp',
            'search_value'  => '081234567890',
            'resolved_from' => null,

            'phone_update_submitted' => false,
            'update_request_id'      => null,
            'update_approved'        => false,
            'approve_error'          => null,
            'tte_updated'            => false,
            'tte_error'              => null,
            'wa_skipped'             => false,
            'notes'                  => [],
        ], $overrides);
    }

    /**
     * Keadaan dihitung controller, bukan view — jadi diuji dari sana.
     *
     * @param array<string, mixed> $draft
     */
    private function stage(array $draft): string
    {
        return $this->getPrivateMethodInvoker(new ResetPassphrase(), 'stageFor')($draft);
    }

    /**
     * @param array<string, mixed> $draft
     *
     * @return list<string>
     */
    private function pending(array $draft): array
    {
        return $this->getPrivateMethodInvoker(new ResetPassphrase(), 'pendingSystems')($draft);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $draft = $this->draft($overrides);
        $typed = (string) $draft['phone_normalized'];

        return view(Module::VIEWS . 'passphrase/_panel_preview', [
            'draft'   => $draft,
            'draftId' => self::DRAFT_ID,
            'pending' => $this->pending($draft),
            'stage'   => $this->stage($draft),
            'matches' => [
                'tte'  => PhoneNumbers::matches($draft['phone_tte'] ?? null, $typed),
                'bsre' => PhoneNumbers::matches($draft['phone_bsre'] ?? null, $typed),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Mesin keadaan
    // -----------------------------------------------------------------

    /**
     * @dataProvider stageProvider
     *
     * @param array<string, mixed> $overrides
     */
    public function testKeadaanDitentukanKemajuanLangkah(array $overrides, string $expected): void
    {
        $this->assertSame($expected, $this->stage($this->draft($overrides)));
    }

    public static function stageProvider(): array
    {
        return [
            'nomor berbeda, belum diputuskan' => [[], 'decide'],
            'nomor cocok'                     => [['phone_bsre' => '081234567890'], 'act'],
            'nomor cocok lintas format'       => [['phone_bsre' => '+6281234567890'], 'act'],
            'tersimpan, persetujuan gagal'    => [['phone_update_submitted' => true], 'approve'],
            'sudah disetujui'                 => [['phone_update_submitted' => true, 'update_approved' => true], 'act'],
            'dilewati'                        => [['wa_skipped' => true], 'act'],

            // TTE punya sebabnya sendiri untuk menahan langkah — dan sebabnya
            // sendiri untuk BERHENTI menahan.
            'tte berbeda, bsre cocok'         => [[
                'phone_bsre' => '081234567890', 'phone_tte' => '085290382571',
            ], 'decide'],
            'tte berbeda tapi sudah gagal'    => [[
                'phone_bsre' => '081234567890', 'phone_tte' => '085290382571',
                'tte_error'  => 'Kolom "no_hp" tidak ditemukan pada form ubah pengguna TTE.',
            ], 'act'],
            'tte berbeda, tombol ubah hilang' => [[
                'phone_bsre' => '081234567890', 'phone_tte' => '085290382571',
                'tte_edit_url' => null,
            ], 'act'],
            'tte sudah diperbarui'            => [[
                'phone_bsre' => '081234567890', 'phone_tte' => '085290382571',
                'tte_updated' => true,
            ], 'act'],
            // Persetujuan yang menggantung mendahului TTE yang masih berbeda:
            // selama belum disetujui, nomor LAMA-lah yang berlaku di BSrE.
            'persetujuan gagal & tte berbeda' => [[
                'phone_update_submitted' => true, 'phone_tte' => '085290382571',
            ], 'approve'],
            // Nomor BSrE kosong bukan "cocok": justru itu yang paling perlu diisi.
            'nomor bsre kosong'               => [['phone_bsre' => null], 'decide'],
        ];
    }

    // -----------------------------------------------------------------
    // Tepat satu tombol aksi per keadaan
    // -----------------------------------------------------------------

    public function testKeadaanDecideMenawarkanPerbaruiLewatiDanMenguncikanReset(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('id="btn-update-phone"', $html);
        $this->assertStringContainsString('id="btn-skip-update"', $html);
        $this->assertStringNotContainsString('id="btn-approve-update"', $html);

        // Tombol Reset tetap dirender tetapi terkunci: operator perlu melihat
        // langkah akhirnya ada, sekaligus tahu ia belum boleh ditekan.
        $this->assertStringContainsString('data-needs-phone-decision="1"', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function testTombolPerbaruiMenyatakanIaSekaligusMenyetujui(): void
    {
        $html = $this->render();

        // Kalau dialognya hanya bicara soal menyimpan, operator akan mengira masih
        // ada langkah persetujuan yang harus ia kerjakan sendiri di portal.
        $this->assertStringContainsString('langsung disetujui', $html);
        $this->assertStringNotContainsString('belum berlaku sampai disetujui di langkah berikutnya', $html);
    }

    public function testKeadaanApproveMenjelaskanNomorSudahTersimpan(): void
    {
        $html = $this->render([
            'phone_update_submitted' => true,
            'approve_error'          => 'BSrE menolak persetujuan perubahan data (HTTP 500).',
        ]);

        // Alasan gagalnya harus terbaca — tanpa itu operator hanya tahu "gagal".
        $this->assertStringContainsString('HTTP 500', $html);
        // Dan yang paling menentukan: nomornya tidak boleh dikirim ulang.
        $this->assertStringContainsString('sudah tersimpan', $html);
        $this->assertStringNotContainsString('id="btn-update-phone"', $html);
    }

    public function testTombolMenyebutHanyaSistemYangPerluDiperbaiki(): void
    {
        // Hanya TTE yang berbeda: menawarkan "perbarui di BSrE" di sini berarti
        // menulis ulang nomor yang sudah benar, plus satu persetujuan sia-sia.
        $html = $this->render(['phone_bsre' => '081234567890', 'phone_tte' => '085290382571']);

        $this->assertStringContainsString('Perbarui nomor di TTE', $html);
        $this->assertStringNotContainsString('setujui nomor di BSrE', $html);
        $this->assertStringContainsString('TTE masih menyimpan nomor lama', $html);
    }

    public function testTombolMenyebutKeduanyaSaatKeduanyaBerbeda(): void
    {
        $html = $this->render(['phone_tte' => '085290382571']);

        $this->assertStringContainsString('Perbarui nomor di TTE &amp; BSrE', $html);
        // Urutan penulisannya TTE dulu, dan rinciannya harus mengatakan begitu.
        $this->assertStringContainsString('TTE disimpan ulang lebih dulu', $html);
    }

    public function testKegagalanTteDilaporkanTanpaMenguncikanReset(): void
    {
        $html = $this->render([
            'phone_bsre' => '081234567890',
            'phone_tte'  => '085290382571',
            'tte_error'  => 'Sesi TTE berakhir atau akun tidak berwenang mengubah data pengguna.',
        ]);

        // Alasannya terbaca…
        $this->assertStringContainsString('tidak berwenang mengubah data pengguna', $html);
        // …tetapi reset passphrase TIDAK ikut tertahan: TTE tidak menentukan
        // ke mana tautan reset maupun verifikasi dikirim.
        $this->assertStringContainsString('id="btn-dispatch"', $html);
        $this->assertStringNotContainsString('data-needs-phone-decision', $html);
    }

    public function testKeadaanApproveHanyaMenawarkanPersetujuan(): void
    {
        $html = $this->render(['phone_update_submitted' => true]);

        $this->assertStringContainsString('id="btn-approve-update"', $html);
        $this->assertStringNotContainsString('id="btn-dispatch"', $html);
        $this->assertStringNotContainsString('id="btn-update-phone"', $html);
    }

    public function testKeadaanActHanyaMenawarkanReset(): void
    {
        $html = $this->render(['phone_bsre' => '081234567890']);

        $this->assertStringContainsString('id="btn-dispatch"', $html);
        $this->assertStringNotContainsString('id="btn-approve-update"', $html);
        $this->assertStringNotContainsString('id="btn-update-phone"', $html);
        $this->assertStringNotContainsString('data-needs-phone-decision', $html);
    }

    public function testDraftIdSampaiKeSetiapTombolLangkah(): void
    {
        foreach ([[], ['phone_update_submitted' => true], ['wa_skipped' => true]] as $case) {
            $html = $this->render($case);

            $this->assertStringContainsString('data-draft-id="' . self::DRAFT_ID . '"', $html);
            $this->assertStringNotContainsString('data-draft-id=""', $html);
        }
    }

    // -----------------------------------------------------------------
    // Satu tombol Reset, dua dialog
    // -----------------------------------------------------------------

    public function testDialogResetBerbunyiVerifikasiSaatBelumTerverifikasi(): void
    {
        $html = $this->render(['phone_bsre' => '081234567890', 'phoneVerified' => false]);

        $this->assertStringContainsString('data-confirm-ok="Verifikasi"', $html);
        $this->assertStringContainsString('tautan verifikasi ke WhatsApp', $html);
        // Label tombolnya sendiri tetap sama seperti aksi di portal.
        $this->assertStringContainsString('Reset Passphrase', $html);
    }

    public function testDialogResetBerbunyiKirimKeEmailSaatSudahTerverifikasi(): void
    {
        $html = $this->render(['phone_bsre' => '081234567890', 'phoneVerified' => true]);

        $this->assertStringContainsString('data-confirm-ok="Ya, Reset Passphrase"', $html);
        $this->assertStringContainsString('email dinas', $html);
        $this->assertStringNotContainsString('tautan verifikasi ke WhatsApp', $html);
    }

    // -----------------------------------------------------------------
    // Peringatan "Lewati" yang membedakannya dari Reset Password
    // -----------------------------------------------------------------

    public function testLewatiMemperingatkanTautanDikirimKeNomorLama(): void
    {
        $html = $this->render(['phoneVerified' => false]);

        $this->assertStringContainsString('085290382571', $html);
        $this->assertStringContainsString('bukan ke nomor yang Anda ketik', $html);
    }

    public function testLewatiTidakMenakutiSaatNomorSudahTerverifikasi(): void
    {
        $html = $this->render(['phoneVerified' => true]);

        $this->assertStringContainsString('id="btn-skip-update"', $html);
        $this->assertStringContainsString('tetap bisa dijalankan', $html);
    }

    public function testCatatanLangkahSebelumnyaIkutDitampilkan(): void
    {
        $html = $this->render([
            'phone_update_submitted' => true,
            'notes'                  => ['Perubahan nomor HP tersimpan dan menunggu persetujuan.'],
        ]);

        $this->assertStringContainsString('menunggu persetujuan', $html);
    }
}
