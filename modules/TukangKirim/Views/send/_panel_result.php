<?php
/**
 * Panel kanan, tahap hasil. Disuntikkan lewat fetch() dari send/form.php.
 *
 * @var array<string, mixed> $result
 */
?>
<?php if ($result['status'] === 'success'): ?>
    <div class="alert alert-success d-flex align-items-start">
        <i class="bi bi-check-circle-fill me-2 fs-4"></i>
        <div>
            <h2 class="h6 mb-1">Pesan terkirim</h2>
            Terkirim ke <strong><?= esc($result['recipient']) ?></strong>
            <?php if (! empty($result['account'])): ?>
                lewat akun <strong><?= esc($result['account']) ?></strong>
            <?php endif; ?>
            (HTTP <?= esc((string) $result['http_code']) ?>).
        </div>
    </div>
<?php elseif ($result['status'] === 'dryrun'): ?>
    <div class="alert alert-warning d-flex align-items-start">
        <i class="bi bi-cone-striped me-2 fs-4"></i>
        <div>
            <h2 class="h6 mb-1">Mode uji coba — pesan TIDAK dikirim</h2>
            Payload di bawah ini yang <em>akan</em> dikirim ke <strong><?= esc($result['recipient']) ?></strong>
            bila <code>maxchat.dryRun</code> diubah menjadi <code>false</code> di berkas <code>.env</code>.
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-danger d-flex align-items-start">
        <i class="bi bi-x-octagon-fill me-2 fs-4"></i>
        <div>
            <h2 class="h6 mb-1">Pengiriman gagal</h2>
            <?= esc($result['error'] ?? 'Penyebab tidak diketahui.') ?>
            <?php if ($result['http_code'] !== null): ?>
                (HTTP <?= esc((string) $result['http_code']) ?>)
            <?php endif; ?>
            <?php if (count($result['failed'] ?? []) > 1): ?>
                <div class="mt-1">Seluruh <?= count($result['failed']) ?> akun aktif sudah dicoba dan semuanya gagal.</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
    // Percobaan yang gagal tapi akhirnya tertutup akun lain: bukti mitigasi
    // bekerja, sekaligus penanda akun mana yang perlu diperiksa.
    $failed = $result['failed'] ?? [];
?>
<?php if ($failed !== [] && $result['status'] !== 'failed'): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1">
            <i class="bi bi-arrow-repeat me-1"></i>
            Dialihkan setelah <?= count($failed) ?> akun gagal
        </div>
        <ul class="mb-0 small">
            <?php foreach ($failed as $attempt): ?>
                <li><strong><?= esc($attempt['account']) ?></strong>: <?= esc($attempt['error']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (! empty($result['password'])): ?>
    <div class="card border-success shadow-sm mb-3">
        <div class="card-header bg-success-subtle fw-semibold">
            <i class="bi bi-shield-lock-fill me-1"></i> Password yang dikirim — tampil sekali
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="password-box" id="pwd-value"><?= esc($result['password']) ?></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-password>
                    <i class="bi bi-clipboard me-1"></i> Salin
                </button>
            </div>
            <p class="text-danger small mb-0 mt-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Password ini tidak disimpan di database dan akan hilang begitu panel ini ditutup.
                Catat sekarang bila masih dibutuhkan.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Isi pesan</div>
    <div class="card-body">
        <div class="chat-canvas">
            <div class="bubble"><p class="message-preview"><?= esc($result['text']) ?></p></div>
        </div>
    </div>
</div>

<?php if (! empty($result['body'])): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">
            <?= $result['status'] === 'dryrun' ? 'Payload (tidak dikirim)' : 'Respons MaxChat' ?>
        </div>
        <div class="card-body">
            <pre class="bg-dark text-light p-3 rounded small mb-0"><code><?= esc($result['body']) ?></code></pre>
        </div>
    </div>
<?php endif; ?>

<div class="d-grid gap-2">
    <button type="button" id="btn-again" class="btn btn-primary">
        <i class="bi bi-send me-1"></i> Kirim pesan lain
    </button>
    <a href="<?= module_url('logs') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i> Lihat riwayat
    </a>
</div>
