<?php

use CodeIgniter\Pager\PagerRenderer;

/**
 * Template paginasi untuk Bootstrap 5.
 *
 * Bawaan CodeIgniter (system/Pager/Views/default_full.php) masih memakai markup
 * Bootstrap 3: <li> polos dan <a> tanpa kelas. Di Bootstrap 5 kelas .page-item
 * dan .page-link-lah yang memberi kotak, border, dan jarak — tanpa keduanya
 * pagination tampil sebagai daftar telanjang.
 *
 * Perbedaan lain dari bawaan: keadaan nonaktif dirender sebagai <span> di dalam
 * .page-item.disabled (tautan mati tetap bisa diklik dan difokus keyboard), dan
 * halaman aktif ditandai aria-current="page" untuk pembaca layar.
 *
 * @var PagerRenderer $pager
 */
$pager->setSurroundCount(2);
?>

<nav aria-label="<?= lang('Pager.pageNavigation') ?>">
    <ul class="pagination pagination-sm mb-0">
        <?php if ($pager->hasPrevious()): ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getFirst() ?>" aria-label="<?= lang('Pager.first') ?>">
                    <span aria-hidden="true">&laquo;&laquo;</span>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getPrevious() ?>" aria-label="<?= lang('Pager.previous') ?>">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item disabled">
                <span class="page-link" aria-hidden="true">&laquo;&laquo;</span>
            </li>
            <li class="page-item disabled">
                <span class="page-link" aria-hidden="true">&laquo;</span>
            </li>
        <?php endif ?>

        <?php foreach ($pager->links() as $link): ?>
            <li class="page-item <?= $link['active'] ? 'active' : '' ?>"
                <?= $link['active'] ? 'aria-current="page"' : '' ?>>
                <a class="page-link" href="<?= $link['uri'] ?>"><?= $link['title'] ?></a>
            </li>
        <?php endforeach ?>

        <?php if ($pager->hasNext()): ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getNext() ?>" aria-label="<?= lang('Pager.next') ?>">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getLast() ?>" aria-label="<?= lang('Pager.last') ?>">
                    <span aria-hidden="true">&raquo;&raquo;</span>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item disabled">
                <span class="page-link" aria-hidden="true">&raquo;</span>
            </li>
            <li class="page-item disabled">
                <span class="page-link" aria-hidden="true">&raquo;&raquo;</span>
            </li>
        <?php endif ?>
    </ul>
</nav>
