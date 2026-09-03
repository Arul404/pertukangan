<?php
/**
 * Panel kanan, tahap hasil. Disuntikkan lewat fetch() dari reset/form.php.
 *
 * Kata sandi TTE pada tahap ini sudah PASTI berhasil diubah (kalau gagal,
 * controller mengembalikan panel error, bukan panel ini). Yang bisa berstatus
 * sukses/gagal di sini hanya pengiriman WhatsApp-nya.
 *
 * @var array<string, mixed> $result
 */
?>

<div class="alert alert-success d-flex align-items-start">
    <i class="bi bi-shield-check me-2 fs-4"></i>
    <div>
        <h2 class="h6 mb-1">Kata sandi TTE berhasil direset</h2>
        Akun <strong><?= esc($result['email']) ?></strong>
        (role <?= esc($result['role']) ?>) kini memakai kata sandi baru di bawah.
    </div>
</div>

<?php if ($result['status'] === 'success'): ?>
    <div class="alert alert-success d-flex align-items-start">
        <i class="bi bi-whatsapp me-2 fs-4"></i>
        <div>
            <h2 class="h6 mb-1">Kata sandi terkirim</h2>
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
            Kata sandi sudah diubah di TTE, tetapi pesan ke <strong><?= esc($result['recipient']) ?></strong>
            tidak benar-benar dikirim karena <code>maxchat.dryRun</code> bernilai <code>true</code>.
            <strong>Catat kata sandi di bawah dan sampaikan secara manual.</strong>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-danger d-flex align-items-start">
        <i class="bi bi-x-octagon-fill me-2 fs-4"></i>
        <div>
            <h2 class="h6 mb-1">Kata sandi sudah diubah, tetapi gagal terkirim</h2>
            <?= esc($result['error'] ?? 'Penyebab tidak diketahui.') ?>
            <?php if ($result['http_code'] !== null): ?>
                (HTTP <?= esc((string) $result['http_code']) ?>)
            <?php endif; ?>
            <strong class="d-block mt-1">Sampaikan kata sandi di bawah ke penerima secara manual.</strong>
        </div>
    </div>
<?php endif; ?>

<?php $failed = $result['failed'] ?? []; ?>
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

<div class="card border-success shadow-sm mb-3">
    <div class="card-header bg-success-subtle fw-semibold">
        <i class="bi bi-shield-lock-fill me-1"></i> Kata sandi baru — tampil sekali
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
            Tidak disimpan dalam bentuk asli dan akan hilang begitu panel ini ditutup.
        </p>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Isi pesan</div>
    <div class="card-body">
        <div class="chat-canvas">
            <div class="bubble"><p class="message-preview"><?= esc($result['text']) ?></p></div>
        </div>
    </div>
</div>

<div class="d-grid gap-2">
    <button type="button" id="btn-again" class="btn btn-primary">
        <i class="bi bi-arrow-repeat me-1"></i> Reset akun lain
    </button>
    <a href="<?= site_url('tukang-kirim/logs') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i> Lihat riwayat kirim
    </a>
</div>
