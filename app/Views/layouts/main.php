<?php
/**
 * Kerangka halaman Pertukangan.
 *
 * Navigasi memakai sidebar kiri: daftar modul di atas, menu internal modul yang
 * sedang aktif tepat di bawahnya. Di layar sempit sidebar yang sama disajikan
 * sebagai offcanvas lewat tombol di topbar.
 */

use Config\AppModules;

// Sidebar membaca sendiri apa yang dibutuhkannya (lihat layouts/_sidebar.php);
// di sini hanya modul aktif yang diperlukan, untuk judul di topbar.
$activeModule = config(AppModules::class)->find(active_module());

// dryRun milik modul Tukang Kirim; shell tidak boleh bergantung padanya, jadi
// config-nya hanya dibaca saat modul itu memang sedang dibuka.
$dryRun = $activeModule !== null && $activeModule->slug() === 'tukang-kirim' && config('MaxChat')->dryRun;
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Beranda') ?> &middot; Pertukangan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6f8; }
        .message-preview {
            white-space: pre-wrap;
            word-break: break-word;
            font-family: inherit;
            margin: 0;
        }
        .bubble {
            background: #dcf8c6;
            border-radius: .9rem;
            padding: .85rem 1rem;
            max-width: 34rem;
            border: 1px solid #cbe7b3;
        }
        .chat-canvas { background: #ece5dd; border-radius: .75rem; padding: 1.25rem; }
        code.placeholder-chip {
            background: #e7f1ff; color: #0a58ca; border-radius: .3rem;
            padding: .1rem .35rem; font-size: .85em;
        }
        .password-box {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 1.35rem; letter-spacing: .08em;
        }
        /* Angka bulat pada daftar "Cara kerja" di panel kanan. */
        .step-num {
            flex: 0 0 1.75rem; height: 1.75rem;
            display: inline-flex; align-items: center; justify-content: center;
            background: #e7f1ff; color: #0a58ca;
            border-radius: 50%; font-size: .85rem; font-weight: 600;
        }
        .note-box {
            background: #f1f3f5; border-radius: .5rem;
            padding: .75rem .9rem; font-size: .875rem; color: #495057;
        }
        /* Panel kanan ikut turun mengikuti gulir, hanya pada layar lebar. */
        @media (min-width: 992px) {
            .panel-sticky { position: sticky; top: 1rem; }
        }

        /* --- Sidebar pemilih modul --- */
        .sidebar {
            background: #1b1f24;
            color: #adb5bd;
            width: 16rem;
            flex: 0 0 16rem;
            padding: 1rem .75rem;
        }
        .sidebar-brand {
            display: flex; align-items: center; gap: .6rem;
            color: #fff; text-decoration: none;
            font-weight: 700; font-size: 1.15rem; letter-spacing: -.02em;
            padding: .35rem .5rem 1rem;
        }
        .sidebar-brand i { font-size: 1.3rem; color: #ffc107; }
        .sidebar-heading {
            text-transform: uppercase; font-size: .7rem; font-weight: 600;
            letter-spacing: .08em; color: #6c757d;
            padding: 0 .5rem .4rem;
        }
        .sidebar-nav .nav-link {
            display: flex; align-items: center; gap: .6rem;
            color: #adb5bd; border-radius: .4rem;
            padding: .5rem .65rem; font-size: .925rem;
        }
        .sidebar-nav .nav-link:hover { background: #262b31; color: #fff; }
        .sidebar-nav .module-link { font-weight: 600; }
        .sidebar-nav .module-link.active { background: #0d6efd; color: #fff; }
        /* Submenu ditarik masuk dan diberi garis agar hierarkinya terbaca. */
        .sidebar-submenu {
            margin: .15rem 0 .35rem 1.1rem;
            padding-left: .55rem;
            border-left: 1px solid #343a40;
        }
        .sidebar-submenu .nav-link { font-size: .875rem; padding: .35rem .6rem; }
        .sidebar-submenu .nav-link.active { background: #262b31; color: #fff; }

        /* Sidebar tetap terlihat saat konten digulir, hanya pada layar lebar. */
        @media (min-width: 992px) {
            .sidebar-fixed { position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        }
        .offcanvas .sidebar { width: 100%; flex: 1 1 auto; }

        /* Tanpa ini kolom konten ikut melar mengikuti tabel lebar dan
           mendorong sidebar keluar layar. */
        .content-col { min-width: 0; }

        /* --- Kartu modul di dashboard --- */
        .module-card-icon {
            width: 3rem; height: 3rem;
            display: inline-flex; align-items: center; justify-content: center;
            background: #e7f1ff; color: #0a58ca;
            border-radius: .6rem; font-size: 1.5rem;
        }
        .module-card { transition: transform .12s ease, box-shadow .12s ease; }
        .module-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .08) !important; }
    </style>
</head>
<body>
<div class="d-flex align-items-start">
    <aside class="sidebar sidebar-fixed d-none d-lg-block">
        <?= $this->include('layouts/_sidebar') ?>
    </aside>

    <div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="sidebar-offcanvas">
        <div class="sidebar h-100 overflow-auto">
            <?= $this->include('layouts/_sidebar') ?>
        </div>
    </div>

    <div class="flex-grow-1 content-col">
        <header class="bg-white border-bottom">
            <div class="d-flex align-items-center gap-2 px-3 px-lg-4 py-2">
                <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button"
                        data-bs-toggle="offcanvas" data-bs-target="#sidebar-offcanvas"
                        aria-label="Buka menu">
                    <i class="bi bi-list"></i>
                </button>

                <span class="fw-semibold text-truncate">
                    <?php if ($activeModule !== null): ?>
                        <i class="bi <?= esc($activeModule->icon()) ?> me-1 text-secondary"></i><?= esc($activeModule->name()) ?>
                        <span class="text-muted fw-normal">&middot; <?= esc($title ?? '') ?></span>
                    <?php else: ?>
                        <?= esc($title ?? 'Beranda') ?>
                    <?php endif; ?>
                </span>

                <?php if ($dryRun): ?>
                    <span class="badge text-bg-warning ms-auto" title="maxchat.dryRun = true di .env">
                        <i class="bi bi-cone-striped"></i> MODE UJI COBA
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <main class="container-fluid px-3 px-lg-4 py-4">
            <?= $this->include('layouts/_flash') ?>
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="text-center text-muted py-4 small">
            Pertukangan &middot; aplikasi modular internal &middot; berjalan lokal
        </footer>
    </div>
</div>

<!-- Dialog konfirmasi bersama, dipakai semua aksi yang tidak bisa dibatalkan. -->
<div class="modal fade" id="confirm-modal" tabindex="-1" aria-labelledby="confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirm-title">Konfirmasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="confirm-body"></p>
                <div class="note-box fw-semibold mt-3" id="confirm-detail" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="confirm-ok">Ya, lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /**
     * Konfirmasi berbentuk modal Bootstrap, pengganti window.confirm().
     *
     * Seluruh teks dipasang lewat textContent, jadi nama template/akun yang
     * mengandung karakter HTML tidak bisa menyusup jadi markup.
     *
     * @returns {Promise<boolean>} true bila ditekan tombol lanjut.
     */
    window.confirmAction = function (options) {
        const modalEl = document.getElementById('confirm-modal');

        // Bila bootstrap.bundle gagal dimuat, jangan sampai aksi jadi tak
        // terkonfirmasi sama sekali — mundur ke dialog bawaan browser.
        if (typeof bootstrap === 'undefined' || !modalEl) {
            const text = [options.title, options.body, options.detail].filter(Boolean).join('\n\n');

            return Promise.resolve(window.confirm(text));
        }

        const detailBox = document.getElementById('confirm-detail');
        const okBtn     = document.getElementById('confirm-ok');

        document.getElementById('confirm-title').textContent = options.title || 'Konfirmasi';
        document.getElementById('confirm-body').textContent  = options.body || '';

        detailBox.textContent = options.detail || '';
        detailBox.hidden      = !options.detail;

        okBtn.textContent = options.okLabel || 'Ya, lanjutkan';
        okBtn.className   = 'btn btn-' + (options.variant || 'danger');

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        return new Promise((resolve) => {
            let settled = false;

            const settle = (answer) => {
                if (settled) return;
                settled = true;
                okBtn.removeEventListener('click', onConfirm);
                resolve(answer);
            };

            // 'hidden' juga menutupi tombol Batal, tombol silang, tekan Esc, dan
            // klik di luar modal — semuanya berarti tidak jadi.
            const onConfirm = () => { settle(true); modal.hide(); };

            okBtn.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', () => settle(false), { once: true });

            modal.show();
        });
    };

    /** Baca opsi konfirmasi dari atribut data-confirm-* sebuah elemen. */
    window.confirmOptionsOf = function (el) {
        return {
            title:   el.dataset.confirmTitle,
            body:    el.dataset.confirm || el.dataset.confirmBody,
            detail:  el.dataset.confirmDetail,
            okLabel: el.dataset.confirmOk,
            variant: el.dataset.confirmVariant,
        };
    };

    // Form biasa cukup diberi atribut data-confirm; tidak perlu skrip sendiri.
    // Event submit hanya terpicu setelah validasi HTML5 lolos, dan
    // form.submit() tidak memicu ulang listener ini, jadi tidak ada rekursi.
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-confirm]');

        if (!form || form.dataset.confirmed === 'yes') return;

        event.preventDefault();

        if (await window.confirmAction(window.confirmOptionsOf(form))) {
            form.dataset.confirmed = 'yes';
            form.submit();
        }
    });
</script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
