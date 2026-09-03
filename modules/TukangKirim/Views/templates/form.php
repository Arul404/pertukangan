<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    $isEdit = $template !== null;
$action     = $isEdit ? module_url('templates/' . $template['id'] . '/update') : module_url('templates/store');
$value      = static fn (string $field, $fallback = '') => old($field) ?? ($template[$field] ?? $fallback);
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <h1 class="h3 mb-3"><?= $isEdit ? 'Ubah Template' : 'Template Baru' ?></h1>

        <form method="post" action="<?= $action ?>" class="card shadow-sm">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="name">Nama template <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" required
                           value="<?= esc($value('name')) ?>" placeholder="Reset Password - Standar">
                </div>

                <div class="mb-2">
                    <label class="form-label" for="body">Isi pesan <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="body" name="body" rows="10" required
                              placeholder="Halo [nama], password baru Anda: [password]"><?= esc($value('body')) ?></textarea>
                </div>

                <div class="alert alert-info small">
                    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Cara pakai placeholder</div>
                    Tulis <code class="placeholder-chip">[nama_apa_saja]</code> di dalam teks; saat mengirim,
                    setiap placeholder otomatis menjadi kolom isian.
                    Khusus <code class="placeholder-chip">[password]</code> nilainya
                    <strong>dibuat otomatis</strong> oleh sistem (minimal 10 karakter, mengandung huruf kapital,
                    angka, dan karakter spesial) dan tidak perlu diisi manual.
                </div>

                <div class="mb-0">
                    <span class="form-label d-block mb-1">Placeholder terdeteksi</span>
                    <div id="detected" class="d-flex flex-wrap gap-1"><span class="text-muted small">—</span></div>
                </div>
            </div>
            <div class="card-footer bg-white d-flex gap-2 align-items-center">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Simpan</button>
                <a href="<?= module_url('templates') ?>" class="btn btn-outline-secondary">Batal</a>
                <div class="form-check form-switch ms-auto mb-0">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                        <?= (int) $value('is_active', 1) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Aktif</label>
                </div>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const body     = document.getElementById('body');
    const detected = document.getElementById('detected');

    // Cerminan sisi klien dari Modules\TukangKirim\Libraries\PlaceholderParser::extract().
    function refresh() {
        const names = [...new Set([...body.value.matchAll(/\[([a-zA-Z0-9_]+)\]/g)].map(m => m[1].toLowerCase()))];

        if (names.length === 0) {
            detected.innerHTML = '<span class="text-muted small">— tidak ada placeholder —</span>';
            return;
        }

        detected.innerHTML = names.map(name => {
            const auto = name === 'password';
            return '<code class="placeholder-chip' + (auto ? ' bg-success-subtle text-success-emphasis' : '') + '">[' +
                name + ']' + (auto ? ' otomatis' : ' isi manual') + '</code>';
        }).join(' ');
    }

    body.addEventListener('input', refresh);
    refresh();
</script>
<?= $this->endSection() ?>
