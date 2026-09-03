<?php $flash = session()->getFlashdata(); ?>
<?php if (! empty($flash['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= esc($flash['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (! empty($flash['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= esc($flash['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (! empty($flash['errors']) && is_array($flash['errors'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>Periksa kembali isian berikut:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($flash['errors'] as $message): ?>
                <li><?= esc($message) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
