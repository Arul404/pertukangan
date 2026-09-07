<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    /**
     * @var array<string, mixed>|null $credential
     * @var string                    $baseUrl
     * @var bool                      $hasEncryptKey
     * @var bool                      $connected
     */
    $isEdit = $credential !== null;
?>

<div class="row justify-content-center">
    <div class="col-xxl-10">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
            <div>
                <h1 class="h3 mb-0">Akun BSrE</h1>
            </div>
            <span class="badge align-self-center <?= $connected ? 'text-bg-success' : 'text-bg-secondary' ?>" id="conn-badge">
                <i class="bi <?= $connected ? 'bi-plug-fill' : 'bi-plug' ?> me-1"></i>
                <?= $connected ? 'Terhubung' : 'Belum terhubung' ?>
            </span>
        </div>

        <?php if (! $hasEncryptKey): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>Kunci enkripsi belum diset.</strong> Kata sandi BSrE terpaksa disimpan tanpa
                enkripsi. Setel <code>encryption.key</code> di berkas <code>.env</code>
                (mis. lewat <code>php spark key:generate</code>) lalu simpan ulang kredensial.
            </div>
        <?php endif; ?>

        <div class="row g-3 align-items-start">
            <!-- Kolom kiri: kredensial -->
            <div class="col-lg-6">
                <form method="post" action="<?= module_url('akun-bsre/save') ?>" class="card shadow-sm h-100" id="bsre-form">
                    <?= csrf_field() ?>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="username">Username / Email <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required
                                   value="<?= esc(old('username') ?? ($credential['username'] ?? '')) ?>"
                                   placeholder="nama@domain.go.id" autocomplete="off">
                            <div class="form-text">Sesuai yang diketik di kolom login portal BSrE.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="password">
                                Kata sandi <?= $isEdit ? '' : '<span class="text-danger">*</span>' ?>
                            </label>
                            <input type="password" class="form-control" id="password" name="password"
                                   <?= $isEdit ? '' : 'required' ?> autocomplete="new-password"
                                   placeholder="<?= $isEdit ? 'Kosongkan bila tidak diubah' : 'Kata sandi login BSrE' ?>">
                            <div class="form-text">
                                <?= $isEdit
                                    ? 'Sudah tersimpan. Isi hanya bila ingin menggantinya.'
                                    : 'Disimpan terenkripsi dan hanya dipakai untuk login otomatis ke BSrE.' ?>
                            </div>
                        </div>

                        <div class="mb-1">
                            <label class="form-label" for="base_url">Base URL (opsional)</label>
                            <input type="text" class="form-control" id="base_url" name="base_url"
                                   value="<?= esc(old('base_url') ?? ($credential['base_url'] ?? '')) ?>"
                                   placeholder="<?= esc($baseUrl) ?>">
                            <div class="form-text">Kosongkan untuk memakai default: <code><?= esc($baseUrl) ?></code>.</div>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i> Simpan</button>
                    </div>
                </form>
            </div>

            <!-- Kolom kanan: hubungkan -->
            <div class="col-lg-6">
                <?php if ($isEdit): ?>
                    <!-- Hubungkan: login Keycloak dengan TOTP dari authenticator. -->
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-shield-lock me-1"></i> Hubungkan ke BSrE
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Masukkan <strong>kode OTP</strong> (6 digit dari aplikasi authenticator Anda),
                                lalu tekan Hubungkan. Sistem login ke BSrE dan menyimpan sesinya untuk dipakai
                                reset passphrase; sesi diperpanjang otomatis tanpa perlu OTP lagi sampai kedaluwarsa.
                            </p>

                            <label class="form-label" for="otp">Kode OTP (TOTP) <span class="text-danger">*</span></label>
                            <div class="input-group" style="max-width: 320px;">
                                <input type="text" class="form-control" id="otp" inputmode="numeric"
                                       maxlength="6" placeholder="6 digit" autocomplete="one-time-code">
                                <button class="btn btn-primary" type="button" id="btn-connect">
                                    <i class="bi bi-plug me-1"></i> Hubungkan
                                </button>
                            </div>
                            <div id="conn-status" class="small mt-2"></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card shadow-sm h-100 border-dashed">
                        <div class="card-body d-flex align-items-center text-muted small">
                            <i class="bi bi-info-circle me-2 fs-5"></i>
                            Simpan kredensial dulu, lalu opsi <strong class="mx-1">Hubungkan</strong> (login + OTP) muncul di sini.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const LOGIN_URL = <?= json_encode(module_url('akun-bsre/login')) ?>;
    const CSRF_NAME = <?= json_encode(csrf_token()) ?>;

    const connectBtn = document.getElementById('btn-connect');

    if (connectBtn) {
        const otpInput = document.getElementById('otp');
        const statusEl = document.getElementById('conn-status');
        const badge    = document.getElementById('conn-badge');

        function csrfField() {
            return document.querySelector('input[name="' + CSRF_NAME + '"]');
        }

        function setStatus(html, kind) {
            statusEl.className = 'small mt-2 text-' + (kind || 'muted');
            statusEl.innerHTML = html;
        }

        function busy(on) {
            if (on) {
                connectBtn.dataset.idleHtml = connectBtn.innerHTML;
                connectBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menghubungkan…';
            } else if (connectBtn.dataset.idleHtml) {
                connectBtn.innerHTML = connectBtn.dataset.idleHtml;
            }
            connectBtn.disabled = on;
        }

        async function connect() {
            const otp = otpInput.value.trim();

            if (otp === '') {
                setStatus('<i class="bi bi-exclamation-circle-fill me-1"></i> Kode OTP wajib diisi.', 'danger');
                otpInput.focus();
                return;
            }

            busy(true);
            setStatus('<span class="spinner-border spinner-border-sm me-1"></span> Login ke BSrE…');

            const body = new FormData();
            body.append(CSRF_NAME, csrfField().value);
            body.append('otp', otp);

            try {
                const response = await fetch(LOGIN_URL, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: body,
                });
                const data = await response.json();

                if (data.csrf) {
                    csrfField().value = data.csrf;
                }

                if (data.ok) {
                    setStatus('<i class="bi bi-check-circle-fill me-1"></i> Terhubung ke BSrE. Sesi tersimpan.', 'success');
                    badge.className = 'badge align-self-center text-bg-success';
                    badge.innerHTML = '<i class="bi bi-plug-fill me-1"></i> Terhubung';
                    otpInput.value = '';
                } else {
                    setStatus('<i class="bi bi-x-circle-fill me-1"></i> ' + (data.error || 'Login gagal.'), 'danger');
                }
            } catch (error) {
                setStatus('<i class="bi bi-x-circle-fill me-1"></i> Gagal menghubungi server: ' + error.message, 'danger');
            } finally {
                busy(false);
            }
        }

        connectBtn.addEventListener('click', connect);
        otpInput.addEventListener('keyup', (event) => {
            if (event.key === 'Enter') connect();
        });
    }
</script>
<?= $this->endSection() ?>
