<?php
/**
 * Panel kanan, tahap konfirmasi. Disuntikkan lewat fetch() dari reset/form.php.
 *
 * @var array<string, mixed>                          $draft
 * @var Modules\TukangKirim\Libraries\MaxChatService  $maxchat
 * @var array<string, mixed>|null                     $nextAccount
 */
?>

<div class="card border-primary shadow-sm mb-3">
    <div class="card-header bg-primary-subtle fw-semibold">
        <i class="bi bi-person-check-fill me-1"></i> Akun ditemukan
    </div>
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-4 text-muted fw-normal">Nama</dt>
            <dd class="col-8"><?= esc($draft['name']) ?></dd>

            <dt class="col-4 text-muted fw-normal">Email</dt>
            <dd class="col-8"><span class="fw-semibold"><?= esc($draft['email']) ?></span></dd>

            <dt class="col-4 text-muted fw-normal">Role</dt>
            <dd class="col-8"><span class="badge text-bg-secondary"><?= esc($draft['role']) ?></span></dd>
        </dl>
    </div>
</div>

<div class="card border-success shadow-sm mb-3">
    <div class="card-header bg-success-subtle fw-semibold">
        <i class="bi bi-shield-lock-fill me-1"></i> Kata sandi baru yang dibuat
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="password-box" id="pwd-value"><?= esc($draft['password']) ?></span>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-password>
                <i class="bi bi-clipboard me-1"></i> Salin
            </button>
        </div>
        <span class="text-muted small">
            <?= strlen($draft['password']) ?> karakter &middot; huruf kapital, huruf kecil, angka, dan
            karakter spesial. <strong>Belum diterapkan</strong> — kata sandi TTE baru diubah setelah Anda
            menekan tombol di bawah.
        </span>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-warning-subtle d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span class="fw-semibold">Pesan yang akan dikirim</span>
    </div>
    <div class="card-body">
        <dl class="row mb-3 small">
            <dt class="col-4 text-muted fw-normal">Dicari via</dt>
            <dd class="col-8">
                <span class="badge text-bg-secondary"><?= strtoupper(esc($draft['search_by'] ?? 'email')) ?></span>
                <?= esc($draft['search_value'] ?? '') ?>
            </dd>

            <dt class="col-4 text-muted fw-normal">Nomor ditemukan</dt>
            <dd class="col-8"><?= esc($draft['phone_input']) ?></dd>

            <dt class="col-4 text-muted fw-normal">Dikirim ke</dt>
            <dd class="col-8 mb-0">
                <span class="fw-semibold"><?= esc($draft['phone_normalized']) ?></span>
                <span class="text-muted">(hasil normalisasi)</span>
            </dd>
        </dl>

        <div class="chat-canvas">
            <div class="bubble"><p class="message-preview"><?= esc($draft['text']) ?></p></div>
        </div>
    </div>
</div>

<div class="d-grid mb-3">
    <button type="button" id="btn-dispatch"
            class="btn btn-lg <?= $maxchat->isDryRun() ? 'btn-warning' : 'btn-danger' ?>"
            data-confirm-title="Reset kata sandi sekarang?"
            data-confirm="<?= $maxchat->isDryRun()
                ? 'Mode uji coba kirim aktif: kata sandi TTE TETAP diubah, tetapi pesan WhatsApp tidak benar-benar dikirim (hanya dicatat sebagai dryrun).'
                : 'Kata sandi akun TTE ini akan benar-benar diganti dan dikirim ke nomor tujuan. Tindakan ini tidak bisa dibatalkan.' ?>"
            data-confirm-detail="<?= esc($draft['email'] . ' → ' . $draft['phone_normalized'], 'attr') ?>"
            data-confirm-ok="Ya, Reset &amp; Kirim"
            data-confirm-variant="<?= $maxchat->isDryRun() ? 'warning' : 'danger' ?>">
        <i class="bi bi-key-fill me-1"></i> Reset &amp; Kirim
    </button>
</div>

<?php if ($nextAccount === null): ?>
    <div class="alert alert-warning small mb-0">
        <strong>Belum ada akun MaxChat aktif.</strong>
        Kata sandi tetap akan diubah di TTE, tetapi pesannya gagal terkirim.
    </div>
<?php else: ?>
    <p class="text-muted small mb-0">
        <i class="bi bi-arrow-repeat"></i>
        Pengiriman lewat akun <strong><?= esc($nextAccount['name']) ?></strong>.
        Bila gagal, akun aktif berikutnya otomatis dicoba.
    </p>
<?php endif; ?>
