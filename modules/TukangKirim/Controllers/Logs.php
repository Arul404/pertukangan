<?php

namespace Modules\TukangKirim\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;
use Modules\TukangKirim\Config\Module;
use Modules\TukangKirim\Models\MaxchatAccountModel;
use Modules\TukangKirim\Models\MessageLogModel;

/**
 * Riwayat pengiriman. Isi pesan yang disimpan sudah ter-mask: password asli
 * tidak pernah masuk database.
 */
class Logs extends BaseController
{
    protected MessageLogModel $logs;

    public function __construct()
    {
        $this->logs = new MessageLogModel();
    }

    public function index(): string
    {
        $filters = [
            'status'             => $this->request->getGet('status'),
            'maxchat_account_id' => $this->request->getGet('maxchat_account_id'),
            'q'                  => trim((string) $this->request->getGet('q')),
        ];

        return view(Module::VIEWS . 'logs/index', [
            'title'    => 'Riwayat Pengiriman',
            'logs'     => $this->logs->filtered($filters)->paginate(20),
            'pager'    => $this->logs->pager,
            'filters'  => $filters,
            'accounts' => (new MaxchatAccountModel())->forFilter(),
        ]);
    }

    public function show(int $id): string
    {
        $log = $this->logs->find($id);

        if ($log === null) {
            throw PageNotFoundException::forPageNotFound('Log tidak ditemukan.');
        }

        return view(Module::VIEWS . 'logs/show', [
            'title' => 'Detail Pengiriman #' . $id,
            'log'   => $log,
        ]);
    }
}
