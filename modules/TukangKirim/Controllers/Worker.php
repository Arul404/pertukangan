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

    /** Status ringkas untuk polling (AJAX). */
    public function status(): ResponseInterface
    {
        return $this->response->setJSON($this->snapshot(config(MaxChatConfig::class)) + ['csrf' => csrf_hash()]);
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

        try {
            $summary = (new SendQueueService())->processOne();
        } catch (Throwable $e) {
            $summary = null;
            $error   = $e->getMessage();
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }

        if (($error ?? null) !== null) {
            return $this->response->setJSON(['ok' => false, 'error' => $error, 'csrf' => csrf_hash()]);
        }

        return $this->response->setJSON([
            'ok'      => true,
            'summary' => $summary,             // null bila antrean kosong
            'pending' => $this->queue->pendingCount(),
            'csrf'    => csrf_hash(),
        ]);
    }

    /** Batalkan satu pesan yang masih menunggu di antrean. */
    public function cancel(int $id): RedirectResponse
    {
        $row = $this->queue->find($id);

        if ($row !== null && $row['status'] === 'queued') {
            $this->queue->delete($id);

            return redirect()->to(module_url('worker'))->with('success', 'Pesan dibatalkan dari antrean.');
        }

        return redirect()->to(module_url('worker'))
            ->with('errors', ['Pesan tidak dapat dibatalkan (sudah diproses atau tidak ada).']);
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

    /** Umur (detik) pesan tertua yang masih menunggu. */
    protected function oldestQueuedAge(): ?int
    {
        $row = $this->queue->where('status', 'queued')->orderBy('id', 'ASC')->first();

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
