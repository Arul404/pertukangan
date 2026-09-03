<?php

namespace Modules\TukangKirim\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Modules\TukangKirim\Config\Module;
use Modules\TukangKirim\Libraries\PlaceholderParser;
use Modules\TukangKirim\Models\TemplateModel;

/**
 * CRUD template pesan. Isi pesan boleh memuat placeholder [nama]; khusus
 * [password] nilainya dibangkitkan otomatis saat pengiriman.
 */
class Templates extends BaseController
{
    protected TemplateModel $templates;
    protected PlaceholderParser $parser;

    public function __construct()
    {
        $this->templates = new TemplateModel();
        $this->parser    = new PlaceholderParser();
    }

    public function index(): string
    {
        $rows = $this->templates->allOrdered();

        foreach ($rows as &$row) {
            $row['placeholders'] = $this->parser->extract($row['body']);
        }

        unset($row);

        return view(Module::VIEWS . 'templates/index', [
            'title'     => 'Template Pesan',
            'templates' => $rows,
            'parser'    => $this->parser,
        ]);
    }

    public function create(): string
    {
        return view(Module::VIEWS . 'templates/form', [
            'title'    => 'Template Baru',
            'template' => null,
        ]);
    }

    public function store(): RedirectResponse
    {
        $data = $this->collect();

        if (! $this->templates->insert($data)) {
            return redirect()->back()->withInput()->with('errors', $this->templates->errors());
        }

        return redirect()->to(module_url('templates'))->with('success', 'Template "' . $data['name'] . '" berhasil dibuat.');
    }

    public function edit(int $id): string
    {
        $template = $this->templates->find($id);

        if ($template === null) {
            throw PageNotFoundException::forPageNotFound('Template tidak ditemukan.');
        }

        return view(Module::VIEWS . 'templates/form', [
            'title'    => 'Ubah Template',
            'template' => $template,
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        if ($this->templates->find($id) === null) {
            throw PageNotFoundException::forPageNotFound('Template tidak ditemukan.');
        }

        $data = $this->collect();

        if (! $this->templates->update($id, $data)) {
            return redirect()->back()->withInput()->with('errors', $this->templates->errors());
        }

        return redirect()->to(module_url('templates'))->with('success', 'Template "' . $data['name'] . '" berhasil diperbarui.');
    }

    public function delete(int $id): RedirectResponse
    {
        $template = $this->templates->find($id);

        if ($template === null) {
            throw PageNotFoundException::forPageNotFound('Template tidak ditemukan.');
        }

        $this->templates->delete($id);

        return redirect()->to(module_url('templates'))->with('success', 'Template "' . $template['name'] . '" dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function collect(): array
    {
        return [
            'name'      => trim((string) $this->request->getPost('name')),
            // Isi pesan dibiarkan apa adanya (termasuk baris baru); escaping
            // dilakukan saat ditampilkan, bukan saat disimpan.
            'body'      => rtrim((string) $this->request->getPost('body')),
            'is_active' => $this->request->getPost('is_active') !== null ? 1 : 0,
        ];
    }
}
