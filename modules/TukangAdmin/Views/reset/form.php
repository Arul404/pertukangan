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
        <h1 class="h3 mb-0">Reset Password TTE</h1>
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
                            No HP <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control form-control-lg" id="phone" name="phone"
                               inputmode="tel" placeholder="08123456789" autocomplete="off" required autofocus
                               <?= $hasCredential ? '' : 'disabled' ?>>
                        <div class="form-text">
                            Nomor tujuan WhatsApp, sekaligus dipakai mencari akun di TTE.
                            Nomor ini tetap jadi tujuan pengiriman walau nanti dicari lewat NIK/email.
                        </div>
                    </div>

                    <div class="mb-3" id="fallback-wrap" hidden>
                        <label class="form-label fw-semibold" for="fallback">NIK atau Email</label>
                        <input type="text" class="form-control" id="fallback" name="fallback"
                               placeholder="16 digit NIK atau nama@instansi.go.id" autocomplete="off">
                        <div class="form-text" id="fallback-hint">
                            Dipakai hanya untuk menemukan akunnya di TTE. Jenisnya dikenali otomatis.
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
                                    <div class="fw-semibold">Cari lewat No HP</div>
                                    <div class="text-muted small">
                                        Sistem login ke TTE dan mencari akun <strong>penandatangan</strong>
                                        dengan nomor itu. Bila tidak ada yang cocok, kolom NIK/Email muncul
                                        sebagai jalan lain untuk menemukan akunnya.
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3 mb-3">
                                <span class="step-num">2</span>
                                <div>
                                    <div class="fw-semibold">Verifikasi nomor</div>
                                    <div class="text-muted small">
                                        Nomor yang Anda ketik dibandingkan dengan yang tercatat di TTE.
                                        Bila berbeda, Anda bisa <strong>memperbarui</strong> data di TTE atau
                                        <strong>melewatinya</strong> — pilihan ini tidak mengubah tujuan pengiriman.
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3 mb-3">
                                <span class="step-num">3</span>
                                <div>
                                    <div class="fw-semibold">Konfirmasi</div>
                                    <div class="text-muted small">
                                        Akun yang ditemukan dan kata sandi baru yang dibuat otomatis
                                        ditampilkan di sini untuk Anda periksa. Belum ada yang diubah.
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <span class="step-num">4</span>
                                <div>
                                    <div class="fw-semibold">Reset &amp; kirim</div>
                                    <div class="text-muted small">
                                        Baru setelah Anda menekan tombolnya, kata sandi TTE diganti dan
                                        dikirim lewat WhatsApp.
                                    </div>
                                </div>
                            </div>

                            <div class="note-box mt-3">
                                <i class="bi bi-send-check me-1"></i>
                                Pesan selalu dikirim ke <strong>nomor yang Anda masukkan</strong>, bukan ke
                                nomor yang tercatat di TTE — karena nomor di TTE bisa saja sudah usang.
                            </div>

                            <div class="note-box mt-2">
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
    const CSRF_NAME     = <?= json_encode(csrf_token()) ?>;
    const DISPATCH_URL  = <?= json_encode(module_url('reset/dispatch')) ?>;
    const UPDATE_WA_URL = <?= json_encode(module_url('reset/update-wa')) ?>;

    const form        = document.getElementById('reset-form');
    const searchBtn   = document.getElementById('btn-search');
    const errorBox    = document.getElementById('form-errors');
    const panelIdle   = document.getElementById('panel-idle');
    const panelServer = document.getElementById('panel-server');
    const phoneInput    = document.getElementById('phone');
    const fallbackWrap  = document.getElementById('fallback-wrap');
    const fallbackInput = document.getElementById('fallback');
    const fallbackHint  = document.getElementById('fallback-hint');

    // Tanpa template aktif, blok form tidak dirender sama sekali (lihat penjaga
    // $templates === [] di atas) — tanpa penjaga ini seluruh skrip halaman mati
    // oleh TypeError pada baris pertama yang menyentuh form.
    if (form) {

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

    function csrfValue() {
        return form.querySelector('input[name="' + CSRF_NAME + '"]').value;
    }

    // Kolom cadangan hanya muncul setelah pencarian lewat nomor gagal.
    function showFallback(on) {
        fallbackWrap.hidden = !on;
        searchBtn.innerHTML = on
            ? '<i class="bi bi-search me-1"></i> Cari dengan NIK/Email'
            : '<i class="bi bi-search me-1"></i> Cari Akun';

        if (!on) {
            fallbackInput.value = '';
            fallbackHint.textContent = 'Dipakai hanya untuk menemukan akunnya di TTE. Jenisnya dikenali otomatis.';
        }
    }

    // Petunjuk hidup saja; jenis sebenarnya ditentukan server.
    fallbackInput.addEventListener('input', () => {
        const v = fallbackInput.value.trim();

        if (v === '') {
            fallbackHint.textContent = 'Dipakai hanya untuk menemukan akunnya di TTE. Jenisnya dikenali otomatis.';
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

    // Langkah 2 dan tombol lain di dalam panel server (delegasi).
    panelServer.addEventListener('click', async (event) => {
        // --- Perbarui nomor WhatsApp di TTE (langkah antara, opsional) ---
        const waBtn = event.target.closest('#btn-update-wa');

        if (waBtn) {
            if (!await confirmAction(confirmOptionsOf(waBtn))) return;

            clearErrors();
            busy(waBtn, true, 'Memperbarui…');

            const body = new FormData();
            body.append(CSRF_NAME, csrfValue());
            body.append('draft_id', waBtn.dataset.draftId || '');

            try {
                const data = await postJson(UPDATE_WA_URL, body);

                if (data.state === 'preview') {
                    showServerPanel(data.html, 'preview');
                } else {
                    // Draft sengaja TIDAK diklaim di langkah ini, jadi preview
                    // masih sah: jangan buang panelnya — operator masih bisa
                    // menekan Lewati dan melanjutkan reset.
                    showErrors(data.errors.length ? data.errors : ['Gagal memperbarui nomor.']);
                    busy(waBtn, false);
                }
            } catch (error) {
                showErrors(['Gagal menghubungi server: ' + error.message]);
                busy(waBtn, false);
            }

            return;
        }

        // --- Lewati: murni sisi klien, tujuan kirim memang tidak berubah ---
        if (event.target.closest('#btn-skip-wa')) {
            const decision = panelServer.querySelector('[data-wa-decision]');
            const skipped  = panelServer.querySelector('[data-wa-skipped]');
            const dispatch = panelServer.querySelector('#btn-dispatch');

            if (decision) decision.hidden = true;
            if (skipped) skipped.hidden = false;
            if (dispatch) dispatch.disabled = false;

            return;
        }

        if (event.target.closest('#btn-focus-fallback')) {
            fallbackInput.focus();

            return;
        }

        const doBtn = event.target.closest('#btn-dispatch');

        if (doBtn) {
            if (!await confirmAction(confirmOptionsOf(doBtn))) return;

            clearErrors();
            busy(doBtn, true, 'Memproses…');

            const body = new FormData();
            body.append(CSRF_NAME, csrfValue());
            body.append('draft_id', doBtn.dataset.draftId || '');

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
            showFallback(false);
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
