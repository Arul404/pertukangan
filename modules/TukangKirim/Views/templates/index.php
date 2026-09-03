<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 mb-1">Template Pesan</h1>
        <p class="text-muted mb-0">Isi pesan siap pakai beserta placeholdernya.</p>
    </div>
    <a href="<?= module_url('templates/create') ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Template Baru</a>
</div>

<?php if ($templates === []): ?>
    <div class="alert alert-light border text-center text-muted py-4">Belum ada template.</div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($templates as $template): ?>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-semibold"><?= esc($template['name']) ?></div>
                    <?php if ((int) $template['is_active'] === 1): ?>
                        <span class="badge text-bg-success">Aktif</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">Nonaktif</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="message-preview text-body-secondary small"><?= esc($template['body']) ?></p>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center gap-2">
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($template['placeholders'] as $name): ?>
                            <code class="placeholder-chip<?= $parser->isReserved($name) ? ' bg-success-subtle text-success-emphasis' : '' ?>"
                                  title="<?= $parser->isReserved($name) ? 'Dibuat otomatis oleh sistem' : 'Diisi manual saat kirim' ?>">
                                [<?= esc($name) ?>]<?= $parser->isReserved($name) ? ' auto' : '' ?>
                            </code>
                        <?php endforeach; ?>
                        <?php if ($template['placeholders'] === []): ?>
                            <span class="text-muted small">tanpa placeholder</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-nowrap">
                        <a href="<?= module_url('templates/' . $template['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="post" action="<?= module_url('templates/' . $template['id'] . '/delete') ?>" class="d-inline"
                              data-confirm-title="Hapus template?"
                              data-confirm="Template ini akan dihapus permanen. Riwayat pengiriman yang memakainya tetap tersimpan."
                              data-confirm-detail="<?= esc($template['name'], 'attr') ?>"
                              data-confirm-ok="Ya, Hapus">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?= $this->endSection() ?>
