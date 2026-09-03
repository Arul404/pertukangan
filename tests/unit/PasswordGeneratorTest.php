<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangKirim\Config\PasswordPolicy;
use Modules\TukangKirim\Libraries\PasswordGenerator;

/**
 * @internal
 */
final class PasswordGeneratorTest extends CIUnitTestCase
{
    private PasswordGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new PasswordGenerator();
    }

    public function testMemenuhiSyaratKomposisi(): void
    {
        $policy = config(PasswordPolicy::class);

        // Diulang banyak kali karena hasilnya acak: satu sampel tidak membuktikan apa-apa.
        for ($i = 0; $i < 200; $i++) {
            $password = $this->generator->generate();

            $this->assertGreaterThanOrEqual(10, strlen($password), 'Password minimal 10 karakter.');
            $this->assertMatchesRegularExpression('/[' . preg_quote($policy->upper, '/') . ']/', $password, 'Wajib ada huruf kapital.');
            $this->assertMatchesRegularExpression('/[' . preg_quote($policy->lower, '/') . ']/', $password, 'Wajib ada huruf kecil.');
            $this->assertMatchesRegularExpression('/[' . preg_quote($policy->digits, '/') . ']/', $password, 'Wajib ada angka.');
            $this->assertMatchesRegularExpression('/[' . preg_quote($policy->special, '/') . ']/', $password, 'Wajib ada karakter spesial.');
            $this->assertTrue($this->generator->validate($password));
        }
    }

    public function testMengikutiPanjangYangDiminta(): void
    {
        $this->assertSame(16, strlen($this->generator->generate(16)));
        $this->assertSame(10, strlen($this->generator->generate(10)));
    }

    public function testPanjangDinaikkanKeBatasMinimum(): void
    {
        // Permintaan di bawah 10 tetap dinaikkan, bukan ditolak diam-diam.
        $this->assertSame(PasswordPolicy::MIN_LENGTH, strlen($this->generator->generate(4)));
    }

    public function testTidakMenghasilkanPasswordYangSama(): void
    {
        $samples = [];

        for ($i = 0; $i < 100; $i++) {
            $samples[] = $this->generator->generate();
        }

        $this->assertSame($samples, array_values(array_unique($samples)), 'Password tidak boleh berulang.');
    }

    public function testValidateMenolakPasswordLemah(): void
    {
        $this->assertFalse($this->generator->validate('pendek1!'));          // < 10 karakter
        $this->assertFalse($this->generator->validate('semuahurufkecil'));   // tanpa kapital/angka/spesial
        $this->assertFalse($this->generator->validate('TanpaSpesial234'));   // tanpa karakter spesial
        $this->assertTrue($this->generator->validate('Rahasia99#x'));
    }
}
