<?php
/**
 * Panel kanan, tahap konfirmasi. Disuntikkan lewat fetch() dari passphrase/form.php.
 *
 * Tepat SATU tombol aksi dirender, ditentukan $stage (dihitung controller):
 *
 *   decide  -> masih ada nomor yang berbeda dan belum diputuskan; kartu nomor
 *              menawarkan Perbarui/Lewati, dan tombol Reset dikunci. "Perbarui"
 *              menulis ke SEMUA sistem yang perlu — TTE dulu, lalu BSrE (simpan
 *              sekaligus setujui) — dalam satu tekan.
 *   approve -> jalur ulang: nomornya tersimpan, tetapi persetujuan otomatisnya
 *              gagal. Satu-satunya cara keadaan ini muncul.
 *   act     -> Reset Passphrase. Satu tombol, dua kemungkinan — persis seperti
 *              aksi di portal: bila nomor belum terverifikasi, yang muncul adalah
 *              dialog verifikasi; bila sudah, tautan reset langsung dikirim ke
 *              email pengguna. Yang berbeda hanyalah isi dialog konfirmasinya.
 *
 * @var array<string, mixed> $draft
 * @var string               $draftId
 * @var list<string>         $pending
 * @var array<string, bool>  $matches
 * @var string               $stage
 */

$verified = (bool) $draft['phoneVerified'];
$certs    = (int) ($draft['cert_count'] ?? 1);
?>

<div class="card border-primary shadow-sm mb-3">
    <div class="card-header bg-primary-subtle fw-semibold">
        <i class="bi bi-person-check-fill me-1"></i> Akun ditemukan
    </div>
    <div class="card-body">
        <?php if (! empty($draft['resolved_from'])): ?>
            <div class="alert alert-info py-2 px-3 small mb-3">
                <i class="bi bi-signpost-2 me-1"></i> Ditemukan via <?= esc($draft['resolved_from']) ?>
            </div>
        <?php endif; ?>
        <dl class="row mb-0 small">
            <dt class="col-4 text-muted fw-normal">Nama</dt>
            <dd class="col-8"><?= esc($draft['name']) ?></dd>

            <dt class="col-4 text-muted fw-normal">Email</dt>
            <dd class="col-8"><span class="fw-semibold"><?= esc($draft['email']) ?></span></dd>

            <?php if (! empty($draft['nik'])): ?>
                <dt class="col-4 text-muted fw-normal">NIK</dt>
                <dd class="col-8"><?= esc((string) $draft['nik']) ?></dd>
            <?php endif; ?>

            <?php $byLabels = ['email' => 'Email', 'nik' => 'NIK', 'nohp' => 'No HP']; ?>
            <dt class="col-4 text-muted fw-normal">Dicari via</dt>
            <dd class="col-8 mb-0">
                <span class="badge text-bg-secondary"><?= esc($byLabels[$draft['search_by'] ?? 'nohp'] ?? 'No HP') ?></span>
                <?= esc((string) ($draft['search_value'] ?? '')) ?>
            </dd>
        </dl>
    </div>
</div>

<?= $this->include(Modules\TukangAdmin\Config\Module::VIEWS . 'passphrase/_card_phone') ?>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold">
        <i class="bi bi-patch-check me-1"></i> Sertifikat Elektronik
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Reset akan dijalankan pada sertifikat aktif berikut
            <?= $certs > 1 ? '(pertama dari ' . $certs . ' sertifikat)' : '' ?>:
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

<?php if ($stage === 'approve'): ?>

    <div class="note-box mb-3">
        <i class="bi bi-arrow-clockwise me-1"></i>
        Persetujuan yang mestinya berjalan otomatis sesudah menyimpan nomor belum berhasil.
        Mencobanya lagi setara membuka <em>users/update/list</em>, mencari pengguna, lalu
        menekan Verifikasi — dan tidak mengirim ulang perubahan nomornya.
    </div>

    <div class="d-grid mb-3">
        <button type="button" id="btn-approve-update" class="btn btn-warning btn-lg"
                data-draft-id="<?= esc($draftId, 'attr') ?>"
                data-confirm-title="Coba setujui lagi?"
                data-confirm="Perubahan nomor HP yang sudah tersimpan akan disetujui, sehingga nomor barunya berlaku di BSrE."
                data-confirm-detail="<?= esc($draft['email'] . ' → ' . $draft['phone_normalized'], 'attr') ?>"
                data-confirm-ok="Ya, Setujui"
                data-confirm-variant="warning">
            <i class="bi bi-patch-check-fill me-1"></i> Coba Setujui Lagi
        </button>
    </div>

<?php else: ?>

    <?php if ($verified): ?>
        <div class="note-box mb-3">
            <i class="bi bi-envelope-check me-1"></i>
            Nomor HP sudah terverifikasi. Menekan tombol akan meminta BSrE mengirim
            <strong>tautan reset passphrase ke email dinas</strong> pengguna.
        </div>
    <?php else: ?>
        <div class="alert alert-warning small mb-3">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Nomor HP <strong>belum terverifikasi</strong>. Seperti di portal, menekan Reset
            Passphrase akan memunculkan konfirmasi verifikasi lebih dulu: BSrE mengirim
            <strong>tautan verifikasi ke WhatsApp pengguna</strong>, dan passphrase baru bisa
            direset setelah pengguna membukanya.
        </div>
    <?php endif; ?>

    <div class="d-grid mb-3">
        <button type="button" id="btn-dispatch" data-draft-id="<?= esc($draftId, 'attr') ?>"
                <?= $stage === 'decide' ? 'disabled data-needs-phone-decision="1"' : '' ?>
                class="btn btn-lg <?= $verified ? 'btn-danger' : 'btn-warning' ?>"
                data-confirm-title="<?= $verified ? 'Reset passphrase sekarang?' : 'Kirim tautan verifikasi HP?' ?>"
                data-confirm="<?= $verified
                    ? 'BSrE akan mengirim tautan reset passphrase ke email dinas pengguna.'
                    : 'Nomor HP pengguna belum terverifikasi. BSrE akan mengirim tautan verifikasi ke WhatsApp pengguna terlebih dahulu; passphrase belum direset pada langkah ini.' ?>"
                data-confirm-detail="<?= esc($verified
                    ? $draft['email']
                    : 'Tautan verifikasi → ' . (string) ($draft['phone_bsre'] ?? '(nomor kosong)'), 'attr') ?>"
                data-confirm-ok="<?= $verified ? 'Ya, Reset Passphrase' : 'Verifikasi' ?>"
                data-confirm-variant="<?= $verified ? 'danger' : 'warning' ?>">
            <i class="bi bi-key-fill me-1"></i> Reset Passphrase
        </button>
    </div>

    <?php if ($stage === 'decide'): ?>
        <p class="text-muted small mb-0">
            <i class="bi bi-lock me-1"></i>
            Terkunci sampai Anda memilih <strong>Perbarui</strong> atau <strong>Lewati</strong> pada
            kartu nomor di atas.
        </p>
    <?php endif; ?>

<?php endif; ?>
