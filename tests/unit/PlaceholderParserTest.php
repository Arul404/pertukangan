<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangKirim\Libraries\PlaceholderParser;

/**
 * @internal
 */
final class PlaceholderParserTest extends CIUnitTestCase
{
    private PlaceholderParser $parser;

    private string $body = "Halo [nama],\nUsername: [username]\nPassword: [password]\nSalam, [nama].";

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PlaceholderParser();
    }

    public function testMengambilPlaceholderUnikSesuaiUrutan(): void
    {
        $this->assertSame(['nama', 'username', 'password'], $this->parser->extract($this->body));
    }

    public function testMemisahkanPlaceholderOtomatisDanManual(): void
    {
        $this->assertSame(['nama', 'username'], $this->parser->manual($this->body));
        $this->assertSame(['password'], $this->parser->reserved($this->body));
        $this->assertTrue($this->parser->hasPassword($this->body));
        $this->assertFalse($this->parser->hasPassword('Halo [nama], akun Anda sudah aktif.'));
    }

    public function testRenderMenggantiSemuaKemunculan(): void
    {
        $text = $this->parser->render($this->body, [
            'nama'     => 'Budi',
            'username' => 'budi.s',
            'password' => 'Rahasia99#x',
        ]);

        $this->assertSame("Halo Budi,\nUsername: budi.s\nPassword: Rahasia99#x\nSalam, Budi.", $text);
    }

    public function testPlaceholderTanpaNilaiDibiarkanUtuh(): void
    {
        // Sengaja dibiarkan agar kesalahan terlihat jelas saat preview, bukan terkirim sebagai teks kosong.
        $this->assertSame('Halo [nama], kode: 123', $this->parser->render('Halo [nama], kode: [kode]', ['kode' => '123']));
    }

    public function testNamaPlaceholderTidakSensitifHuruf(): void
    {
        $this->assertSame(['nama'], $this->parser->extract('Halo [Nama] dan [NAMA]'));
        $this->assertSame('Halo Budi dan Budi', $this->parser->render('Halo [Nama] dan [NAMA]', ['nama' => 'Budi']));
    }

    public function testTeksBerkurungTapiBukanPlaceholderDiabaikan(): void
    {
        $body = 'Jam kerja [08.00 - 16.00] dan [ nama ] tetap apa adanya.';

        $this->assertSame([], $this->parser->extract($body));
        $this->assertSame($body, $this->parser->render($body, ['nama' => 'Budi']));
    }

    public function testLabelDibuatEnakDibaca(): void
    {
        $this->assertSame('Nomor Hp', $this->parser->label('nomor_hp'));
        $this->assertSame('Username', $this->parser->label('username'));
    }
}
