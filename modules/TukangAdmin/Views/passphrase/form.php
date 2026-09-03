<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    /**
     * @var bool $hasCredential
     * @var bool $connected
     */
?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">Reset Passphrase BSrE</h1>
        <p class="text-muted mb-0">Masukkan email akun BSrE. Sistem mencari akunnya di portal,
            membuka sertifikat elektroniknya, lalu mengirim tautan reset passphrase.</p>
    </div>
    <a href="<?= module_url('akun-bsre') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-shield-lock me-1"></i> Akun BSrE
    </a>
</div>

<?php if (! $hasCredential): ?>
    <div class="alert alert-danger">
        <strong>Belum ada kredensial BSrE.</strong>
        Isi username & kata sandi login BSrE dulu di menu
        <a href="<?= module_url('akun-bsre') ?>" class="alert-link">Akun BSrE</a>.
    </div>
<?php elseif (! $connected): ?>
    <div class="alert alert-warning">
        <strong>Belum terhubung ke BSrE.</strong>
        Login (dan isi OTP) dulu di menu
        <a href="<?= module_url('akun-bsre') ?>" class="alert-link">Akun BSrE</a>
        agar token aktif untuk sesi ini.
    </div>
<?php endif; ?>

<div class="row g-3 align-items-start">

    <!-- ===== Kolom kiri: isian ===== -->
    <div class="col-lg-7">
        <div id="form-errors"></div>

        <?php $ready = $hasCredential && $connected; ?>
        <form method="post" action="<?= module_url('passphrase/search') ?>" id="passphrase-form" class="card shadow-sm">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-1">
                    <label class="form-label fw-semibold" for="email">
                        Email akun <span class="text-danger">*</span>
                    </label>
                    <input type="email" class="form-control form-control-lg" id="email" name="email"
                           inputmode="email" placeholder="nama@instansi.go.id" autocomplete="off" required
                           <?= $ready ? '' : 'disabled' ?>>
                    <div class="form-text">
                        Email ini dipakai untuk mencari akun di portal BSrE (parameter Email).
                    </div>
                </div>
            </div>

            <div class="card-footer bg-white">
                <button type="submit" class="btn btn-primary btn-lg w-100" id="btn-search"
                        <?= $ready ? '' : 'disabled' ?>>
                    <i class="bi bi-search me-1"></i> Cari Akun
                </button>
            </div>
        </form>
    </div>

    <!-- ===== Kolom kanan: panel status ===== -->
    <div class="col-lg-5">
        <div class="panel-sticky">
            <div id="panel-idle">
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Cara kerja</h2>

                        <div class="d-flex gap-3 mb-3">
                            <span class="step-num">1</span>
                            <div>
                                <div class="fw-semibold">Cari akun</div>
                                <div class="text-muted small">
                                    Sistem mencari akun di portal BSrE yang cocok dengan email,
                                    lalu membuka sertifikat elektroniknya.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3 mb-3">
                            <span class="step-num">2</span>
                            <div>
                                <div class="fw-semibold">Konfirmasi</div>
                                <div class="text-muted small">
                                    Data akun, sertifikat, dan status verifikasi nomor HP ditampilkan
                                    di sini untuk Anda periksa. Belum ada yang dikirim.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3">
                            <span class="step-num">3</span>
                            <div>
                                <div class="fw-semibold">Reset passphrase</div>
                                <div class="text-muted small">
                                    Bila HP sudah terverifikasi, BSrE langsung mengirim tautan reset
                                    passphrase ke email dinas. Bila belum, BSrE mengirim tautan
                                    verifikasi ke WhatsApp pengguna lebih dulu.
                                </div>
                            </div>
                        </div>

                        <div class="note-box mt-3">
                            <i class="bi bi-shield-check me-1"></i>
                            Tindakan reset hanya mengirim tautan; passphrase baru diatur sendiri oleh
                            pengguna lewat tautan tersebut.
                        </div>
                    </div>
                </div>
            </div>

            <div id="panel-server" hidden></div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const CSRF_NAME    = <?= json_encode(csrf_token()) ?>;
    const DISPATCH_URL = <?= json_encode(module_url('passphrase/dispatch')) ?>;

    const form        = document.getElementById('passphrase-form');
    const searchBtn   = document.getElementById('btn-search');
    const errorBox    = document.getElementById('form-errors');
    const panelIdle   = document.getElementById('panel-idle');
    const panelServer = document.getElementById('panel-server');
    const emailInput  = document.getElementById('email');

    function showIdle() {
        panelServer.hidden = true;
        panelServer.innerHTML = '';
        delete panelServer.dataset.state;
        panelIdle.hidden = false;
    }

    function showServerPanel(html, state) {
        panelServer.innerHTML = html;
        panelServer.dataset.state = state;
        panelServer.hidden = false;
        panelIdle.hidden = true;
    }

    function showErrors(messages) {
        errorBox.innerHTML =
            '<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
            '<strong>Tidak bisa dilanjutkan:</strong><ul class="mb-0 mt-1"></ul>' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';

        const list = errorBox.querySelector('ul');

        for (const message of messages) {
            const item = document.createElement('li');
            item.textContent = message;
            list.appendChild(item);
        }
    }

    function clearErrors() {
        errorBox.innerHTML = '';
    }

    function busy(button, on, label) {
        if (on) {
            button.dataset.idleHtml = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + label;
        } else if (button.dataset.idleHtml) {
            button.innerHTML = button.dataset.idleHtml;
        }

        button.disabled = on;
    }

    // Hash CSRF baru dari respons dipasang ulang (Config\Security::$regenerate = true).
    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        });

        const data = await response.json();

        if (data.csrf) {
            for (const field of document.querySelectorAll('input[name="' + CSRF_NAME + '"]')) {
                field.value = data.csrf;
            }
        }

        return data;
    }

    if (form) {
        // Langkah 1: cari akun & susun draft.
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearErrors();
            busy(searchBtn, true, 'Mencari…');

            try {
                const data = await postJson(form.action, new FormData(form));

                if (data.state === 'preview') {
                    showServerPanel(data.html, 'preview');
                } else {
                    showErrors(data.errors.length ? data.errors : ['Pencarian gagal.']);
                    showIdle();
                }
            } catch (error) {
                showErrors(['Gagal menghubungi server: ' + error.message]);
            } finally {
                busy(searchBtn, false);
            }
        });

        // Langkah 2 dan tombol lain di dalam panel server (delegasi).
        panelServer.addEventListener('click', async (event) => {
            const doBtn = event.target.closest('#btn-dispatch');

            if (doBtn) {
                if (!await confirmAction(confirmOptionsOf(doBtn))) return;

                clearErrors();
                busy(doBtn, true, 'Memproses…');

                const body = new FormData();
                body.append(CSRF_NAME, form.querySelector('input[name="' + CSRF_NAME + '"]').value);

                try {
                    const data = await postJson(DISPATCH_URL, body);

                    if (data.state === 'result') {
                        showServerPanel(data.html, 'result');
                    } else {
                        showErrors(data.errors.length ? data.errors : ['Proses gagal.']);
                        showIdle();
                    }
                } catch (error) {
                    showErrors(['Gagal menghubungi server: ' + error.message]);
                    busy(doBtn, false);
                }

                return;
            }

            if (event.target.closest('#btn-again')) {
                emailInput.value = '';
                showIdle();
                emailInput.focus();
            }
        });

        // Bila email diubah, panel konfirmasi jadi basi dan dibuang supaya tombol
        // aksi tidak sempat memakai draft lama. Panel hasil dikecualikan.
        for (const type of ['input', 'change']) {
            form.addEventListener(type, () => {
                clearErrors();

                if (panelServer.dataset.state === 'preview') {
                    showIdle();
                }
            });
        }
    }
</script>
<?= $this->endSection() ?>
