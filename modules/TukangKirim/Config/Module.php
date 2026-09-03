<?php

namespace Modules\TukangKirim\Config;

use App\Modules\ModuleDefinition;

/**
 * Definisi modul Tukang Kirim untuk shell Pertukangan.
 *
 * Inilah satu-satunya tempat slug 'tukang-kirim' ditulis. Route modul
 * (Config/Routes.php) dan seluruh tautan di view (lewat module_url()) mengacu
 * ke nilai yang sama, jadi prefix URL tidak pernah tersebar di banyak berkas.
 */
class Module extends ModuleDefinition
{
    public const SLUG = 'tukang-kirim';

    /**
     * Prefix namespace berkas view modul.
     *
     * View modul tidak berada di app/Views, jadi FileLocator hanya menemukannya
     * bila namanya ber-namespace: view(Module::VIEWS . 'send/form').
     */
    public const VIEWS = 'Modules\TukangKirim\Views\\';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Tukang Kirim';
    }

    public function icon(): string
    {
        return 'bi-send-fill';
    }

    public function description(): string
    {
        return 'Kirim pesan WhatsApp lewat MaxChat memakai template, '
            . 'placeholder otomatis, dan preview wajib sebelum terkirim.';
    }

    public function menu(): array
    {
        return [
            ['label' => 'Kirim Pesan',  'icon' => 'bi-send',           'path' => ''],
            ['label' => 'Template',     'icon' => 'bi-file-earmark-text', 'path' => 'templates'],
            ['label' => 'Akun MaxChat', 'icon' => 'bi-person-badge',   'path' => 'accounts'],
            ['label' => 'Riwayat',      'icon' => 'bi-clock-history',  'path' => 'logs'],
        ];
    }
}
