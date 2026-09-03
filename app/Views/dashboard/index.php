<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="mb-4">
    <h1 class="h3 mb-1">Pertukangan</h1>
    <p class="text-muted mb-0">Pilih modul yang mau dipakai.</p>
</div>

<?php if ($modules === []): ?>
    <div class="alert alert-warning">
        Belum ada modul terdaftar. Tambahkan kelas definisinya di
        <code>app/Config/AppModules.php</code>.
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($modules as $module): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100 shadow-sm module-card">
                    <div class="card-body d-flex flex-column">
                        <div class="module-card-icon mb-3">
                            <i class="bi <?= esc($module->icon()) ?>"></i>
                        </div>
                        <h2 class="h5 card-title"><?= esc($module->name()) ?></h2>
                        <p class="card-text text-muted small flex-grow-1">
                            <?= esc($module->description()) ?>
                        </p>
                        <a href="<?= site_url($module->slug()) ?>" class="btn btn-primary mt-3 align-self-start">
                            Buka <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
