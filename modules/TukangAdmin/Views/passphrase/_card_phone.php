<?php
/**
 * Kartu verifikasi nomor: yang diketik operator vs yang tercatat di TTE & BSrE.
 *
 * Dua sistem, satu nomor. TTE ditaruh lebih dulu karena di situlah alurnya
 * bermula — nomor yang usang di TTE adalah sebab paling sering pencarian lewat
 * nomor gagal — sementara BSrE-lah yang menentukan ke mana tautan verifikasi
 * dikirim. Keduanya diperlihatkan terpisah lengkap dengan tanda cocok/berbeda
 * masing-masing: satu badge gabungan justru menyembunyikan hal yang perlu
 * dilihat operator.
 *
 * Sepupu dari reset/_card_phone.php, dengan satu perbedaan yang menentukan:
 * di Reset Password, "Lewati" tidak berakibat apa-apa karena pesan tetap dikirim
 * ke nomor yang diketik operator. Di sini tidak begitu — melewati berarti tautan
 * verifikasi WhatsApp dikirim BSrE ke nomor LAMA, dan pengguna tidak akan pernah
 * menerimanya. Karena itu peringatannya dinyatakan terang-terangan, di kartunya
 * maupun di dialog konfirmasi tombolnya.
 *
 * @var array<string, mixed> $draft
 * @var string               $draftId
 * @var list<string>         $pending sistem yang nomornya masih perlu ditulis
 * @var array<string, bool>  $matches kecocokan per sistem (dihitung controller)
 * @var string               $stage   decide | approve | act
 */

$typed    = (string) $draft['phone_normalized'];
$tte      = $draft['phone_tte'] ?? null;
$bsre     = $draft['phone_bsre'] ?? null;
$verified = (bool) $draft['phoneVerified'];
$tteError = trim((string) ($draft['tte_error'] ?? ''));
$gagal    = trim((string) ($draft['approve_error'] ?? ''));
$skipped  = ! empty($draft['wa_skipped']);

$perluTte  = in_array('tte', $pending, true);
$perluBsre = in_array('bsre', $pending, true);

// Satu tombol; isinya menyesuaikan apa yang memang perlu diperbaiki.
if ($perluTte && $perluBsre) {
    $aksiLabel   = 'Perbarui nomor di TTE &amp; BSrE';
    $aksiTitle   = 'Perbarui nomor HP di TTE dan BSrE?';
    $aksiConfirm = 'Nomor di TTE disimpan ulang lebih dulu, lalu data akun di BSrE disimpan dengan '
        . 'nomor baru dan perubahannya langsung disetujui — sehingga berlaku saat itu juga.';
} elseif ($perluTte) {
    $aksiLabel   = 'Perbarui nomor di TTE';
    $aksiTitle   = 'Perbarui nomor HP di TTE?';
    $aksiConfirm = 'Data pengguna di TTE disimpan ulang dengan nomor baru. Kolom lain dikirim ulang '
        . 'apa adanya.';
} else {
    $aksiLabel   = 'Perbarui &amp; setujui nomor di BSrE';
    $aksiTitle   = 'Perbarui &amp; setujui nomor HP di BSrE?';
    $aksiConfirm = 'Data akun disimpan ulang dengan nomor baru, lalu perubahannya langsung disetujui '
        . 'di daftar perubahan data akun — sehingga nomor barunya berlaku saat itu juga.';
}

$aksiDetail = ($perluTte ? 'TTE: ' . ((string) ($tte ?? '(kosong)')) . ' → ' . $typed : '')
    . ($perluTte && $perluBsre ? ' · ' : '')
    . ($perluBsre ? 'BSrE: ' . ((string) ($bsre ?? '(kosong)')) . ' → ' . $typed : '');

// "Lewati" berakibat berbeda tergantung apa yang dilewatkan. Nomor BSrE yang
// belum terverifikasi satu-satunya yang benar-benar membuat kiriman nyasar,
// jadi hanya itu yang diperingatkan keras.
if (! $perluBsre) {
    $lewatiConfirm = 'Nomor di TTE dibiarkan apa adanya. Reset passphrase tidak terpengaruh, tetapi '
        . 'pencarian lewat No HP untuk pengguna ini akan gagal lagi lain kali.';
} elseif ($verified) {
    $lewatiConfirm = 'Nomor dibiarkan apa adanya. Nomor di BSrE sudah terverifikasi, jadi reset '
        . 'passphrase tetap bisa dijalankan.';
} else {
    $lewatiConfirm = 'Nomor dibiarkan apa adanya. Karena nomor di BSrE BELUM terverifikasi, tautan '
        . 'verifikasi akan dikirim ke nomor yang tercatat di sana — bukan ke nomor yang Anda ketik, '
        . 'sehingga pengguna kemungkinan besar tidak akan menerimanya.';
}
?>

<div class="card shadow-sm mb-3 <?= $stage === 'decide' ? 'border-warning' : '' ?>">
    <div class="card-header fw-semibold <?= $pending === [] ? 'bg-success-subtle' : 'bg-warning-subtle' ?>">
        <i class="bi bi-phone me-1"></i> Nomor HP pengguna
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="text-muted small">Anda ketik</div>
            <div class="fw-semibold fs-5"><?= esc($draft['phone_typed'] ?? $draft['phone_input']) ?></div>
            <div class="text-muted small"><?= esc($typed) ?></div>
        </div>

        <dl class="row g-0 mb-3 small">
            <dt class="col-4 text-muted fw-normal py-1">Tercatat di TTE</dt>
            <dd class="col-8 py-1 mb-0">
                <?php if ($tte === null || $tte === ''): ?>
                    <span class="text-muted fst-italic">— tidak terbaca —</span>
                <?php else: ?>
                    <span class="fw-semibold <?= $matches['tte'] ? '' : 'text-warning-emphasis' ?>"><?= esc((string) $tte) ?></span>
                    <?php if (! $matches['tte']): ?>
                        <span class="badge text-bg-warning ms-1">berbeda</span>
                    <?php endif; ?>
                <?php endif; ?>
            </dd>

            <dt class="col-4 text-muted fw-normal py-1">Tercatat di BSrE</dt>
            <dd class="col-8 py-1 mb-0">
                <?php if ($bsre === null || $bsre === ''): ?>
                    <span class="text-muted fst-italic">— tidak terbaca —</span>
                <?php else: ?>
                    <span class="fw-semibold <?= $matches['bsre'] ? '' : 'text-warning-emphasis' ?>"><?= esc((string) $bsre) ?></span>
                    <?php if (! $matches['bsre']): ?>
                        <span class="badge text-bg-warning ms-1">berbeda</span>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="mt-1">
                    <?php if ($verified): ?>
                        <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Terverifikasi</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning"><i class="bi bi-exclamation-circle me-1"></i>Belum terverifikasi</span>
                    <?php endif; ?>
                </div>
            </dd>
        </dl>

        <?php if ($tteError !== ''): ?>
            <div class="alert alert-warning py-2 px-3 small mb-2">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Nomor di TTE tidak bisa diperiksa atau diperbarui: <?= esc($tteError) ?>
                <div class="mt-1">
                    Reset passphrase tetap bisa dijalankan — perbaiki nomornya di TTE secara manual.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($draft['notes'] ?? [] as $note): ?>
            <div class="alert alert-info py-2 px-3 small mb-2">
                <i class="bi bi-info-circle me-1"></i> <?= esc($note) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($stage === 'decide'): ?>
            <?php if ($perluBsre): ?>
                <p class="small mb-2">
                    Nomor berbeda. Selama belum diperbarui, tautan verifikasi akan dikirim BSrE ke
                    <strong><?= esc($bsre !== null && $bsre !== '' ? (string) $bsre : '(nomor kosong)') ?></strong> —
                    bukan ke nomor yang Anda ketik.
                </p>
            <?php else: ?>
                <p class="small mb-2">
                    Nomor di BSrE sudah benar, tetapi <strong>TTE masih menyimpan nomor lama</strong> —
                    itulah yang membuat pencarian lewat No HP gagal menemukan pengguna ini.
                </p>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" id="btn-update-phone" class="btn btn-warning btn-sm"
                        data-draft-id="<?= esc($draftId, 'attr') ?>"
                        data-confirm-title="<?= $aksiTitle ?>"
                        data-confirm="<?= $aksiConfirm ?>"
                        data-confirm-detail="<?= esc($aksiDetail, 'attr') ?>"
                        data-confirm-ok="Ya, Perbarui"
                        data-confirm-variant="warning">
                    <i class="bi bi-pencil-square me-1"></i> <?= $aksiLabel ?>
                </button>
                <button type="button" id="btn-skip-update" class="btn btn-outline-secondary btn-sm"
                        data-draft-id="<?= esc($draftId, 'attr') ?>"
                        data-confirm-title="Lewati perbaikan nomor?"
                        data-confirm="<?= $lewatiConfirm ?>"
                        data-confirm-detail="<?= esc($perluBsre
                            ? 'Tautan dikirim ke ' . ((string) ($bsre ?? '(kosong)'))
                            : 'Nomor di TTE tetap ' . ((string) ($tte ?? '(kosong)')), 'attr') ?>"
                        data-confirm-ok="Ya, Lewati"
                        data-confirm-variant="<?= $perluBsre && ! $verified ? 'warning' : 'secondary' ?>">
                    <i class="bi bi-skip-forward me-1"></i> Lewati
                </button>
            </div>

        <?php elseif ($stage === 'approve'): ?>
            <div class="alert alert-danger py-2 px-3 small mb-2">
                <i class="bi bi-exclamation-octagon-fill me-1"></i>
                Nomor baru <strong>sudah tersimpan</strong> di BSrE, tetapi persetujuannya gagal —
                jadi perubahannya <strong>belum berlaku</strong>.
                <?php if ($gagal !== ''): ?>
                    <div class="mt-1"><?= esc($gagal) ?></div>
                <?php endif; ?>
                <div class="mt-1">Nomornya tidak perlu dikirim ulang; cukup ulangi persetujuannya.</div>
            </div>

        <?php elseif ($skipped): ?>
            <div class="alert alert-secondary py-2 px-3 small mb-0">
                <i class="bi bi-skip-forward me-1"></i>
                Dilewati — nomor tidak diubah.
            </div>

        <?php elseif (! empty($draft['tte_updated']) || ! empty($draft['update_approved'])): ?>
            <div class="alert alert-success py-2 px-3 small mb-0">
                <i class="bi bi-check-circle-fill me-1"></i>
                <?php if (! empty($draft['tte_updated']) && ! empty($draft['update_approved'])): ?>
                    Nomor sudah diperbarui di TTE dan disetujui di BSrE — keduanya berlaku.
                <?php elseif (! empty($draft['update_approved'])): ?>
                    Perubahan nomor sudah disetujui dan berlaku di BSrE.
                <?php else: ?>
                    Nomor di TTE sudah diperbarui.
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="alert alert-success py-2 px-3 small mb-0">
                <i class="bi bi-check-circle-fill me-1"></i>
                Nomor cocok dengan yang tercatat di TTE dan BSrE. Tidak ada yang perlu diubah.
            </div>
        <?php endif; ?>
    </div>
</div>
