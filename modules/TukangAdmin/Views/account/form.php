<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    /**
     * @var array<string, mixed>|null $credential
     * @var string                    $baseUrl
     * @var bool                      $hasEncryptKey
     */
    $isEdit = $credential !== null;
?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
            <div>
                <h1 class="h3 mb-0">Akun TTE</h1>
            </div>
        </div>

        <?php if (! $hasEncryptKey): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>Kunci enkripsi belum diset.</strong> Kata sandi TTE terpaksa disimpan tanpa
                enkripsi. Setel <code>encryption.key</code> di berkas <code>.env</code>
                (mis. lewat <code>php spark key:generate</code>) lalu simpan ulang kredensial.
            </div>
        <?php endif; ?>

        <form method="post" action="<?= module_url('akun/save') ?>" class="card shadow-sm" id="tte-form">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="username">Username / Email <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="username" name="username" required
                           value="<?= esc(old('username') ?? ($credential['username'] ?? '')) ?>"
                           placeholder="nama@domain.go.id" autocomplete="off">
                    <div class="form-text">Sesuai yang diketik di kolom "Email" pada halaman login TTE.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">
                        Kata sandi <?= $isEdit ? '' : '<span class="text-danger">*</span>' ?>
                    </label>
                    <input type="password" class="form-control" id="password" name="password"
                           <?= $isEdit ? '' : 'required' ?> autocomplete="new-password"
                           placeholder="<?= $isEdit ? 'Kosongkan bila tidak diubah' : 'Kata sandi login TTE' ?>">
                    <div class="form-text">
                        <?= $isEdit
                            ? 'Sudah tersimpan. Isi hanya bila ingin menggantinya.'
                            : 'Disimpan terenkripsi dan hanya dipakai untuk login otomatis ke TTE.' ?>
                    </div>
                </div>

                <div class="mb-1">
                    <label class="form-label" for="base_url">Base URL (opsional)</label>
                    <input type="text" class="form-control" id="base_url" name="base_url"
                           value="<?= esc(old('base_url') ?? ($credential['base_url'] ?? '')) ?>"
                           placeholder="<?= esc($baseUrl) ?>">
                    <div class="form-text">Kosongkan untuk memakai default: <code><?= esc($baseUrl) ?></code>.</div>
                </div>
            </div>
            <div class="card-footer bg-white d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i> Simpan</button>
                <?php if ($isEdit): ?>
                    <button class="btn btn-outline-secondary" type="button" id="btn-test">
                        <i class="bi bi-plug me-1"></i> Uji Login
                    </button>
                    <span id="test-result" class="align-self-center small"></span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const TEST_URL  = <?= json_encode(module_url('akun/test')) ?>;
    const CSRF_NAME = <?= json_encode(csrf_token()) ?>;
    const testBtn   = document.getElementById('btn-test');

    if (testBtn) {
        const resultEl = document.getElementById('test-result');

        testBtn.addEventListener('click', async () => {
            const original = testBtn.innerHTML;
            testBtn.disabled = true;
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menguji…';
            resultEl.textContent = '';
            resultEl.className = 'align-self-center small';

            const body = new FormData();
            body.append(CSRF_NAME, document.querySelector('input[name="' + CSRF_NAME + '"]').value);

            try {
                const response = await fetch(TEST_URL, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: body,
                });
                const data = await response.json();

                if (data.csrf) {
                    document.querySelector('input[name="' + CSRF_NAME + '"]').value = data.csrf;
                }

                if (data.ok) {
                    resultEl.textContent = '✓ Login berhasil.';
                    resultEl.classList.add('text-success');
                } else {
                    resultEl.textContent = '✗ ' + (data.error || 'Login gagal.');
                    resultEl.classList.add('text-danger');
                }
            } catch (error) {
                resultEl.textContent = '✗ Gagal menghubungi server: ' + error.message;
                resultEl.classList.add('text-danger');
            } finally {
                testBtn.disabled = false;
                testBtn.innerHTML = original;
            }
        });
    }
</script>
<?= $this->endSection() ?>
