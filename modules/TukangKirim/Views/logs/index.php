<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    $badge = static fn (string $status): string => match ($status) {
        'success' => 'text-bg-success',
        'dryrun'  => 'text-bg-warning',
        default   => 'text-bg-danger',
    };
?>

<div class="mb-3">
    <h1 class="h3 mb-0">Riwayat Pengiriman</h1>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-sm-3">
        <select class="form-select" name="status">
            <option value="">— Semua status —</option>
            <?php foreach (['success' => 'Terkirim', 'failed' => 'Gagal', 'dryrun' => 'Uji coba'] as $key => $label): ?>
                <option value="<?= $key ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-3">
        <select class="form-select" name="maxchat_account_id">
            <option value="">— Semua akun —</option>
            <?php foreach ($accounts as $account): ?>
                <option value="<?= (int) $account['id'] ?>" <?= (int) $filters['maxchat_account_id'] === (int) $account['id'] ? 'selected' : '' ?>>
                    <?= esc($account['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-3">
        <input type="text" class="form-control" name="q" placeholder="Cari nomor…" value="<?= esc($filters['q']) ?>">
    </div>
    <div class="col-sm-3 d-grid">
        <button class="btn btn-outline-secondary"><i class="bi bi-funnel me-1"></i> Filter</button>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Waktu</th>
                    <th>Tujuan</th>
                    <th>Template</th>
                    <th>Akun</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada pengiriman.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap small"><?= esc($log['created_at']) ?></td>
                        <td>
                            <div class="fw-semibold"><?= esc($log['recipient_normalized']) ?></div>
                            <div class="text-muted small">diketik: <?= esc($log['recipient_input']) ?></div>
                        </td>
                        <td><?= esc($log['template_name'] ?? '—') ?></td>
                        <td>
                            <div><?= esc($log['account_name'] ?? '—') ?></div>
                            <?php if ((int) $log['attempt_no'] > 1): ?>
                                <div class="text-muted small">percobaan ke-<?= (int) $log['attempt_no'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $badge($log['status']) ?>"><?= esc($log['status']) ?></span>
                            <?php if ($log['http_code'] !== null): ?>
                                <div class="text-muted small">HTTP <?= esc((string) $log['http_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= module_url('logs/' . $log['id']) ?>" class="btn btn-sm btn-outline-secondary">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager !== null && $pager->getPageCount() > 1): ?>
        <div class="card-footer bg-white"><?= $pager->links() ?></div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
