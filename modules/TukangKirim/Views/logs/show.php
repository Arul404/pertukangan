<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    $badge = match ($log['status']) {
        'success' => 'text-bg-success',
        'dryrun'  => 'text-bg-warning',
        default   => 'text-bg-danger',
    };
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Pengiriman #<?= (int) $log['id'] ?></h1>
            <a href="<?= module_url('logs') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3 text-muted fw-normal">Status</dt>
                    <dd class="col-sm-9">
                        <span class="badge <?= $badge ?>"><?= esc($log['status']) ?></span>
                        <?php if ($log['http_code'] !== null): ?>
                            <span class="text-muted small">HTTP <?= esc((string) $log['http_code']) ?></span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3 text-muted fw-normal">Waktu</dt>
                    <dd class="col-sm-9"><?= esc($log['created_at']) ?></dd>

                    <dt class="col-sm-3 text-muted fw-normal">Tujuan</dt>
                    <dd class="col-sm-9">
                        <?= esc($log['recipient_normalized']) ?>
                        <span class="text-muted small">(diketik: <?= esc($log['recipient_input']) ?>)</span>
                    </dd>

                    <dt class="col-sm-3 text-muted fw-normal">Akun</dt>
                    <dd class="col-sm-9">
                        <?= esc($log['account_name'] ?? '—') ?>
                        <?php if ((int) $log['attempt_no'] > 1): ?>
                            <span class="badge text-bg-light" title="Akun sebelumnya gagal, pengiriman dialihkan ke akun ini">
                                percobaan ke-<?= (int) $log['attempt_no'] ?>
                            </span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3 text-muted fw-normal">Template</dt>
                    <dd class="col-sm-9 mb-0"><?= esc($log['template_name'] ?? '—') ?></dd>
                </dl>
            </div>
        </div>

        <?php if (! empty($log['error_message'])): ?>
            <div class="alert alert-danger"><?= esc($log['error_message']) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                Isi pesan
                <?php if ((int) $log['has_password'] === 1): ?>
                    <span class="badge text-bg-light ms-1" title="Password diganti tanda bintang sebelum disimpan">password ter-mask</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="chat-canvas">
                    <div class="bubble"><p class="message-preview"><?= esc($log['message_masked']) ?></p></div>
                </div>
            </div>
        </div>

        <?php if (! empty($log['api_response'])): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <?= $log['status'] === 'dryrun' ? 'Payload (tidak dikirim)' : 'Respons MaxChat' ?>
                </div>
                <div class="card-body">
                    <pre class="bg-dark text-light p-3 rounded small mb-0"><code><?= esc($log['api_response']) ?></code></pre>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
