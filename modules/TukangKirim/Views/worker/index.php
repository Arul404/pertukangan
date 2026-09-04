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
        <h1 class="h3 mb-1">Worker Antrean</h1>
        <p class="text-muted mb-0">Pantau dan kelola pengiriman WhatsApp yang berjalan di latar
            (satu-per-satu dengan jeda, agar tidak diblokir).</p>
    </div>
    <a href="<?= module_url('logs') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i> Riwayat Kirim
    </a>
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

<?php if (! $status['running']): ?>
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
<?php endif; ?>

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
                    <button class="btn btn-success btn-sm" type="submit" <?= $status['running'] ? 'disabled' : '' ?>>
                        <i class="bi bi-play-fill me-1"></i> Nyalakan
                    </button>
                </form>

                <form method="post" action="<?= module_url('worker/stop') ?>" class="d-inline"
                      data-confirm-title="Matikan worker?"
                      data-confirm="Worker daemon akan keluar dengan rapi setelah pesan yang sedang berjalan selesai."
                      data-confirm-ok="Ya, Matikan" data-confirm-variant="danger">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-danger btn-sm" type="submit" <?= $status['running'] ? '' : 'disabled' ?>>
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
                <span class="badge text-bg-light border"><?= count($queued) ?> ditampilkan</span>
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
                    <tbody>
                        <?php if ($queued === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Antrean kosong.</td></tr>
                        <?php else: ?>
                            <?php foreach ($queued as $q): ?>
                                <tr>
                                    <td><?= (int) $q['id'] ?></td>
                                    <td><?= esc($q['recipient']) ?></td>
                                    <td>
                                        <span class="badge <?= $q['status'] === 'processing' ? 'text-bg-info' : 'text-bg-secondary' ?>">
                                            <?= esc($q['status']) ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted"><?= esc($q['created_at']) ?></td>
                                    <td class="text-end">
                                        <?php if ($q['status'] === 'queued'): ?>
                                            <form method="post" action="<?= module_url('worker/' . (int) $q['id'] . '/cancel') ?>"
                                                  class="d-inline js-confirm"
                                                  data-confirm-title="Batalkan pesan?"
                                                  data-confirm="Pesan ini akan dihapus dari antrean dan tidak dikirim."
                                                  data-confirm-ok="Ya, Batalkan" data-confirm-variant="danger">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-outline-danger btn-sm" type="submit">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
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

    // Polling status tiap 5 detik.
    async function refreshStatus() {
        try {
            const r = await fetch(STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
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
        } catch (e) { /* diam saja saat gagal polling */ }
    }
    setInterval(refreshStatus, 5000);

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

            if (!d.ok) {
                flushStatus.className = 'small mt-2 text-danger';
                flushStatus.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + (d.error || 'Gagal.');
            } else if (d.summary === null) {
                flushStatus.className = 'small mt-2 text-muted';
                flushStatus.innerHTML = '<i class="bi bi-inbox me-1"></i>Antrean kosong.';
            } else {
                const s = d.summary;
                flushStatus.className = 'small mt-2 ' + (s.ok ? 'text-success' : 'text-danger');
                flushStatus.innerHTML = (s.ok ? '<i class="bi bi-check-circle-fill me-1"></i>Terkirim' : '<i class="bi bi-x-circle-fill me-1"></i>Gagal')
                    + ' ke ' + s.recipient + ' (' + s.status + '). Sisa antrean: ' + d.pending + '. Muat ulang untuk memperbarui daftar.';
            }
        } catch (e) {
            flushStatus.className = 'small mt-2 text-danger';
            flushStatus.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + e.message;
        } finally {
            flushBtn.disabled = false;
            refreshStatus();
        }
    });
</script>
<?= $this->endSection() ?>
