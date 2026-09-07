<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
    /**
     * @var array<string, mixed> $status
     * @var array<string, mixed> $settings
     * @var \Modules\TukangKirim\Config\MaxChat $config
     * @var list<array<string, mixed>> $queued
     */
    $fmtAge = static function (?int $s): string {
        if ($s === null) {
            return '—';
        }
        if ($s < 60) {
            return $s . ' dtk';
        }
        if ($s < 3600) {
            return floor($s / 60) . ' mnt';
        }

        return floor($s / 3600) . ' jam';
    };
?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Worker Antrean</h1>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="auto-refresh">
            <label class="form-check-label small" for="auto-refresh">Auto-refresh</label>
        </div>
        <select class="form-select form-select-sm" id="auto-interval" style="width:auto">
            <option value="5">5 dtk</option>
            <option value="10" selected>10 dtk</option>
            <option value="15">15 dtk</option>
            <option value="30">30 dtk</option>
        </select>
        <span class="text-muted small" id="refresh-countdown" style="min-width:9rem"></span>
        <a href="<?= module_url('logs') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clock-history me-1"></i> Riwayat Kirim
        </a>
    </div>
</div>

<!-- ===== Status ===== -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Status worker</div>
                <div class="h5 mb-0" id="st-running">
                    <?php if ($status['running']): ?>
                        <span class="badge text-bg-success"><i class="bi bi-broadcast me-1"></i>Aktif</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><i class="bi bi-slash-circle me-1"></i>Mati</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Menunggu di antrean</div>
                <div class="h4 mb-0"><span id="st-pending"><?= (int) $status['pending'] ?></span> pesan</div>
                <div class="small text-muted">tertua: <span id="st-oldest"><?= $fmtAge($status['oldest_age']) ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Pengiriman</div>
                <div class="h5 mb-0" id="st-paused">
                    <?php if ($status['paused']): ?>
                        <span class="badge text-bg-warning">Dijeda</span>
                    <?php else: ?>
                        <span class="badge text-bg-info">Berjalan</span>
                    <?php endif; ?>
                </div>
                <div class="small text-muted">jeda <span id="st-interval"><?= (int) $status['min_interval'] ?>–<?= (int) $status['min_interval'] + (int) $status['jitter'] ?></span> dtk</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Kirim terakhir</div>
                <div class="h6 mb-0" id="st-last"><?= esc($status['last_sent_at'] ?? '—') ?></div>
            </div>
        </div>
    </div>
</div>

<div id="worker-off-alert" <?= $status['running'] ? 'hidden' : '' ?>>
    <div class="alert alert-warning d-flex align-items-start">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div>
            <strong>Worker tidak berjalan.</strong> Pesan di antrean tidak akan terkirim sampai worker
            dijalankan. Jalankan di terminal server:
            <div class="mt-1"><code>php spark tukangkirim:work</code></div>
            atau jadwalkan <code>php spark tukangkirim:work --once</code> tiap menit (Task Scheduler).
            Untuk sekarang, Anda juga bisa menekan <em>Proses 1 sekarang</em> di bawah.
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- ===== Setelan ===== -->
    <div class="col-lg-5">
        <form method="post" action="<?= module_url('worker/save') ?>" class="card shadow-sm">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1"></i> Setelan pengiriman</div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="paused" name="paused"
                           value="1" <?= (int) $settings['paused'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="paused">
                        Jeda pengiriman <span class="text-muted small">(pesan tetap masuk antrean, tapi tidak dikirim)</span>
                    </label>
                </div>

                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label" for="min_interval">Jeda minimum (dtk)</label>
                        <input type="number" min="0" class="form-control" id="min_interval" name="min_interval"
                               value="<?= esc($settings['min_interval'] ?? '', 'attr') ?>"
                               placeholder="<?= (int) $config->sendMinInterval ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="jitter">Jitter acak (dtk)</label>
                        <input type="number" min="0" class="form-control" id="jitter" name="jitter"
                               value="<?= esc($settings['jitter'] ?? '', 'attr') ?>"
                               placeholder="<?= (int) $config->sendJitter ?>">
                    </div>
                </div>
                <div class="form-text">Kosongkan untuk memakai default (<?= (int) $config->sendMinInterval ?> + <?= (int) $config->sendJitter ?> dtk).
                    Jeda efektif tiap kirim = minimum + acak(0..jitter).</div>
            </div>
            <div class="card-footer bg-white d-flex flex-wrap gap-2 align-items-center">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i> Simpan</button>
                <button class="btn btn-outline-primary" type="button" id="btn-flush">
                    <i class="bi bi-lightning-charge me-1"></i> Proses 1 sekarang
                </button>
            </div>
        </form>

        <div id="flush-status" class="small mt-2"></div>

        <div class="card shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-power me-1"></i> Nyala / mati worker</div>
            <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <form method="post" action="<?= module_url('worker/start') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button class="btn btn-success btn-sm" type="submit" id="btn-worker-start" <?= $status['running'] ? 'disabled' : '' ?>>
                        <i class="bi bi-play-fill me-1"></i> Nyalakan
                    </button>
                </form>

                <form method="post" action="<?= module_url('worker/stop') ?>" class="d-inline"
                      data-confirm-title="Matikan worker?"
                      data-confirm="Worker daemon akan keluar dengan rapi setelah pesan yang sedang berjalan selesai."
                      data-confirm-ok="Ya, Matikan" data-confirm-variant="danger">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-danger btn-sm" type="submit" id="btn-worker-stop" <?= $status['running'] ? '' : 'disabled' ?>>
                        <i class="bi bi-stop-fill me-1"></i> Matikan
                    </button>
                </form>

                <span class="text-muted small ms-auto">
                    Atau via script: <code>scripts\worker-start.bat</code> / <code>scripts\worker-stop.bat</code>
                </span>
            </div>
        </div>
    </div>

    <!-- ===== Antrean ===== -->
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
                <span><i class="bi bi-list-ol me-1"></i> Antrean menunggu</span>
                <span class="badge text-bg-light border"><span id="queue-count"><?= count($queued) ?></span> ditampilkan</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Tujuan</th>
                            <th>Status</th>
                            <th>Masuk</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="queue-rows">
                        <?= view(\Modules\TukangKirim\Config\Module::VIEWS . 'worker/_queue_rows', ['queued' => $queued, 'status' => $status]) ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const STATUS_URL = <?= json_encode(module_url('worker/status')) ?>;
    const FLUSH_URL  = <?= json_encode(module_url('worker/flush')) ?>;
    const CSRF_NAME  = <?= json_encode(csrf_token()) ?>;

    function fmtAge(s) {
        if (s === null || s === undefined) return '—';
        if (s < 60) return s + ' dtk';
        if (s < 3600) return Math.floor(s / 60) + ' mnt';
        return Math.floor(s / 3600) + ' jam';
    }

    /**
     * Perbarui halaman di tempat — tidak pernah memuat ulang.
     *
     * withQueue=true ikut menarik daftar antrean yang sudah dirender server, jadi
     * tabelnya tidak dibangun ulang di sisi klien (markup baris tetap satu versi,
     * di worker/_queue_rows.php).
     */
    async function refreshStatus(withQueue = false) {
        try {
            const r = await fetch(STATUS_URL + (withQueue ? '?queue=1' : ''), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const d = await r.json();

            document.getElementById('st-running').innerHTML = d.running
                ? '<span class="badge text-bg-success"><i class="bi bi-broadcast me-1"></i>Aktif</span>'
                : '<span class="badge text-bg-secondary"><i class="bi bi-slash-circle me-1"></i>Mati</span>';
            document.getElementById('st-pending').textContent = d.pending;
            document.getElementById('st-oldest').textContent = fmtAge(d.oldest_age);
            document.getElementById('st-paused').innerHTML = d.paused
                ? '<span class="badge text-bg-warning">Dijeda</span>'
                : '<span class="badge text-bg-info">Berjalan</span>';
            document.getElementById('st-interval').textContent = d.min_interval + '–' + (d.min_interval + d.jitter);
            document.getElementById('st-last').textContent = d.last_sent_at || '—';

            // Peringatan "worker tidak berjalan" dan tombol nyala/mati ikut status,
            // supaya tidak basi saat worker dinyalakan/dimatikan dari terminal.
            document.getElementById('worker-off-alert').hidden = d.running;
            document.getElementById('btn-worker-start').disabled = d.running;
            document.getElementById('btn-worker-stop').disabled = !d.running;

            if (d.queued_html !== undefined) {
                document.getElementById('queue-rows').innerHTML = d.queued_html;
                document.getElementById('queue-count').textContent = d.queued_count;
            }
        } catch (e) { /* diam saja saat gagal polling */ }
    }

    // ===== Auto-refresh: perbarui kartu status + tabel antrean di tempat, dengan
    // jeda pilihan operator. Pilihan disimpan di browser.
    //
    // Tanpa auto-refresh, kartu status tetap diperbarui tiap 5 detik seperti
    // sebelumnya; yang ditambahkan saklar ini adalah daftar antrean, yang dulu
    // hanya ikut berubah kalau seluruh halaman dimuat ulang.
    (function () {
        const AR_ON = 'tk_worker_ar_on';
        const AR_IV = 'tk_worker_ar_iv';
        const cb = document.getElementById('auto-refresh');
        const sel = document.getElementById('auto-interval');
        const cd = document.getElementById('refresh-countdown');
        let countdown = null;
        let poll = null;

        const store = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };
        const load  = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };

        // Pulihkan pilihan tersimpan.
        if (load(AR_ON) === '1') cb.checked = true;
        if (load(AR_IV)) sel.value = load(AR_IV);

        function schedule() {
            clearInterval(countdown);
            clearInterval(poll);

            if (!cb.checked) {
                cd.textContent = '';
                poll = setInterval(() => refreshStatus(false), 5000);

                return;
            }

            const every = parseInt(sel.value, 10) || 10;
            let remain = every;

            cd.textContent = 'perbarui dalam ' + remain + ' dtk';
            countdown = setInterval(() => {
                remain -= 1;
                if (remain <= 0) remain = every;
                cd.textContent = 'perbarui dalam ' + remain + ' dtk';
            }, 1000);

            poll = setInterval(() => refreshStatus(true), every * 1000);
        }

        cb.addEventListener('change', () => { store(AR_ON, cb.checked ? '1' : '0'); schedule(); });
        sel.addEventListener('change', () => { store(AR_IV, sel.value); schedule(); });
        schedule();
    })();

    // Proses 1 pesan sekarang (manual).
    const flushBtn = document.getElementById('btn-flush');
    const flushStatus = document.getElementById('flush-status');

    flushBtn?.addEventListener('click', async () => {
        flushBtn.disabled = true;
        flushStatus.className = 'small mt-2 text-muted';
        flushStatus.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Memproses…';

        const body = new FormData();
        body.append(CSRF_NAME, document.querySelector('input[name="' + CSRF_NAME + '"]').value);

        try {
            const r = await fetch(FLUSH_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body });
            const d = await r.json();

            // Pesan tersangkut yang ikut dibereskan sebelum memproses.
            const rec = d.recovered || { requeued: 0, abandoned: 0 };
            const recNote = (rec.requeued || rec.abandoned)
                ? '<div class="text-warning-emphasis"><i class="bi bi-arrow-counterclockwise me-1"></i>Pemulihan: '
                    + rec.requeued + ' pesan tersangkut dikembalikan ke antrean, '
                    + rec.abandoned + ' ditinggalkan.</div>'
                : '';

            if (!d.ok) {
                flushStatus.className = 'small mt-2 text-danger';
                flushStatus.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + (d.error || 'Gagal.');
            } else if (d.summary === null) {
                flushStatus.className = 'small mt-2 text-muted';
                flushStatus.innerHTML = recNote + '<i class="bi bi-inbox me-1"></i>Antrean kosong.';
            } else {
                const s = d.summary;
                flushStatus.className = 'small mt-2 ' + (s.ok ? 'text-success' : 'text-danger');
                flushStatus.innerHTML = recNote
                    + (s.ok ? '<i class="bi bi-check-circle-fill me-1"></i>Terkirim' : '<i class="bi bi-x-circle-fill me-1"></i>Gagal')
                    + ' ke ' + s.recipient + ' (' + s.status + '). Sisa antrean: ' + d.pending + '.';
            }
        } catch (e) {
            flushStatus.className = 'small mt-2 text-danger';
            flushStatus.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + e.message;
        } finally {
            flushBtn.disabled = false;
            // Ikut menarik tabel: baris yang barusan diproses langsung hilang.
            refreshStatus(true);
        }
    });
</script>
<?= $this->endSection() ?>
