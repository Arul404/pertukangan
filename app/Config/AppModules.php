<?php

namespace Config;

use App\Modules\ModuleDefinition;
use CodeIgniter\Config\BaseConfig;

/**
 * Registry modul Pertukangan.
 *
 * Namanya AppModules, bukan Modules, karena Config\Modules sudah dipakai
 * CodeIgniter untuk pengaturan auto-discovery.
 */
class AppModules extends BaseConfig
{
    /**
     * Daftar kelas definisi modul, sesuai urutan tampil di sidebar.
     *
     * @var list<class-string<ModuleDefinition>>
     */
    public array $modules = [
        \Modules\TukangKirim\Config\Module::class,
    ];

    /**
     * Instansiasi semua definisi modul, terkunci pada slug-nya.
     *
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = [];

        foreach ($this->modules as $class) {
            $module = new $class();
            $resolved[$module->slug()] = $module;
        }

        return $resolved;
    }

    /** Definisi satu modul berdasarkan slug, atau null bila tidak terdaftar. */
    public function find(?string $slug): ?ModuleDefinition
    {
        return $slug === null ? null : ($this->all()[$slug] ?? null);
    }
}
