<?php
/**
 * Isi sidebar: daftar modul, lalu menu internal modul yang sedang aktif.
 *
 * Dipakai dua kali oleh layouts/main.php — sekali di kolom tetap layar lebar,
 * sekali di dalam offcanvas layar kecil. View::include() merender partial ini
 * dengan data view, bukan variabel lokal berkas pemanggil, jadi partial ini
 * sengaja berdiri sendiri: semua yang dibutuhkannya dibaca sendiri di sini.
 */

use Config\AppModules;

$registry   = config(AppModules::class);
$modules    = $registry->all();
$activeSlug = active_module();
$activePath = active_module_path();
?>
<a class="sidebar-brand" href="<?= site_url('/') ?>">
    <i class="bi bi-tools"></i>
    <span>Pertukangan</span>
</a>

<div class="sidebar-heading">Modul</div>

<ul class="nav flex-column sidebar-nav">
    <?php foreach ($modules as $slug => $module): ?>
        <?php $isActive = $slug === $activeSlug; ?>
        <li class="nav-item">
            <a class="nav-link module-link <?= $isActive ? 'active' : '' ?>"
               href="<?= site_url($slug) ?>">
                <i class="bi <?= esc($module->icon()) ?>"></i>
                <span><?= esc($module->name()) ?></span>
            </a>

            <?php // Menu internal hanya dibuka untuk modul yang sedang dipakai,
                  // supaya sidebar tidak jadi daftar panjang saat modul bertambah. ?>
            <?php if ($isActive && $module->menu() !== []): ?>
                <ul class="nav flex-column sidebar-submenu">
                    <?php foreach ($module->menu() as $item): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $item['path'] === $activePath ? 'active' : '' ?>"
                               href="<?= module_url($item['path'], $slug) ?>">
                                <i class="bi <?= esc($item['icon']) ?>"></i>
                                <span><?= esc($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
