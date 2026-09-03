<?php
/**
 * Panel kanan, tahap verifikasi. Disuntikkan lewat fetch() dari send/form.php,
 * jadi tidak mengikat layout apa pun.
 *
 * @var array<string, mixed>            $draft
 * @var Modules\TukangKirim\Libraries\MaxChatService    $maxchat
 * @var array<string, mixed>|null       $nextAccount
 */
?>

<?php if ($draft['password'] !== null): ?>
    <div class="card border-success shadow-sm mb-3">
        <div class="card-header bg-success-subtle fw-semibold">
            <i class="bi bi-shield-lock-fill me-1"></i> Password yang dibuat
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="password-box" id="pwd-value"><?= esc($draft['password']) ?></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-password>
                    <i class="bi bi-clipboard me-1"></i> Salin
                </button>
            </div>
            <span class="text-muted small">
                <?= strlen($draft['password']) ?> karakter &middot; mengandung huruf kapital, huruf kecil,
                angka, dan karakter spesial. Password ini <strong>tidak disimpan</strong> di database.
            </span>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-warning-subtle d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span class="fw-semibold">Belum terkirim — periksa dulu</span>
    </div>
    <div class="card-body">
        <dl class="row mb-3 small">
            <dt class="col-4 text-muted fw-normal">Template</dt>
            <dd class="col-8"><?= esc($draft['template_name']) ?></dd>

            <dt class="col-4 text-muted fw-normal">Nomor diketik</dt>
            <dd class="col-8"><?= esc($draft['recipient_input']) ?></dd>

            <dt class="col-4 text-muted fw-normal">Dikirim ke</dt>
            <dd class="col-8 mb-0">
                <span class="fw-semibold"><?= esc($draft['recipient_normalized']) ?></span>
                <span class="text-muted">(hasil normalisasi)</span>
            </dd>
        </dl>

        <div class="chat-canvas">
            <div class="bubble"><p class="message-preview"><?= esc($draft['text']) ?></p></div>
        </div>

        <p class="text-muted small mt-2 mb-0">
            Teks di atas inilah yang dikirim, apa adanya — tersimpan di server sejak preview ini dibuat.
        </p>
    </div>
</div>

<div class="d-grid mb-3">
    <button type="button" id="btn-dispatch"
            class="btn btn-lg <?= $maxchat->isDryRun() ? 'btn-warning' : 'btn-success' ?>"
            data-recipient="<?= esc($draft['recipient_normalized'], 'attr') ?>"
            data-confirm-title="<?= $maxchat->isDryRun() ? 'Jalankan uji coba?' : 'Kirim pesan sekarang?' ?>"
            data-confirm="<?= $maxchat->isDryRun()
                ? 'Mode uji coba aktif: pesan tidak benar-benar dikirim, hanya dicatat di riwayat sebagai dryrun.'
                : 'Pesan langsung dikirim ke nomor berikut dan tidak bisa ditarik kembali.' ?>"
            data-confirm-detail="<?= esc($draft['recipient_normalized'], 'attr') ?>"
            data-confirm-ok="<?= $maxchat->isDryRun() ? 'Ya, Jalankan' : 'Ya, Kirim' ?>"
            data-confirm-variant="<?= $maxchat->isDryRun() ? 'warning' : 'success' ?>">
        <i class="bi bi-send-fill me-1"></i>
        <?= $maxchat->isDryRun() ? 'Kirim (mode uji coba)' : 'Kirim Sekarang' ?>
    </button>
</div>

<?php if ($maxchat->isDryRun()): ?>
    <p class="text-muted small">
        <i class="bi bi-cone-striped"></i> Mode uji coba aktif (<code>maxchat.dryRun = true</code>):
        pesan tidak benar-benar dikirim, hanya dicatat di riwayat sebagai <code>dryrun</code>.
    </p>
<?php endif; ?>

<?php if ($nextAccount === null): ?>
    <div class="alert alert-danger">
        <strong>Belum ada akun MaxChat aktif.</strong>
        Pengiriman pasti gagal. Tambahkan atau aktifkan akun di menu
        <a href="<?= module_url('accounts') ?>" class="alert-link">Akun MaxChat</a> lebih dulu.
    </div>
<?php else: ?>
    <p class="text-muted small">
        <i class="bi bi-arrow-repeat"></i>
        Giliran kirim jatuh ke akun <strong><?= esc($nextAccount['name']) ?></strong>.
        Bila akun itu gagal, akun aktif berikutnya otomatis dicoba.
    </p>
<?php endif; ?>

<details>
    <summary class="text-muted small">
        Lihat payload yang akan dikirim
        <?= $nextAccount !== null ? 'ke ' . esc(rtrim($nextAccount['base_url'], '/')) . '/messages' : '' ?>
    </summary>
    <pre class="bg-dark text-light p-3 rounded mt-2 small"><code><?= esc(json_encode([
        'to'   => $draft['recipient_normalized'],
        'type' => 'text',
        'text' => $draft['text'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></pre>
</details>
