<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    // Token tidak pernah dicetak utuh di daftar — hanya di form ubah.
    $mask = static fn (string $token): string => strlen($token) <= 8
        ? str_repeat('•', max(strlen($token), 4))
        : substr($token, 0, 4) . str_repeat('•', 4) . substr($token, -4);

$statusBadge = static fn (?string $status): string => match ($status) {
    'success' => 'text-bg-success',
    'dryrun'  => 'text-bg-warning',
    'failed'  => 'text-bg-danger',
    default   => 'text-bg-light',
};

$activeCount = 0;

foreach ($accounts as $a) {
    $activeCount += (int) $a['is_active'];
}
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 mb-0">Akun MaxChat</h1>
    </div>
    <a href="<?= module_url('accounts/create') ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Akun Baru</a>
</div>

<?php if ($activeCount === 0): ?>
    <div class="alert alert-danger">
        <strong>Tidak ada akun aktif.</strong> Pesan tidak bisa dikirim sampai minimal satu akun diaktifkan.
    </div>
<?php elseif ($activeCount === 1): ?>
    <div class="alert alert-warning">
        <strong>Hanya ada satu akun aktif.</strong> Tambahkan akun kedua agar ada cadangan bila akun ini gagal.
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nama</th>
                    <th>Base URL</th>
                    <th>Token</th>
                    <th class="text-nowrap">Terakhir dipakai</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($accounts === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada akun.</td></tr>
                <?php endif; ?>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= esc($account['name']) ?></div>
                            <?php if (! empty($account['description'])): ?>
                                <div class="text-muted small"><?= esc($account['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><code><?= esc($account['base_url']) ?>/messages</code></td>
                        <td><code class="small"><?= esc($mask((string) $account['token'])) ?></code></td>
                        <td class="text-nowrap small text-muted">
                            <?= $account['last_used_at'] !== null ? esc($account['last_used_at']) : 'belum pernah' ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <?php if ((int) $account['is_active'] === 1): ?>
                                <span class="badge text-bg-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Nonaktif</span>
                            <?php endif; ?>
                            <?php if ($account['last_status'] !== null): ?>
                                <div class="mt-1">
                                    <span class="badge <?= $statusBadge($account['last_status']) ?>"
                                          title="<?= esc($account['last_error'] ?? 'Percobaan terakhir: ' . $account['last_status']) ?>">
                                        <?= esc($account['last_status']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <form method="post" action="<?= module_url('accounts/' . $account['id'] . '/test') ?>"
                                  class="d-inline-flex align-items-center gap-1 me-1"
                                  data-confirm-title="Kirim pesan uji?"
                                  data-confirm="Pesan uji benar-benar dikirim lewat akun ini dan memakai kuota pesan."
                                  data-confirm-detail="<?= esc($account['name'], 'attr') ?>"
                                  data-confirm-ok="Ya, Kirim Uji"
                                  data-confirm-variant="primary">
                                <?= csrf_field() ?>
                                <input type="text" name="to" class="form-control form-control-sm" style="width: 10rem"
                                       inputmode="tel" placeholder="08xx untuk tes" required>
                                <button class="btn btn-sm btn-outline-primary" title="Kirim pesan uji lewat akun ini">
                                    <i class="bi bi-broadcast"></i> Tes
                                </button>
                            </form>
                            <a href="<?= module_url('accounts/' . $account['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="post" action="<?= module_url('accounts/' . $account['id'] . '/delete') ?>" class="d-inline"
                                  data-confirm-title="Hapus akun?"
                                  data-confirm="Akun ini akan dihapus dari rotasi pengiriman. Riwayat pengiriman lewat akun ini tetap tersimpan."
                                  data-confirm-detail="<?= esc($account['name'], 'attr') ?>"
                                  data-confirm-ok="Ya, Hapus">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white text-muted small">
        <i class="bi bi-info-circle me-1"></i>
        Giliran berikutnya jatuh ke akun aktif dengan kolom <em>Terakhir dipakai</em> paling lama
        (akun yang belum pernah dipakai didahulukan). Tombol <strong>Tes</strong> mengirim pesan sungguhan
        dan tidak menggeser giliran rotasi.
    </div>
</div>

<?= $this->endSection() ?>
