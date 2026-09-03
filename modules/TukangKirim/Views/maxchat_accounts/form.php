<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    $isEdit = $account !== null;
$action     = $isEdit ? module_url('accounts/' . $account['id'] . '/update') : module_url('accounts/store');
$value      = static fn (string $field, $fallback = '') => old($field) ?? ($account[$field] ?? $fallback);
?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <h1 class="h3 mb-3"><?= $isEdit ? 'Ubah Akun' : 'Akun Baru' ?></h1>

        <form method="post" action="<?= $action ?>" class="card shadow-sm">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="name">Nama akun <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" required
                           value="<?= esc($value('name')) ?>" placeholder="Diskominfo 3">
                    <div class="form-text">Nama bebas, dipakai untuk menandai riwayat pengiriman.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="base_url">Base URL <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="base_url" name="base_url" required
                           value="<?= esc($value('base_url')) ?>" placeholder="https://core.maxchat.id/diskominfo3/api">
                    <div class="form-text">
                        Tanpa <code>/messages</code> di ujung — bagian itu ditambahkan otomatis
                        (dan dibuang otomatis bila ikut tersalin).
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="token">Token <span class="text-danger">*</span></label>
                    <input type="text" class="form-control font-monospace" id="token" name="token" required
                           value="<?= esc($value('token')) ?>" placeholder="Token bearer dari MaxChat" autocomplete="off">
                    <div class="form-text">Tiap alamat punya tokennya sendiri. Di daftar akun token ditampilkan tersamar.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="description">Keterangan</label>
                    <input type="text" class="form-control" id="description" name="description"
                           value="<?= esc($value('description')) ?>" placeholder="Akun cadangan untuk pengiriman massal.">
                </div>

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                        <?= (int) $value('is_active', 1) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Aktif (ikut dalam rotasi pengiriman)</label>
                </div>
            </div>
            <div class="card-footer bg-white d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Simpan</button>
                <a href="<?= module_url('accounts') ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
