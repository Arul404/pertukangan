<?php

namespace Modules\TukangKirim\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\TukangKirim\Config\MaxChat as MaxChatConfig;
use Modules\TukangKirim\Config\Module;
use Modules\TukangKirim\Libraries\SendQueueService;
use Modules\TukangKirim\Models\MessageQueueModel;
use Modules\TukangKirim\Models\WorkerSettingsModel;
use Throwable;

/**
 * Halaman kelola worker antrean kirim.
 *
 * Web tidak bisa men-spawn proses daemon, jadi halaman ini memantau (aktif/mati,
 * antrean, jeda) dan mengendalikan yang bisa dikendalikan lewat DB: jeda/lanjut,
 * setel jeda antar-kirim, minta worker berhenti, batalkan pesan antrean, serta
 * memproses satu pesan manual saat worker sedang tidak jalan.
 */
class Worker extends BaseController
{
    /** Harus sama dengan SendWorker::LOCK_NAME. */
    protected const LOCK_NAME = 'tukangkirim_send_worker';

    protected WorkerSettingsModel $settings;
    protected MessageQueueModel $queue;

    public function __construct()
    {
        $this->settings = new WorkerSettingsModel();
        $this->queue    = new MessageQueueModel();
    }

    public function index(): string
    {
        $config = config(MaxChatConfig::class);

        return view(Module::VIEWS . 'worker/index', [
            'title'    => 'Worker Antrean',
            'status'   => $this->snapshot($config),
            'settings' => $this->settings->current(),
            'config'   => $config,
            'queued'   => $this->queuedList(),
        ]);
    }

    /**
     * Status ringkas untuk polling (AJAX).
     *
     * Dengan `?queue=1` ikut mengirim daftar antrean yang sudah dirender server
     * (partial worker/_queue_rows). Itu yang membuat auto-refresh cukup
     * memperbarui tabelnya saja, bukan memuat ulang seluruh halaman — dan markup
     * barisnya tetap hidup di satu berkas, jadi tak bisa melenceng dari versi
     * yang dirender saat halaman pertama dibuka.
     */
    public function status(): ResponseInterface
    {
        $snapshot = $this->snapshot(config(MaxChatConfig::class));
        $extra    = [];

        if ($this->request->getGet('queue') !== null) {
            $queued = $this->queuedList();

            $extra = [
                'queued_html'  => view(Module::VIEWS . 'worker/_queue_rows', [
                    'queued' => $queued,
                    'status' => $snapshot,
                ]),
                'queued_count' => count($queued),
            ];
        }

        return $this->response->setJSON($snapshot + $extra + ['csrf' => csrf_hash()]);
    }

    /** Simpan jeda/lanjut + setelan jeda antar-kirim. */
    public function save(): RedirectResponse
    {
        $paused = $this->request->getPost('paused') !== null ? 1 : 0;
        $min    = $this->request->getPost('min_interval');
        $jitter = $this->request->getPost('jitter');

        $this->settings->save1([
            'paused'       => $paused,
            'min_interval' => ($min === '' || $min === null) ? null : max(0, (int) $min),
            'jitter'       => ($jitter === '' || $jitter === null) ? null : max(0, (int) $jitter),
        ]);

        return redirect()->to(module_url('worker'))->with('success', 'Setelan worker disimpan.');
    }

    /**
     * Nyalakan worker (best-effort): luncurkan daemon terpisah dari server web.
     *
     * Keandalannya bergantung lingkungan (server web menemukan php, boleh
     * meluncurkan proses). Bila gagal menyala, pakai scripts\worker-start.bat.
     */
    public function start(): RedirectResponse
    {
        if ($this->isRunning()) {
            return redirect()->to(module_url('worker'))->with('success', 'Worker sudah aktif.');
        }

        // Bersihkan sisa flag berhenti agar daemon baru tidak langsung keluar.
        $this->settings->save1(['stop_requested' => 0]);

        $config = config(MaxChatConfig::class);
        $php    = $config->phpBinary !== '' ? $config->phpBinary : 'php';
        $spark  = ROOTPATH . 'spark';
        $log    = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'worker.log';

        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = 'cmd /c start "TukangKirimWorker" /B "' . $php . '" "' . $spark
                . '" tukangkirim:work 1>> "' . $log . '" 2>&1';
        } else {
            $cmd = 'nohup "' . $php . '" "' . $spark . '" tukangkirim:work >> "' . $log . '" 2>&1 &';
        }

        $launched = false;

        try {
            $handle = @popen($cmd, 'r');

            if ($handle !== false) {
                @pclose($handle);
                $launched = true;
            }
        } catch (Throwable) {
            $launched = false;
        }

        if ($launched) {
            return redirect()->to(module_url('worker'))
                ->with('success', 'Perintah nyalakan dikirim. Tunggu beberapa detik, status akan berubah jadi "Aktif". '
                    . 'Bila tetap "Mati", jalankan scripts\\worker-start.bat.');
        }

        return redirect()->to(module_url('worker'))
            ->with('errors', ['Tidak bisa meluncurkan worker dari web. Jalankan scripts\\worker-start.bat di server.']);
    }

    /** Minta worker (daemon) berhenti dengan rapi. */
    public function stop(): RedirectResponse
    {
        $this->settings->save1(['stop_requested' => 1]);

        return redirect()->to(module_url('worker'))
            ->with('success', 'Permintaan berhenti dikirim. Worker akan keluar setelah pesan berjalan selesai.');
    }

    /**
     * Proses satu pesan sekarang dari web — hanya bila daemon TIDAK berjalan
     * (dijaga kunci yang sama), supaya tidak berbarengan dengan worker.
     */
    public function flush(): ResponseInterface
    {
        if (! $this->request->isAJAX()) {
            return redirect()->to(module_url('worker'));
        }

        $db  = db_connect();
        $got = (int) ($db->query('SELECT GET_LOCK(?, 0) AS l', [self::LOCK_NAME])->getRow()->l ?? 0);

        if ($got !== 1) {
            return $this->response->setJSON([
                'ok'    => false,
                'error' => 'Worker sedang berjalan — biarkan ia memproses antrean.',
                'csrf'  => csrf_hash(),
            ]);
        }

        $summary   = null;
        $recovered = ['requeued' => 0, 'abandoned' => 0];
        $error     = null;

        try {
            $service = new SendQueueService();

            // Kunci di tangan = tidak ada yang sedang mengirim, jadi sisa baris
            // `processing` pasti yatim. Bereskan dulu supaya pesan yang tersangkut
            // ikut terproses dalam klik yang sama.
            $recovered = $service->reclaimOrphans();
            $summary   = $service->processOne();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }

        if ($error !== null) {
            return $this->response->setJSON(['ok' => false, 'error' => $error, 'csrf' => csrf_hash()]);
        }

        return $this->response->setJSON([
            'ok'        => true,
            'summary'   => $summary,             // null bila antrean kosong
            'recovered' => $recovered,           // baris yatim yang dipulihkan/ditinggalkan
            'pending'   => $this->queue->pendingCount(),
            'csrf'      => csrf_hash(),
        ]);
    }

    /**
     * Batalkan satu pesan dari antrean.
     *
     * Baris `processing` hanya boleh dibatalkan saat worker mati: itu pasti sisa
     * proses yang terputus. Bila worker hidup, baris itu mungkin sedang benar-
     * benar dikirim — menghapusnya akan memotong jejak auditnya di tengah jalan.
     */
    public function cancel(int $id): RedirectResponse
    {
        $row = $this->queue->find($id);

        if ($row === null) {
            return redirect()->to(module_url('worker'))
                ->with('errors', ['Pesan tidak ditemukan — mungkin sudah selesai diproses.']);
        }

        if ($row['status'] === 'queued') {
            $this->queue->delete($id);

            return redirect()->to(module_url('worker'))->with('success', 'Pesan dibatalkan dari antrean.');
        }

        if ($row['status'] === 'processing') {
            if ($this->isRunning()) {
                return redirect()->to(module_url('worker'))->with('errors', [
                    'Pesan ini sedang diproses worker. Hentikan worker lebih dulu bila memang ingin membatalkannya.',
                ]);
            }

            $this->queue->delete($id);

            return redirect()->to(module_url('worker'))->with('success',
                'Pesan tersangkut dibatalkan. Pesan itu sisa worker yang berhenti di tengah pengiriman, '
                . 'jadi belum tentu benar-benar gagal terkirim — periksa Riwayat Kirim bila perlu.');
        }

        return redirect()->to(module_url('worker'))
            ->with('errors', ['Pesan tidak dapat dibatalkan (status: ' . $row['status'] . ').']);
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{running: bool, pending: int, paused: bool, oldest_age: int|null, last_sent_at: string|null, heartbeat_at: string|null, min_interval: int, jitter: int}
     */
    protected function snapshot(MaxChatConfig $config): array
    {
        return [
            'running'      => $this->isRunning(),
            'pending'      => $this->queue->pendingCount(),
            'paused'       => (int) $this->settings->current()['paused'] === 1,
            'oldest_age'   => $this->oldestQueuedAge(),
            'last_sent_at' => $this->lastSentAt(),
            'heartbeat_at' => $this->settings->current()['heartbeat_at'],
            'min_interval' => $this->settings->effectiveMinInterval($config),
            'jitter'       => $this->settings->effectiveJitter($config),
        ];
    }

    /** Worker dianggap berjalan bila kunci proses-tunggal sedang dipegang. */
    protected function isRunning(): bool
    {
        $db  = db_connect();
        $got = (int) ($db->query('SELECT GET_LOCK(?, 0) AS l', [self::LOCK_NAME])->getRow()->l ?? 0);

        if ($got === 1) {
            $db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]); // tak ada daemon — lepas lagi.

            return false;
        }

        return true; // dipegang proses lain = worker aktif.
    }

    /**
     * Umur (detik) pesan tertua yang masih menunggu.
     *
     * Memakai himpunan status yang sama dengan pendingCount(): baris `processing`
     * yang tersangkut ikut dihitung, supaya kartu "Menunggu" tidak menampilkan
     * angka sementara kartu "Tertua" kosong — persis gejala yang dulu membuat
     * baris yatim tak terlihat.
     */
    protected function oldestQueuedAge(): ?int
    {
        $row = $this->queue->whereIn('status', ['queued', 'processing'])->orderBy('id', 'ASC')->first();

        if ($row === null || empty($row['created_at'])) {
            return null;
        }

        return max(0, time() - strtotime($row['created_at']));
    }

    protected function lastSentAt(): ?string
    {
        $row = db_connect()->table('message_logs')
            ->select('created_at')
            ->where('status', 'success')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row['created_at'] ?? null;
    }

    /**
     * Daftar pesan menunggu untuk ditampilkan — TANPA isi/secret.
     *
     * @return list<array<string, mixed>>
     */
    protected function queuedList(): array
    {
        return $this->queue
            ->select('id, recipient, status, attempts, created_at')
            ->whereIn('status', ['queued', 'processing'])
            ->orderBy('id', 'ASC')
            ->findAll(50);
    }
}
