<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">Kirim Pesan</h1>
        <p class="text-muted mb-0">Pilih template, isi datanya, periksa hasilnya di panel kanan, lalu kirim.</p>
    </div>
    <a href="<?= module_url('templates') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-file-earmark-text me-1"></i> Kelola Template
    </a>
</div>

<?php if ($templates === []): ?>
    <div class="alert alert-warning">
        <strong>Belum ada template aktif.</strong>
        Buat dulu di menu <a href="<?= module_url('templates/create') ?>" class="alert-link">Template</a>,
        dan pastikan templatenya berstatus aktif.
    </div>
<?php else: ?>

    <?php if ($activeAccounts === 0): ?>
        <div class="alert alert-danger">
            <strong>Belum ada akun MaxChat aktif.</strong>
            Pesan tidak bisa dikirim. Tambahkan atau aktifkan akun di menu
            <a href="<?= module_url('accounts') ?>" class="alert-link">Akun MaxChat</a>.
        </div>
    <?php endif; ?>

    <div class="row g-3 align-items-start">

        <!-- ===== Kolom kiri: isian ===== -->
        <div class="col-lg-7">
            <div id="form-errors"></div>

            <form method="post" action="<?= module_url('send/preview') ?>" id="send-form" class="card shadow-sm">
                <?= csrf_field() ?>
                <div class="card-body">

                    <label class="form-label fw-semibold" for="template_id">
                        Template pesan <span class="text-danger">*</span>
                    </label>
                    <select class="form-select" id="template_id" name="template_id" required>
                        <option value="">— Pilih template —</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"
                                <?= (int) ($old['template_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
                                <?= esc($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="fill-block" hidden>
                        <hr class="my-4">
                        <div class="fw-semibold mb-2">Isi data</div>
                        <div class="row g-3" id="placeholder-fields"></div>
                        <div id="password-note" class="alert alert-info d-flex align-items-start mt-3 mb-0" hidden>
                            <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
                            <div class="small">
                                Template ini memuat <code class="placeholder-chip">[password]</code>.
                                Password acak (minimal 10 karakter, mengandung huruf kapital, angka, dan
                                karakter spesial) dibuat otomatis dan ditampilkan di panel kanan.
                            </div>
                        </div>
                    </div>

                    <div id="target-block" hidden>
                        <hr class="my-4">
                        <label class="form-label fw-semibold" for="to">
                            Nomor WhatsApp tujuan <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="to" name="to" inputmode="tel"
                               placeholder="08123456789" value="<?= esc($old['to'] ?? '') ?>" required>
                        <div class="form-text">
                            Boleh diketik <code>08xx</code>, <code>62xx</code>, atau <code>+62xx</code> — akan dinormalkan otomatis.
                        </div>
                    </div>
                </div>

                <div class="card-footer bg-white" id="submit-block" hidden>
                    <button type="submit" class="btn btn-primary btn-lg w-100" id="btn-preview">
                        <i class="bi bi-eye me-1"></i> Lihat Preview
                    </button>
                </div>
            </form>
        </div>

        <!-- ===== Kolom kanan: panel status ===== -->
        <div class="col-lg-5">
            <div class="panel-sticky">

                <!-- Panel bawaan. Hanya disembunyikan, tidak pernah dibuang. -->
                <div id="panel-idle">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h5 mb-3">Cara kerja</h2>

                            <div class="d-flex gap-3 mb-3">
                                <span class="step-num">1</span>
                                <div>
                                    <div class="fw-semibold">Susun pesan</div>
                                    <div class="text-muted small">
                                        Pilih template, lalu isi setiap placeholder yang muncul di sebelah kiri.
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3 mb-3">
                                <span class="step-num">2</span>
                                <div>
                                    <div class="fw-semibold">Periksa preview</div>
                                    <div class="text-muted small">
                                        Server menyusun teks finalnya dan menampilkannya di panel ini untuk Anda verifikasi.
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <span class="step-num">3</span>
                                <div>
                                    <div class="fw-semibold">Kirim</div>
                                    <div class="text-muted small">
                                        Yang dikirim persis teks yang Anda lihat — bukan susunan ulang dari form.
                                    </div>
                                </div>
                            </div>

                            <div class="note-box mt-3">
                                <i class="bi bi-shield-check me-1"></i>
                                Password dibuat otomatis oleh sistem, ditampilkan sekali di panel ini, dan
                                tidak pernah disimpan ke database.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Diisi HTML panel preview/hasil dari server. -->
                <div id="panel-server" hidden></div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const TEMPLATES    = <?= json_encode($templateMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const OLD_VALUES   = <?= json_encode((object) ($old['placeholders'] ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CSRF_NAME    = <?= json_encode(csrf_token()) ?>;
    const DISPATCH_URL = <?= json_encode(module_url('send/dispatch')) ?>;

    const form           = document.getElementById('send-form');
    const templateSelect = document.getElementById('template_id');
    const fieldsBox      = document.getElementById('placeholder-fields');
    const fillBlock      = document.getElementById('fill-block');
    const targetBlock    = document.getElementById('target-block');
    const submitBlock    = document.getElementById('submit-block');
    const previewBtn     = document.getElementById('btn-preview');
    const passwordNote   = document.getElementById('password-note');
    const errorBox       = document.getElementById('form-errors');
    const panelIdle      = document.getElementById('panel-idle');
    const panelServer    = document.getElementById('panel-server');
    const numberInput    = document.getElementById('to');

    // ---------- kolom kiri ----------

    // Bangun input untuk tiap placeholder manual milik template terpilih.
    function renderFields() {
        const template = TEMPLATES[templateSelect.value];
        fieldsBox.innerHTML = '';

        if (!template) {
            fillBlock.hidden = targetBlock.hidden = submitBlock.hidden = true;
            return;
        }

        for (const ph of template.placeholders) {
            const col = document.createElement('div');
            col.className = 'col-md-6';
            col.innerHTML =
                '<label class="form-label" for="ph-' + ph.name + '">' + ph.label +
                ' <span class="text-danger">*</span></label>' +
                '<input type="text" class="form-control" id="ph-' + ph.name + '"' +
                ' name="placeholders[' + ph.name + ']" data-placeholder="' + ph.name + '" required>' +
                '<div class="form-text">mengisi <code class="placeholder-chip">[' + ph.name + ']</code></div>';
            fieldsBox.appendChild(col);

            col.querySelector('input').value = OLD_VALUES[ph.name] || '';
        }

        fillBlock.hidden    = template.placeholders.length === 0 && !template.has_password;
        passwordNote.hidden = !template.has_password;
        targetBlock.hidden  = false;
        submitBlock.hidden  = false;
    }

    // ---------- kolom kanan ----------

    function showIdle() {
        panelServer.hidden = true;
        panelServer.innerHTML = '';
        delete panelServer.dataset.state;
        panelIdle.hidden = false;
    }

    // `state` menentukan apakah panel ini boleh dibatalkan saat isian diubah:
    // 'preview' boleh, 'result' tidak (memuat password yang cuma tampil sekali).
    function showServerPanel(html, state) {
        panelServer.innerHTML = html;
        panelServer.dataset.state = state;
        panelServer.hidden = false;
        panelIdle.hidden = true;
    }

    function showErrors(messages) {
        errorBox.innerHTML =
            '<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
            '<strong>Periksa kembali isian berikut:</strong><ul class="mb-0 mt-1"></ul>' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';

        const list = errorBox.querySelector('ul');

        for (const message of messages) {
            const item = document.createElement('li');
            item.textContent = message;   // pesan server disisipkan sebagai teks, bukan HTML
            list.appendChild(item);
        }
    }

    function clearErrors() {
        errorBox.innerHTML = '';
    }

    // Kunci tombol selama menunggu server; label aslinya disimpan untuk dipulihkan.
    function busy(button, on, label) {
        if (on) {
            button.dataset.idleHtml = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + label;
        } else if (button.dataset.idleHtml) {
            button.innerHTML = button.dataset.idleHtml;
        }

        button.disabled = on;
    }

    // Satu pintu untuk kedua endpoint. Hash CSRF baru dari respons langsung dipasang
    // ulang, karena token berganti tiap POST (Config\Security::$regenerate = true).
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

    // Langkah 2: minta server menyusun teks final.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();
        busy(previewBtn, true, 'Menyusun…');

        try {
            const data = await postJson(form.action, new FormData(form));

            if (data.state === 'preview') {
                showServerPanel(data.html, 'preview');
            } else {
                showErrors(data.errors.length ? data.errors : ['Preview gagal dibuat.']);
                showIdle();
            }
        } catch (error) {
            showErrors(['Gagal menghubungi server: ' + error.message]);
        } finally {
            busy(previewBtn, false);
        }
    });

    // Langkah 3 dan tombol-tombol lain di dalam panel kiriman server: semuanya
    // lewat delegasi, karena elemennya belum ada saat halaman dimuat.
    panelServer.addEventListener('click', async (event) => {
        const sendBtn = event.target.closest('#btn-dispatch');

        if (sendBtn) {
            if (! await confirmAction(confirmOptionsOf(sendBtn))) return;

            clearErrors();
            busy(sendBtn, true, 'Mengirim…');

            // Isi pesan tidak ikut dikirim ulang: server memakai draft yang sudah
            // tersimpan di session sejak preview.
            const body = new FormData();
            body.append(CSRF_NAME, form.querySelector('input[name="' + CSRF_NAME + '"]').value);

            try {
                const data = await postJson(DISPATCH_URL, body);

                if (data.state === 'result') {
                    showServerPanel(data.html, 'result');
                } else {
                    showErrors(data.errors.length ? data.errors : ['Pengiriman gagal diproses.']);
                    showIdle();
                }
            } catch (error) {
                showErrors(['Gagal menghubungi server: ' + error.message]);
                busy(sendBtn, false);
            }

            return;
        }

        if (event.target.closest('#btn-again')) {
            numberInput.value = '';

            for (const input of fieldsBox.querySelectorAll('input[data-placeholder]')) {
                input.value = '';
            }

            showIdle();
            numberInput.focus();

            return;
        }

        const copyBtn = event.target.closest('[data-copy-password]');

        if (copyBtn) {
            await navigator.clipboard.writeText(panelServer.querySelector('#pwd-value').textContent.trim());
            copyBtn.innerHTML = '<i class="bi bi-check2 me-1"></i> Tersalin';
            setTimeout(() => { copyBtn.innerHTML = '<i class="bi bi-clipboard me-1"></i> Salin'; }, 2000);
        }
    });

    // Begitu ada isian yang diubah, preview yang sedang tampil sudah basi: ia
    // dibuang supaya tombol Kirim tidak sempat mengirim draft lama. Panel hasil
    // sengaja dikecualikan — passwordnya hanya tampil sekali.
    //
    // Menyetel .value dari JavaScript tidak memicu event 'input', jadi
    // renderFields() dan tombol "Kirim pesan lain" tidak ikut terpicu.
    for (const type of ['input', 'change']) {
        form.addEventListener(type, () => {
            clearErrors();

            if (panelServer.dataset.state === 'preview') {
                showIdle();
            }
        });
    }

    templateSelect.addEventListener('change', renderFields);

    // Template bawaan (dari kiriman sebelumnya atau isian yang gagal divalidasi)
    // sudah terpilih lewat atribut selected, jadi cukup dirender apa adanya.
    renderFields();
</script>
<?= $this->endSection() ?>
