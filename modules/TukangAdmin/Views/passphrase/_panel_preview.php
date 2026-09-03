<?php
/**
 * Panel kanan, tahap konfirmasi. Disuntikkan lewat fetch() dari passphrase/form.php.
 *
 * @var array<string, mixed>            $draft
 * @var list<array<string, mixed>>      $certificates
 */

$verified = (bool) $draft['phoneVerified'];
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

            <dt class="col-4 text-muted fw-normal">Nomor HP</dt>
            <dd class="col-8"><?= $draft['phone'] !== null ? esc($draft['phone']) : '<span class="text-muted">— tidak terbaca —</span>' ?></dd>

            <dt class="col-4 text-muted fw-normal">Status HP</dt>
            <dd class="col-8">
                <?php if ($verified): ?>
                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Terverifikasi</span>
                <?php else: ?>
                    <span class="badge text-bg-warning"><i class="bi bi-exclamation-circle me-1"></i>Belum terverifikasi</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold">
        <i class="bi bi-patch-check me-1"></i> Sertifikat Elektronik
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Reset akan dijalankan pada sertifikat aktif berikut
            <?= count($certificates) > 1 ? '(pertama dari ' . count($certificates) . ' sertifikat)' : '' ?>:
        </p>
        <dl class="row mb-0 small">
            <dt class="col-4 text-muted fw-normal">Nomor Seri</dt>
            <dd class="col-8"><code><?= esc($draft['serial']) ?></code></dd>

            <?php if ($draft['jenis'] !== null): ?>
                <dt class="col-4 text-muted fw-normal">Jenis</dt>
                <dd class="col-8"><span class="badge text-bg-secondary"><?= esc($draft['jenis']) ?></span></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>

<?php if ($verified): ?>
    <div class="note-box mb-3">
        <i class="bi bi-envelope-check me-1"></i>
        Nomor HP sudah terverifikasi. Menekan tombol akan meminta BSrE mengirim
        <strong>tautan reset passphrase ke email dinas</strong> pengguna.
    </div>
<?php else: ?>
    <div class="alert alert-warning small mb-3">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        Nomor HP <strong>belum terverifikasi</strong>. Menekan tombol akan meminta BSrE
        mengirim <strong>tautan verifikasi ke WhatsApp pengguna</strong> lebih dulu.
        Reset passphrase baru bisa dilakukan setelah pengguna memverifikasi nomornya.
    </div>
<?php endif; ?>

<div class="d-grid mb-3">
    <button type="button" id="btn-dispatch"
            class="btn btn-lg <?= $verified ? 'btn-danger' : 'btn-warning' ?>"
            data-confirm-title="<?= $verified ? 'Kirim tautan reset passphrase?' : 'Kirim tautan verifikasi HP?' ?>"
            data-confirm="<?= $verified
                ? 'BSrE akan mengirim tautan reset passphrase ke email dinas pengguna.'
                : 'Nomor HP pengguna belum terverifikasi. BSrE akan mengirim tautan verifikasi ke WhatsApp pengguna terlebih dahulu.' ?>"
            data-confirm-detail="<?= esc($draft['email'] . ($draft['phone'] !== null ? ' → ' . $draft['phone'] : ''), 'attr') ?>"
            data-confirm-ok="<?= $verified ? 'Ya, Kirim Tautan Reset' : 'Ya, Kirim Verifikasi' ?>"
            data-confirm-variant="<?= $verified ? 'danger' : 'warning' ?>">
        <?php if ($verified): ?>
            <i class="bi bi-key-fill me-1"></i> Reset Passphrase
        <?php else: ?>
            <i class="bi bi-phone me-1"></i> Kirim Verifikasi HP
        <?php endif; ?>
    </button>
</div>
