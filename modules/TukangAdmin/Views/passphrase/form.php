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
        <h1 class="h3 mb-0">Reset Passphrase BSrE</h1>
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
    <!--
        Dulu ini hanya pemberitahuan yang menyuruh operator pindah ke halaman
        Akun BSrE. Karena satu-satunya yang dibutuhkan di sini adalah kode OTP,
        formnya ditaruh langsung supaya alurnya tidak terputus: hubungkan,
        lalu lanjut mencari di halaman yang sama.
    -->
    <div class="card border-warning shadow-sm mb-3" id="connect-card">
        <div class="card-header bg-warning-subtle fw-semibold">
            <i class="bi bi-plug me-1"></i> Belum terhubung ke BSrE
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Sesi BSrE belum aktif. Masukkan <strong>kode OTP</strong> (6 digit dari aplikasi
                authenticator Anda) untuk menghubungkan sekarang — tanpa perlu pindah halaman.
                Sesinya dipakai untuk seluruh reset passphrase berikutnya dan diperpanjang
                otomatis tanpa OTP lagi sampai kedaluwarsa.
            </p>

            <label class="form-label" for="otp">Kode OTP (TOTP) <span class="text-danger">*</span></label>
            <div class="input-group" style="max-width: 320px;">
                <input type="text" class="form-control" id="otp" inputmode="numeric"
                       maxlength="6" placeholder="6 digit" autocomplete="one-time-code" autofocus>
                <button class="btn btn-primary" type="button" id="btn-connect">
                    <i class="bi bi-plug me-1"></i> Hubungkan
                </button>
            </div>
            <div id="conn-status" class="small mt-2"></div>

            <div class="form-text mt-2">
                Username &amp; kata sandinya sendiri diatur di
                <a href="<?= module_url('akun-bsre') ?>">Akun BSrE</a>.
            </div>
        </div>
    </div>

    <!--
        JANGAN tambahkan d-flex (atau utility d-* lain) di sini: Bootstrap punya
        [hidden]{display:none!important} di reboot, tetapi .d-flex{display:flex
        !important} berada jauh lebih belakang di berkas yang sama. Spesifikasinya
        sama dan dua-duanya !important, jadi yang belakangan menang — alertnya
        akan tampil walau beratribut hidden.
    -->
    <div class="alert alert-success" id="connect-done" hidden>
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>Terhubung ke BSrE.</strong> Sesi tersimpan — silakan lanjut mencari akun.
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
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="phone">
                        No HP <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-lg" id="phone" name="phone"
                           inputmode="tel" placeholder="08123456789" autocomplete="off" required autofocus
                           <?= $ready ? '' : 'disabled' ?>>
                    <div class="form-text">
                        Nomor yang dipegang pengguna. Dipakai mencari akunnya, lalu dibandingkan
                        dengan nomor yang tercatat di BSrE — dan bila perlu, disimpan ke sana.
                    </div>
                </div>

                <div class="mb-1" id="fallback-wrap" hidden>
                    <label class="form-label fw-semibold" for="fallback">NIK atau Email</label>
                    <input type="text" class="form-control" id="fallback" name="fallback"
                           placeholder="16 digit NIK atau nama@instansi.go.id" autocomplete="off">
                    <div class="form-text" id="fallback-hint">
                        Dipakai hanya untuk menemukan akunnya di BSrE. Jenisnya dikenali otomatis.
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
                                <div class="fw-semibold">Cari lewat No HP</div>
                                <div class="text-muted small">
                                    Portal BSrE tidak bisa dicari lewat nomor, jadi pencarian selalu
                                    lewat TTE dulu — termasuk saat kolom NIK/Email dipakai. Email
                                    dari TTE itulah yang kemudian dicari di BSrE.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3 mb-3">
                            <span class="step-num">2</span>
                            <div>
                                <div class="fw-semibold">Review nomor</div>
                                <div class="text-muted small">
                                    Nomor yang Anda ketik dibandingkan dengan yang tercatat di
                                    <strong>TTE dan BSrE</strong>. Bila ada yang berbeda, satu tombol
                                    memperbaiki keduanya — di BSrE sekalian disetujui supaya langsung
                                    berlaku — atau Anda bisa <strong>melewatinya</strong>.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3">
                            <span class="step-num">3</span>
                            <div>
                                <div class="fw-semibold">Reset passphrase</div>
                                <div class="text-muted small">
                                    Bila HP sudah terverifikasi, BSrE langsung mengirim tautan reset
                                    passphrase ke email dinas. Bila belum, muncul konfirmasi
                                    verifikasi lebih dulu — persis seperti dialog di portal.
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
    const LOGIN_URL    = <?= json_encode(module_url('akun-bsre/login')) ?>;

    // Langkah antara: semuanya membalas panel 'preview' yang sudah disegarkan,
    // jadi satu penangan generik cukup untuk ketiganya (lihat STEP_BUTTONS).
    const UPDATE_PHONE_URL   = <?= json_encode(module_url('passphrase/update-phone')) ?>;
    const APPROVE_UPDATE_URL = <?= json_encode(module_url('passphrase/approve-update')) ?>;
    const SKIP_UPDATE_URL    = <?= json_encode(module_url('passphrase/skip-update')) ?>;

    // ===== Hubungkan ke BSrE tanpa pindah halaman =====
    // Endpoint yang dipakai sama persis dengan tombol di halaman Akun BSrE
    // (BsreAccount::login), jadi tidak ada jalur login kedua yang harus dijaga.
    (function () {
        const connectBtn = document.getElementById('btn-connect');

        if (!connectBtn) {
            return; // Sudah terhubung, atau kredensial belum diisi.
        }

        const otpInput = document.getElementById('otp');
        const statusEl = document.getElementById('conn-status');
        const card     = document.getElementById('connect-card');
        const done     = document.getElementById('connect-done');

        const csrfField = () => document.querySelector('input[name="' + CSRF_NAME + '"]');

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

        // Kontrol pencarian dirender disabled selama sesi belum aktif; begitu
        // token didapat, buka semuanya di tempat supaya operator tidak perlu
        // memuat ulang halaman.
        function unlockForm() {
            for (const el of document.querySelectorAll('#passphrase-form [disabled]')) {
                el.disabled = false;
            }

            card.hidden = true;
            done.hidden = false;
            document.getElementById('phone')?.focus();
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
                    unlockForm();
                } else {
                    setStatus('<i class="bi bi-x-circle-fill me-1"></i> ' + (data.error || 'Login gagal.'), 'danger');
                    busy(false);
                }
            } catch (error) {
                setStatus('<i class="bi bi-x-circle-fill me-1"></i> Gagal menghubungi server: ' + error.message, 'danger');
                busy(false);
            }
        }

        connectBtn.addEventListener('click', connect);
        otpInput.addEventListener('keyup', (event) => {
            if (event.key === 'Enter') connect();
        });
    })();

    const form          = document.getElementById('passphrase-form');
    const searchBtn     = document.getElementById('btn-search');
    const errorBox      = document.getElementById('form-errors');
    const panelIdle     = document.getElementById('panel-idle');
    const panelServer   = document.getElementById('panel-server');
    const phoneInput    = document.getElementById('phone');
    const fallbackWrap  = document.getElementById('fallback-wrap');
    const fallbackInput = document.getElementById('fallback');
    const fallbackHint  = document.getElementById('fallback-hint');

    const FALLBACK_HINT = 'Dipakai hanya untuk menemukan akunnya di BSrE. Jenisnya dikenali otomatis.';

    // Tombol langkah antara: id -> {url, label sibuk}. Ketiganya membalas panel
    // 'preview' yang sudah disegarkan dari BSrE, jadi penanganannya identik.
    const STEP_BUTTONS = {
        'btn-update-phone':   { url: UPDATE_PHONE_URL,   busy: 'Memperbarui…' },
        'btn-approve-update': { url: APPROVE_UPDATE_URL, busy: 'Menyetujui…' },
        'btn-skip-update':    { url: SKIP_UPDATE_URL,    busy: 'Melewati…' },
    };

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

    function csrfValue() {
        return form.querySelector('input[name="' + CSRF_NAME + '"]').value;
    }

    function stepBody(button) {
        const body = new FormData();
        body.append(CSRF_NAME, csrfValue());
        body.append('draft_id', button.dataset.draftId || '');

        return body;
    }

    // Kolom cadangan hanya muncul setelah pencarian lewat nomor gagal.
    function showFallback(on) {
        fallbackWrap.hidden = !on;
        searchBtn.innerHTML = on
            ? '<i class="bi bi-search me-1"></i> Cari dengan NIK/Email'
            : '<i class="bi bi-search me-1"></i> Cari Akun';

        if (!on) {
            fallbackInput.value = '';
            fallbackHint.textContent = FALLBACK_HINT;
        }
    }

    // Petunjuk hidup saja; jenis sebenarnya ditentukan server.
    fallbackInput.addEventListener('input', () => {
        const v = fallbackInput.value.trim();

        if (v === '') {
            fallbackHint.textContent = FALLBACK_HINT;
        } else if (v.includes('@')) {
            fallbackHint.textContent = 'Terdeteksi: Email';
        } else if (/^\d{6,20}$/.test(v.replace(/[\s.\-]/g, ''))) {
            fallbackHint.textContent = 'Terdeteksi: NIK';
        } else {
            fallbackHint.textContent = 'Belum dikenali — NIK hanya angka, email mengandung @.';
        }
    });

    // Langkah 1: cari akun & susun draft.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();
        busy(searchBtn, true, 'Mencari…');

        try {
            const data = await postJson(form.action, new FormData(form));

            if (data.state === 'preview') {
                showServerPanel(data.html, 'preview');
            } else if (data.state === 'notfound') {
                showServerPanel(data.html, 'notfound');
                showFallback(true);
                fallbackInput.focus();
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

    // Tombol di dalam panel server (delegasi).
    panelServer.addEventListener('click', async (event) => {
        // --- Langkah antara: perbarui / setujui / lewati / periksa lagi ---
        // Semuanya membalas panel preview yang sudah disegarkan. Draft sengaja
        // TIDAK diklaim di sini, jadi kegagalan salah satunya tidak boleh
        // membuang panel yang masih sah — operator harus tetap bisa memilih
        // langkah lain.
        for (const [id, step] of Object.entries(STEP_BUTTONS)) {
            const stepBtn = event.target.closest('#' + id);

            if (!stepBtn) continue;

            if (stepBtn.dataset.confirm && !await confirmAction(confirmOptionsOf(stepBtn))) return;

            clearErrors();
            busy(stepBtn, true, step.busy);

            try {
                const data = await postJson(step.url, stepBody(stepBtn));

                if (data.state === 'preview') {
                    showServerPanel(data.html, 'preview');
                } else {
                    showErrors(data.errors.length ? data.errors : ['Langkah ini gagal.']);
                    busy(stepBtn, false);
                }
            } catch (error) {
                showErrors(['Gagal menghubungi server: ' + error.message]);
                busy(stepBtn, false);
            }

            return;
        }

        if (event.target.closest('#btn-focus-fallback')) {
            fallbackInput.focus();

            return;
        }

        // --- Langkah terakhir: Reset Passphrase (verifikasi HP / reset) ---
        const doBtn = event.target.closest('#btn-dispatch');

        if (doBtn) {
            if (!await confirmAction(confirmOptionsOf(doBtn))) return;

            clearErrors();
            busy(doBtn, true, 'Memproses…');

            try {
                const data = await postJson(DISPATCH_URL, stepBody(doBtn));

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
            showFallback(false);
            showIdle();
            phoneInput.focus();
        }
    });

    // Bila nomor diubah, panel konfirmasi jadi basi dan dibuang supaya tombol aksi
    // tidak sempat memakai draft lama. Panel hasil dikecualikan.
    for (const type of ['input', 'change']) {
        form.addEventListener(type, (event) => {
            clearErrors();

            const state = panelServer.dataset.state;

            if (state === 'preview') {
                showIdle();

                return;
            }

            // Mengetik di kolom cadangan TIDAK boleh menghapus panel penjelasan
            // "tidak ketemu" — panel itulah yang sedang dibaca operator. Hanya
            // mengubah NOMOR yang membatalkan premis "nomor ini tidak ketemu".
            if (state === 'notfound' && event.target === phoneInput) {
                showFallback(false);
                showIdle();
            }
        });
    }

    } // if (form)
</script>
<?= $this->endSection() ?>
