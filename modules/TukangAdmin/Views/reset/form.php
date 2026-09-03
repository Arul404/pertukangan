<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    /**
     * @var list<array<string, mixed>> $templates
     * @var bool                       $hasCredential
     * @var int                        $activeAccounts
     * @var Modules\TukangKirim\Libraries\MaxChatService $maxchat
     */
?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">Reset Password TTE</h1>
        <p class="text-muted mb-0">Masukkan nomor HP penandatangan. Sistem mencari akunnya di TTE,
            mereset kata sandinya, lalu mengirimkannya lewat WhatsApp.</p>
    </div>
    <a href="<?= module_url('akun') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-person-gear me-1"></i> Akun TTE
    </a>
</div>

<?php if (! $hasCredential): ?>
    <div class="alert alert-danger">
        <strong>Belum ada kredensial TTE.</strong>
        Isi username & kata sandi login TTE dulu di menu
        <a href="<?= module_url('akun') ?>" class="alert-link">Akun TTE</a>.
    </div>
<?php endif; ?>

<?php if ($templates === []): ?>
    <div class="alert alert-warning">
        <strong>Belum ada template aktif.</strong>
        Buat template ber-placeholder <code>[email]</code> dan <code>[password]</code> dulu di
        <a href="<?= site_url('tukang-kirim/templates') ?>" class="alert-link">Tukang Kirim &rarr; Template</a>.
    </div>
<?php else: ?>

    <?php if ($activeAccounts === 0): ?>
        <div class="alert alert-warning">
            <strong>Belum ada akun MaxChat aktif.</strong>
            Kata sandi bisa direset, tapi tidak akan terkirim. Aktifkan akun di
            <a href="<?= site_url('tukang-kirim/accounts') ?>" class="alert-link">Tukang Kirim &rarr; Akun MaxChat</a>.
        </div>
    <?php endif; ?>

    <div class="row g-3 align-items-start">

        <!-- ===== Kolom kiri: isian ===== -->
        <div class="col-lg-7">
            <div id="form-errors"></div>

            <form method="post" action="<?= module_url('reset/search') ?>" id="reset-form" class="card shadow-sm">
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="phone">
                            Nomor HP <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control form-control-lg" id="phone" name="phone"
                               inputmode="tel" placeholder="08123456789" autocomplete="off" required
                               <?= $hasCredential ? '' : 'disabled' ?>>
                        <div class="form-text">
                            Nomor ini dipakai untuk mencari akun di TTE sekaligus tujuan pengiriman WhatsApp.
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label fw-semibold" for="template_id">
                            Template pesan <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="template_id" name="template_id" required
                                <?= $hasCredential ? '' : 'disabled' ?>>
                            <option value="">— Pilih template —</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"><?= esc($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Harus memuat <code class="placeholder-chip">[password]</code>;
                            <code class="placeholder-chip">[email]</code> dan
                            <code class="placeholder-chip">[nama]</code> diisi otomatis dari data TTE.
                        </div>
                    </div>
                </div>

                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary btn-lg w-100" id="btn-search"
                            <?= $hasCredential ? '' : 'disabled' ?>>
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
                                        Sistem login ke TTE dan mencari akun <strong>penandatangan</strong>
                                        yang cocok dengan nomor HP, lalu mengambil emailnya.
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3 mb-3">
                                <span class="step-num">2</span>
                                <div>
                                    <div class="fw-semibold">Konfirmasi</div>
                                    <div class="text-muted small">
                                        Email yang ditemukan dan kata sandi baru yang dibuat otomatis
                                        ditampilkan di sini untuk Anda periksa. Belum ada yang diubah.
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <span class="step-num">3</span>
                                <div>
                                    <div class="fw-semibold">Reset &amp; kirim</div>
                                    <div class="text-muted small">
                                        Baru setelah Anda menekan tombolnya, kata sandi TTE diganti dan
                                        dikirim ke nomor HP lewat WhatsApp.
                                    </div>
                                </div>
                            </div>

                            <div class="note-box mt-3">
                                <i class="bi bi-shield-check me-1"></i>
                                Kata sandi dibuat otomatis, ditampilkan sekali, dan tidak pernah disimpan
                                dalam bentuk asli di database.
                            </div>
                        </div>
                    </div>
                </div>

                <div id="panel-server" hidden></div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const CSRF_NAME    = <?= json_encode(csrf_token()) ?>;
    const DISPATCH_URL = <?= json_encode(module_url('reset/dispatch')) ?>;

    const form        = document.getElementById('reset-form');
    const searchBtn   = document.getElementById('btn-search');
    const errorBox    = document.getElementById('form-errors');
    const panelIdle   = document.getElementById('panel-idle');
    const panelServer = document.getElementById('panel-server');
    const phoneInput  = document.getElementById('phone');

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
            phoneInput.value = '';
            showIdle();
            phoneInput.focus();

            return;
        }

        const copyBtn = event.target.closest('[data-copy-password]');

        if (copyBtn) {
            await navigator.clipboard.writeText(panelServer.querySelector('#pwd-value').textContent.trim());
            copyBtn.innerHTML = '<i class="bi bi-check2 me-1"></i> Tersalin';
            setTimeout(() => { copyBtn.innerHTML = '<i class="bi bi-clipboard me-1"></i> Salin'; }, 2000);
        }
    });

    // Bila nomor/template diubah, panel konfirmasi jadi basi dan dibuang supaya
    // tombol Reset tidak sempat memakai draft lama. Panel hasil dikecualikan.
    for (const type of ['input', 'change']) {
        form.addEventListener(type, () => {
            clearErrors();

            if (panelServer.dataset.state === 'preview') {
                showIdle();
            }
        });
    }
</script>
<?= $this->endSection() ?>
