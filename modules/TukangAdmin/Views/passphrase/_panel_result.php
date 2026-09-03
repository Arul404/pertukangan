<?php
/**
 * Panel kanan, tahap hasil. Disuntikkan lewat fetch() dari passphrase/form.php.
 *
 * @var array<string, mixed> $result
 */

$isVerify = ($result['outcome'] ?? '') === 'verify';
?>

<div class="card <?= $isVerify ? 'border-warning' : 'border-success' ?> shadow-sm mb-3">
    <div class="card-header <?= $isVerify ? 'bg-warning-subtle' : 'bg-success-subtle' ?> fw-semibold">
        <?php if ($isVerify): ?>
            <i class="bi bi-phone me-1"></i> Tautan verifikasi dikirim
        <?php else: ?>
            <i class="bi bi-check-circle-fill me-1"></i> Tautan reset passphrase dikirim
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="mb-3"><?= esc($result['message']) ?></p>

        <dl class="row mb-0 small">
            <dt class="col-4 text-muted fw-normal">Nama</dt>
            <dd class="col-8"><?= esc($result['name']) ?></dd>

            <dt class="col-4 text-muted fw-normal">Email</dt>
            <dd class="col-8"><span class="fw-semibold"><?= esc($result['email']) ?></span></dd>

            <?php if ($isVerify && $result['phone'] !== null): ?>
                <dt class="col-4 text-muted fw-normal">Nomor HP</dt>
                <dd class="col-8"><?= esc($result['phone']) ?></dd>
            <?php endif; ?>

            <dt class="col-4 text-muted fw-normal">Nomor Seri</dt>
            <dd class="col-8"><code><?= esc($result['serial']) ?></code></dd>
        </dl>
    </div>
</div>

<?php if ($isVerify): ?>
    <div class="note-box mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Pengguna perlu membuka tautan verifikasi di WhatsApp-nya lebih dulu. Setelah
        nomornya terverifikasi, ulangi pencarian email ini untuk melakukan reset passphrase.
    </div>
<?php endif; ?>

<div class="d-grid">
    <button type="button" id="btn-again" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-repeat me-1"></i> Reset akun lain
    </button>
</div>
