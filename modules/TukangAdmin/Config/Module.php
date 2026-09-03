<?php

namespace Modules\TukangAdmin\Config;

use App\Modules\ModuleDefinition;

/**
 * Definisi modul Tukang Admin untuk shell Pertukangan.
 *
 * Satu-satunya tempat slug 'tukang-admin' ditulis. Route modul
 * (Config/Routes.php) dan seluruh tautan view (lewat module_url()) mengacu ke
 * nilai yang sama, jadi prefix URL tidak pernah tersebar di banyak berkas.
 *
 * Modul ini mengurus tugas administratif terhadap aplikasi luar (TTE Kabupaten
 * Magelang) lewat scraping: mereset kata sandi akun penandatangan, lalu
 * mengirim kata sandi barunya memakai infrastruktur kirim milik Tukang Kirim.
 */
class Module extends ModuleDefinition
{
    public const SLUG = 'tukang-admin';

    /**
     * Prefix namespace berkas view modul.
     *
     * View modul tidak berada di app/Views, jadi FileLocator hanya menemukannya
     * bila namanya ber-namespace: view(Module::VIEWS . 'reset/form').
     */
    public const VIEWS = 'Modules\TukangAdmin\Views\\';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Tukang Admin';
    }

    public function icon(): string
    {
        return 'bi-shield-lock-fill';
    }

    public function description(): string
    {
        return 'Reset kata sandi akun penandatangan di aplikasi TTE Magelang '
            . 'secara otomatis, lalu kirim kata sandinya lewat Tukang Kirim.';
    }

    public function menu(): array
    {
        return [
            ['label' => 'Reset Password',    'icon' => 'bi-key',          'path' => ''],
            ['label' => 'Akun TTE',          'icon' => 'bi-person-gear',  'path' => 'akun'],
            ['label' => 'Reset Passphrase',  'icon' => 'bi-key-fill',     'path' => 'passphrase'],
            ['label' => 'Akun BSrE',         'icon' => 'bi-shield-lock',  'path' => 'akun-bsre'],
        ];
    }
}
