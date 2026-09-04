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
                  // supaya sidebar tidak jadi daftar panjang saat modul bertambah.
                  //
                  // Tiap entri menu boleh berupa item biasa (punya 'path') atau
                  // sub-kelompok (punya 'items'): ['label'=>'TTE','items'=>[...]].
                  // Item biasa tetap didukung, jadi modul lain tak perlu diubah. ?>
            <?php if ($isActive && $module->menu() !== []): ?>
                <?php
                    $renderItem = static function (array $item) use ($slug, $activePath): void {
                        $active = $item['path'] === $activePath ? 'active' : '';
                        echo '<li class="nav-item"><a class="nav-link ' . $active . '" href="'
                            . module_url($item['path'], $slug) . '">'
                            . '<i class="bi ' . esc($item['icon']) . '"></i>'
                            . '<span>' . esc($item['label']) . '</span></a></li>';
                    };
                ?>
                <ul class="nav flex-column sidebar-submenu">
                    <?php foreach ($module->menu() as $entry): ?>
                        <?php if (isset($entry['items'])): ?>
                            <?php if (($entry['label'] ?? '') !== ''): ?>
                                <li class="sidebar-subheading"><?= esc($entry['label']) ?></li>
                            <?php endif; ?>
                            <?php foreach ($entry['items'] as $item) {
                                $renderItem($item);
                            } ?>
                        <?php else: ?>
                            <?php $renderItem($entry); ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
