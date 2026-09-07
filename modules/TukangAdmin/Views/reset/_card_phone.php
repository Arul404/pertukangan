<?php
/**
 * Kartu verifikasi nomor: yang diketik operator vs yang tercatat di TTE.
 *
 * Selalu ditampilkan, termasuk saat keduanya cocok. Kotak pencarian TTE (`?q=`)
 * adalah teks bebas, jadi hasil pencarian lewat nomor pun bisa saja cocok di
 * kolom lain (nama/NIK/email yang memuat digit itu) dan membawa nomor WhatsApp
 * yang berbeda — justru itu yang perlu dilihat mata operator.
 *
 * @var array<string, mixed> $draft
 * @var bool                $phoneMatches hasil perbandingan (dihitung controller)
 */

$typed     = (string) $draft['phone_normalized'];
$tte       = $draft['phone_tte'] ?? null;
$sama      = $phoneMatches;
$updated   = ! empty($draft['wa_updated']);
$canUpdate = ! empty($draft['edit_url']);
$perlu     = ! $updated && ! $sama;
?>

<div class="card shadow-sm mb-3 <?= $perlu && $canUpdate ? 'border-warning' : '' ?>">
    <div class="card-header fw-semibold <?= $updated || $sama ? 'bg-success-subtle' : 'bg-warning-subtle' ?>">
        <i class="bi bi-phone me-1"></i> Verifikasi nomor WhatsApp
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-6">
                <div class="text-muted small">Anda ketik</div>
                <div class="fw-semibold"><?= esc($draft['phone_typed'] ?? $draft['phone_input']) ?></div>
                <div class="text-muted small"><?= esc($typed) ?></div>
            </div>
            <div class="col-6">
                <div class="text-muted small">Tercatat di TTE</div>
                <?php if ($tte === null || $tte === ''): ?>
                    <div class="text-muted fst-italic">— tidak terbaca —</div>
                <?php else: ?>
                    <div class="fw-semibold <?= $sama ? '' : 'text-warning-emphasis' ?>"><?= esc($tte) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($updated): ?>
            <div class="alert alert-success py-2 px-3 small mb-0">
                <i class="bi bi-check-circle-fill me-1"></i>
                Nomor di TTE sudah diperbarui menjadi <strong><?= esc((string) $tte) ?></strong>.
            </div>
            <?php foreach ($draft['wa_notes'] ?? [] as $note): ?>
                <div class="alert alert-warning py-2 px-3 small mb-0 mt-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= esc($note) ?>
                </div>
            <?php endforeach; ?>

        <?php elseif ($sama): ?>
            <div class="alert alert-success py-2 px-3 small mb-0">
                <i class="bi bi-check-circle-fill me-1"></i>
                Nomor cocok dengan yang tercatat di TTE. Tidak ada yang perlu diubah.
            </div>

        <?php elseif (! $canUpdate): ?>
            <div class="alert alert-info py-2 px-3 small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Tombol "ubah" tidak terbaca pada baris pengguna di TTE, jadi nomornya tidak bisa
                diperbarui dari sini. Pengiriman tetap berjalan ke nomor yang Anda ketik.
            </div>

        <?php else: ?>
            <div data-wa-decision>
                <p class="small mb-2">
                    Nomor berbeda. Anda bisa memperbarui data di TTE sekarang, atau melewatinya —
                    pilihan ini <strong>tidak</strong> mengubah tujuan pengiriman.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" id="btn-update-wa" class="btn btn-warning btn-sm"
                            data-draft-id="<?= esc($draftId ?? '', 'attr') ?>"
                            data-confirm-title="Perbarui nomor WhatsApp di TTE?"
                            data-confirm="Data pengguna di TTE akan disimpan ulang dengan nomor baru. Kolom lain dikirim ulang apa adanya."
                            data-confirm-detail="<?= esc(((string) ($tte ?? '(kosong)')) . ' → ' . ($draft['phone_typed'] ?? ''), 'attr') ?>"
                            data-confirm-ok="Ya, Perbarui"
                            data-confirm-variant="warning">
                        <i class="bi bi-pencil-square me-1"></i> Perbarui nomor di TTE
                    </button>
                    <button type="button" id="btn-skip-wa" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-skip-forward me-1"></i> Lewati
                    </button>
                </div>
            </div>
            <div data-wa-skipped hidden>
                <div class="alert alert-secondary py-2 px-3 small mb-0">
                    <i class="bi bi-skip-forward me-1"></i>
                    Dilewati — nomor di TTE tidak diubah.
                </div>
            </div>
        <?php endif; ?>

        <p class="text-muted small mb-0 mt-3">
            <i class="bi bi-send me-1"></i>
            Pesan dikirim ke <strong><?= esc($typed) ?></strong> (nomor yang Anda ketik),
            apa pun pilihan di atas.
        </p>
    </div>
</div>
