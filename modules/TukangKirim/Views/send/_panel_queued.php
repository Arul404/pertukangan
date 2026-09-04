<?php
/**
 * Panel kanan, hasil untuk pengiriman via ANTREAN.
 *
 * Pesan dimasukkan antrean dan dikirim worker satu-per-satu dengan jeda
 * (anti-banned). Kata sandi (bila ada) tetap ditampilkan sekali di sini.
 *
 * @var array<string, mixed> $result
 */
?>

<div class="alert alert-info d-flex align-items-start">
    <i class="bi bi-hourglass-split me-2 fs-4"></i>
    <div>
        <h2 class="h6 mb-1">Pesan masuk antrean</h2>
        Akan dikirim ke <strong><?= esc($result['recipient']) ?></strong> oleh worker,
        satu-per-satu dengan jeda antar-kirim agar tidak diblokir WhatsApp.
        <div class="small mt-1"><?= (int) $result['pending'] ?> pesan menunggu di antrean.</div>
    </div>
</div>

<div class="alert alert-warning d-flex align-items-start py-2">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <div class="small">
        Pastikan worker berjalan: <code>php spark tukangkirim:work</code>. Tanpa itu, pesan
        tetap tersimpan di antrean tetapi tidak akan terkirim.
    </div>
</div>

<?php if (! empty($result['password'])): ?>
    <div class="card border-success shadow-sm mb-3">
        <div class="card-header bg-success-subtle fw-semibold">
            <i class="bi bi-shield-lock-fill me-1"></i> Kata sandi — tampil sekali
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
<?php endif; ?>

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
        <i class="bi bi-send me-1"></i> Kirim pesan lain
    </button>
    <a href="<?= module_url('logs') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i> Lihat riwayat
    </a>
</div>
