<?php
/**
 * Panel kanan saat pencarian lewat nomor HP tidak menemukan siapa pun di TTE.
 *
 * Bukan galat: ini keadaan yang wajar dan justru jadi alasan kolom cadangan
 * (NIK/email) muncul. Nomor yang diketik tetap dipakai sebagai acuan review
 * nomor di BSrE nanti.
 *
 * @var string $phone  nomor yang diketik operator
 * @var string $reason pesan asli dari TTE
 */
?>

<div class="card border-warning shadow-sm mb-3">
    <div class="card-header bg-warning-subtle fw-semibold">
        <i class="bi bi-search me-1"></i> Nomor tidak ditemukan di TTE
    </div>
    <div class="card-body">
        <p class="mb-2">
            Tidak ada akun penandatangan dengan nomor
            <strong><?= esc($phone) ?></strong> di TTE.
        </p>

        <p class="text-muted small mb-2">
            Portal BSrE tidak bisa dicari lewat nomor HP, jadi nomor dipakai menemukan
            emailnya di TTE lebih dulu. Kemungkinan penyebabnya:
        </p>
        <ul class="small mb-3">
            <li>Nomor yang tercatat di TTE berbeda atau kosong — inilah yang paling sering terjadi.</li>
            <li>Akunnya ada, tetapi rolenya bukan <em>penandatangan</em>.</li>
        </ul>

        <div class="alert alert-info py-2 px-3 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Nomor yang Anda ketik <strong>tetap dipakai</strong> sebagai acuan: pencarian lewat
            NIK atau email tetap melalui TTE, lalu nomor itu dibandingkan dengan yang tercatat di
            TTE maupun BSrE dan — bila perlu — disimpan ke keduanya. Itu pula yang membuat
            pencarian lewat No HP berhasil lain kali.
        </div>

        <p class="text-muted small mb-3">
            Balasan TTE: <em><?= esc($reason) ?></em>
        </p>

        <div class="d-grid">
            <button type="button" id="btn-focus-fallback" class="btn btn-primary">
                <i class="bi bi-person-vcard me-1"></i> Cari dengan NIK atau Email
            </button>
        </div>
    </div>
</div>
