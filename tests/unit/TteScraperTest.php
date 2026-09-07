<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangAdmin\Config\Tte as TteConfig;
use Modules\TukangAdmin\Libraries\TteAmbiguousMatchException;
use Modules\TukangAdmin\Libraries\TteScraperException;
use Modules\TukangAdmin\Libraries\TteUserNotFoundException;
use Tests\Support\Libraries\FakeTteScraper;

/**
 * Scraping TTE diuji tanpa jaringan: FakeTteScraper mengganti request() dengan
 * halaman kalengan, dan fixture-nya adalah markup ASLI dari TTE — bukan karangan
 * — sehingga tes ini menguji bentuk yang benar-benar dihadapi di lapangan.
 *
 * @internal
 */
final class TteScraperTest extends CIUnitTestCase
{
    private const BASE = 'https://tte.magelangkab.go.id';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(
            SUPPORTPATH . 'Fixtures' . DIRECTORY_SEPARATOR . 'Tte' . DIRECTORY_SEPARATOR . $name,
        );
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function scraper(array $responses, array $configOverrides = []): FakeTteScraper
    {
        $config = new TteConfig();

        foreach ($configOverrides as $key => $value) {
            $config->{$key} = $value;
        }

        return new FakeTteScraper($responses, $config);
    }

    // -----------------------------------------------------------------
    // Tautan aksi pada baris hasil
    // -----------------------------------------------------------------

    public function testTombolUbahDanKataSandiTidakPernahTertukar(): void
    {
        $scraper = $this->scraper([['body' => $this->fixture('users_row.html')]]);
        $user    = $scraper->findPenandatangan('085290382571');

        $this->assertSame(self::BASE . '/users/password/8250', $user['change_url']);
        $this->assertSame(self::BASE . '/users/update/8250', $user['edit_url']);
    }

    public function testTautanHapusDanJavascriptTidakPernahTerpilih(): void
    {
        $scraper = $this->scraper([['body' => $this->fixture('users_row.html')]]);
        $user    = $scraper->findPenandatangan('085290382571');

        foreach (['change_url', 'edit_url'] as $key) {
            $this->assertStringNotContainsString('/users/delete/', (string) $user[$key]);
            $this->assertStringNotContainsString('javascript:', (string) $user[$key]);
        }
    }

    public function testDataBarisTerbacaLengkap(): void
    {
        $scraper = $this->scraper([['body' => $this->fixture('users_row.html')]]);
        $user    = $scraper->findPenandatangan('085290382571');

        $this->assertSame('hediwidodo10@magelangkab.go.id', $user['email']);
        $this->assertSame('EDI WIDODO', $user['name']);
        $this->assertSame('085290382571', $user['phone']);
        $this->assertSame('penandatangan', $user['role']);
    }

    public function testTombolUbahIkonTanpaTeksTetapTerbaca(): void
    {
        $row = '<table><tr><td>penandatangan a@b.go.id</td><td>'
            . '<a href="/users/password/9" title="Ubah Kata Sandi"><i class="fas fa-key"></i></a>'
            . '<a href="/users/update/9" aria-label="Ubah"><i class="fas fa-pencil"></i></a>'
            . '</td></tr></table>';

        $scraper = $this->scraper([['body' => $row]]);
        $user    = $scraper->findPenandatangan('a@b.go.id');

        // Label tombol sandi memuat "ubah" juga, tapi daftar tolak menyaringnya
        // lebih dulu sehingga tidak pernah dianggap tombol ubah data.
        $this->assertSame('/users/password/9', $user['change_url']);
        $this->assertSame('/users/update/9', $user['edit_url']);
    }

    public function testTanpaTombolUbahResetTetapBisaJalan(): void
    {
        $row = '<table><tr><td>penandatangan a@b.go.id</td>'
            . '<td><a href="/users/password/9">Kata Sandi</a></td></tr></table>';

        $scraper = $this->scraper([['body' => $row]]);
        $user    = $scraper->findPenandatangan('a@b.go.id');

        $this->assertSame('/users/password/9', $user['change_url']);
        $this->assertNull($user['edit_url']);
    }

    // -----------------------------------------------------------------
    // Hasil pencarian
    // -----------------------------------------------------------------

    public function testHasilKosongMemberiPesanYangBersih(): void
    {
        $scraper = $this->scraper([['body' => $this->fixture('users_empty.html')]]);

        $this->expectException(TteUserNotFoundException::class);
        $this->expectExceptionMessage('Tidak ada pengguna yang cocok');

        $scraper->findPenandatangan('888888');
    }

    public function testHasilGandaDitolakBukanDitebak(): void
    {
        $row  = $this->fixture('users_row.html');
        $twin = str_replace(['8250', 'hediwidodo10@'], ['9310', 'oranglain@'], $row);
        // Gabungkan dua baris ke dalam satu tabel.
        $both = str_replace('</tbody>', substr($twin, (int) strpos($twin, '<tbody>') + 7), $row);

        $scraper = $this->scraper([['body' => $both]]);

        $this->expectException(TteAmbiguousMatchException::class);
        $this->expectExceptionMessage('cocok dengan 2 pengguna');

        $scraper->findPenandatangan('085290382571');
    }

    // -----------------------------------------------------------------
    // Pembacaan form
    // -----------------------------------------------------------------

    public function testFormSandiMembawaMethodSpoofing(): void
    {
        $scraper = $this->scraper([['body' => '']]);
        $form    = $this->getPrivateMethodInvoker($scraper, 'passwordForm')($this->fixture('user_password.html'));

        $this->assertNotNull($form);
        $this->assertSame(self::BASE . '/users/password', $form['action']);

        // Inilah field yang, bila hilang karena refactor, mematahkan reset kata
        // sandi tanpa gejala yang jelas.
        $this->assertSame('PUT', $form['fields']['_method']);
        $this->assertSame('8250', $form['fields']['id']);
        $this->assertArrayHasKey('csrf_token_tte', $form['fields']);
        $this->assertSame(['password', 'confirmPassword'], $form['password_fields']);
        $this->assertSame([], $form['unreadable']);
    }

    public function testFormUbahTerbacaLengkap(): void
    {
        $scraper = $this->scraper([['body' => '']]);
        $form    = $this->getPrivateMethodInvoker($scraper, 'fieldForm')($this->fixture('user_edit.html'), 'nowhatsapp');

        $this->assertNotNull($form);
        $this->assertSame('/users/update', $form['action']);
        $this->assertSame('8250', $form['fields']['id']);
        $this->assertSame('EDI WIDODO', $form['fields']['namalengkap']);
        $this->assertSame('3371022303750001', $form['fields']['nik']);
        $this->assertSame('085290382571', $form['fields']['nowhatsapp']);
        $this->assertSame('5', $form['fields']['group_id']);
        $this->assertSame('15', $form['fields']['skpd_id']);
        $this->assertSame([], $form['unreadable']);
        $this->assertSame([], $form['password_fields']);
    }

    public function testTextareaTidakLagiDikosongkan(): void
    {
        $html    = '<form action="/x"><input name="nowhatsapp" value="08123456789">'
            . '<textarea name="alamat">Jalan Merdeka 1</textarea></form>';
        $scraper = $this->scraper([['body' => '']]);
        $form    = $this->getPrivateMethodInvoker($scraper, 'fieldForm')($html, 'nowhatsapp');

        $this->assertSame('Jalan Merdeka 1', $form['fields']['alamat']);
    }

    public function testOpsiTanpaAtributValueMemakaiTeksnya(): void
    {
        $html    = '<form action="/x"><input name="nowhatsapp" value="08123456789">'
            . '<select name="jenis"><option>Umum</option><option selected>Khusus</option></select></form>';
        $scraper = $this->scraper([['body' => '']]);
        $form    = $this->getPrivateMethodInvoker($scraper, 'fieldForm')($html, 'nowhatsapp');

        $this->assertSame('Khusus', $form['fields']['jenis']);
    }

    public function testSelectTanpaSelectedDicatatSebagaiTakTerbaca(): void
    {
        $html    = '<form action="/x"><input name="nowhatsapp" value="08123456789">'
            . '<select name="unit"><option value="1">Satu</option><option value="2">Dua</option></select></form>';
        $scraper = $this->scraper([['body' => '']]);
        $form    = $this->getPrivateMethodInvoker($scraper, 'fieldForm')($html, 'nowhatsapp');

        $this->assertSame(['unit'], $form['unreadable']);
        $this->assertSame('1', $form['fields']['unit']);
    }

    public function testKontrolDisabledDilewati(): void
    {
        $html    = '<form action="/x"><input name="nowhatsapp" value="08123456789">'
            . '<input name="terkunci" value="x" disabled><input type="file" name="foto"></form>';
        $scraper = $this->scraper([['body' => '']]);
        $form    = $this->getPrivateMethodInvoker($scraper, 'fieldForm')($html, 'nowhatsapp');

        $this->assertArrayNotHasKey('terkunci', $form['fields']);
        $this->assertArrayNotHasKey('foto', $form['fields']);
    }

    public function testFieldFormMemilihFormYangBenarDiAntaraBeberapaForm(): void
    {
        $html = '<form action="/cari" method="get"><input name="q" value="abc"></form>'
            . $this->fixture('user_edit.html')
            . '<form action="/logout" method="post"><input type="hidden" name="csrf_token_tte" value="z"></form>';

        $scraper = $this->scraper([['body' => '']]);
        $form    = $this->getPrivateMethodInvoker($scraper, 'fieldForm')($html, 'nowhatsapp');

        $this->assertSame('/users/update', $form['action']);
        $this->assertArrayNotHasKey('q', $form['fields']);
    }

    public function testNamaKolomTidakSahDitolak(): void
    {
        $scraper = $this->scraper([['body' => '']]);

        $this->expectException(TteScraperException::class);
        $this->expectExceptionMessage('tte.whatsappField');

        $this->getPrivateMethodInvoker($scraper, 'fieldForm')('<form></form>', 'no"whatsapp');
    }

    // -----------------------------------------------------------------
    // updateWhatsapp()
    // -----------------------------------------------------------------

    public function testUbahNomorHanyaMengubahKolomNomor(): void
    {
        $edit    = $this->fixture('user_edit.html');
        $after   = str_replace('value="085290382571"', 'value="081234567890"', $edit);
        $scraper = $this->scraper([
            ['body' => $edit],   // GET form
            ['body' => ''],      // POST simpan
            ['body' => $after],  // GET baca ulang
        ]);

        $result = $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6281234567890');

        $this->assertTrue($result['changed']);
        $this->assertTrue($result['verified']);
        $this->assertSame('085290382571', $result['before']);
        $this->assertSame([], $result['collateral']);

        $posts = $scraper->posts();
        $this->assertCount(1, $posts);

        // Nomor ditulis mengikuti format lama TTE (08…), bukan 62…
        $this->assertSame('081234567890', $posts[0]['nowhatsapp']);

        // Semua kolom lain dikirim ulang apa adanya.
        $this->assertSame('EDI WIDODO', $posts[0]['namalengkap']);
        $this->assertSame('3371022303750001', $posts[0]['nik']);
        $this->assertSame('hediwidodo10@magelangkab.go.id', $posts[0]['email']);
        $this->assertSame('5', $posts[0]['group_id']);
        $this->assertSame('15', $posts[0]['skpd_id']);
        $this->assertSame('8250', $posts[0]['id']);
    }

    public function testKolomHilangBerartiTidakAdaYangDikirim(): void
    {
        $scraper = $this->scraper([['body' => '<form action="/users/update"><input name="lain" value="1"></form>']]);

        try {
            $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6281234567890');
            $this->fail('Seharusnya melempar.');
        } catch (TteScraperException $e) {
            $this->assertStringContainsString('nowhatsapp', $e->getMessage());
        }

        $this->assertSame([], $scraper->posts(), 'Tidak boleh ada POST sama sekali.');
    }

    public function testKolomTakTerbacaMembatalkanPenyimpanan(): void
    {
        $edit    = str_replace('<option value="5" selected>', '<option value="5">', $this->fixture('user_edit.html'));
        $scraper = $this->scraper([['body' => $edit]]);

        try {
            $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6281234567890');
            $this->fail('Seharusnya melempar.');
        } catch (TteScraperException $e) {
            $this->assertStringContainsString('group_id', $e->getMessage());
        }

        $this->assertSame([], $scraper->posts(), 'Tidak boleh ada POST sama sekali.');
    }

    public function testNomorSudahSamaTidakMenulisApaPun(): void
    {
        $scraper = $this->scraper([['body' => $this->fixture('user_edit.html')]]);
        $result  = $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6285290382571');

        $this->assertFalse($result['changed']);
        $this->assertTrue($result['verified']);
        $this->assertSame([], $scraper->posts());
    }

    public function testNomorTidakBerubahSesudahSimpanDianggapGagal(): void
    {
        $edit    = $this->fixture('user_edit.html');
        $scraper = $this->scraper([
            ['body' => $edit],
            ['body' => ''],
            ['body' => $edit], // masih nomor lama
        ]);

        $this->expectException(TteScraperException::class);
        $this->expectExceptionMessage('nomor tidak berubah');

        $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6281234567890');
    }

    public function testBacaUlangGagalTidakMemblokirAlur(): void
    {
        $scraper = $this->scraper([
            ['body' => $this->fixture('user_edit.html')],
            ['body' => ''],
            ['throw' => 'jaringan putus'],
        ]);

        $result = $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6281234567890');

        $this->assertTrue($result['changed']);
        $this->assertFalse($result['verified']);
    }

    public function testPerubahanKolomLainTerdeteksiSebagaiKolateral(): void
    {
        $edit  = $this->fixture('user_edit.html');
        $after = str_replace(
            ['value="085290382571"', 'value="EDI WIDODO"'],
            ['value="081234567890"', 'value=""'],
            $edit,
        );

        $scraper = $this->scraper([['body' => $edit], ['body' => ''], ['body' => $after]]);
        $result  = $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6281234567890');

        $this->assertArrayHasKey('namalengkap', $result['collateral']);
        $this->assertSame(['EDI WIDODO', ''], $result['collateral']['namalengkap']);
    }

    public function testSesiHabisDilaporkanSebagaiSesiBukanKolomHilang(): void
    {
        $scraper = $this->scraper([[
            'body' => '<form action="/login"><input type="password" name="password"></form>',
            'url'  => self::BASE . '/login',
        ]]);

        $this->expectException(TteScraperException::class);
        $this->expectExceptionMessage('tidak berwenang');

        $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], '6281234567890');
    }

    public function testNomorSampahDitolakSebelumMenyentuhJaringan(): void
    {
        $scraper = $this->scraper([['body' => $this->fixture('user_edit.html')]]);

        try {
            $scraper->updateWhatsapp(['edit_url' => '/users/update/8250'], 'bukan-nomor');
            $this->fail('Seharusnya melempar.');
        } catch (TteScraperException $e) {
            $this->assertStringContainsString('tidak sah', $e->getMessage());
        }

        $this->assertSame([], $scraper->calls, 'Tidak boleh ada request sama sekali.');
    }
}
