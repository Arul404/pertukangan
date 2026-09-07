<?php
/**
 * Isi <tbody> tabel antrean.
 *
 * Dipisah dari worker/index.php supaya auto-refresh bisa memperbarui daftar ini
 * saja lewat AJAX (Worker::status dengan ?queue=1) tanpa memuat ulang halaman —
 * markup baris hanya ada di satu tempat, jadi versi server dan versi hasil
 * polling tidak mungkin berbeda.
 *
 * @var list<array<string, mixed>> $queued
 * @var array<string, mixed>       $status
 */
?>
<?php if ($queued === []): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">Antrean kosong.</td></tr>
<?php else: ?>
    <?php foreach ($queued as $q): ?>
        <?php
            // Baris `processing` saat worker mati = sisa proses yang terputus.
            // Ia tidak akan diambil lagi oleh worker sampai dipulihkan, jadi
            // jangan ditampilkan seolah sedang jalan.
            $stuck = $q['status'] === 'processing' && ! $status['running'];
        ?>
        <tr>
            <td><?= (int) $q['id'] ?></td>
            <td><?= esc($q['recipient']) ?></td>
            <td>
                <span class="badge <?= $stuck ? 'text-bg-warning' : ($q['status'] === 'processing' ? 'text-bg-info' : 'text-bg-secondary') ?>">
                    <?= $stuck ? 'tersangkut' : esc($q['status']) ?>
                </span>
                <?php if ($stuck): ?>
                    <div class="text-muted small">
                        sisa worker yang berhenti di tengah kirim — dipulihkan
                        otomatis saat worker dinyalakan
                    </div>
                <?php endif; ?>
            </td>
            <td class="small text-muted"><?= esc($q['created_at']) ?></td>
            <td class="text-end">
                <?php if ($q['status'] === 'queued' || $stuck): ?>
                    <form method="post" action="<?= module_url('worker/' . (int) $q['id'] . '/cancel') ?>"
                          class="d-inline js-confirm"
                          data-confirm-title="Batalkan pesan?"
                          data-confirm="<?= $stuck
                              ? 'Pesan ini sisa worker yang berhenti di tengah pengiriman, jadi belum tentu benar-benar gagal terkirim. Menghapusnya berarti pesan tidak akan dicoba lagi.'
                              : 'Pesan ini akan dihapus dari antrean dan tidak dikirim.' ?>"
                          data-confirm-ok="Ya, Batalkan" data-confirm-variant="danger">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-danger btn-sm" type="submit">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                <?php else: ?>
                    <span class="text-muted small">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
