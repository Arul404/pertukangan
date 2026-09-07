<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Pager extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * Templates
     * --------------------------------------------------------------------------
     *
     * Pagination links are rendered out using views to configure their
     * appearance. This array contains aliases and the view names to
     * use when rendering the links.
     *
     * Within each view, the Pager object will be available as $pager,
     * and the desired group as $pagerGroup;
     *
     * @var array<string, string>
     */
    public array $templates = [
        // Template bawaan diganti versi Bootstrap 5: markup bawaan CodeIgniter
        // masih Bootstrap 3 (tanpa .page-item/.page-link) sehingga pagination
        // tampil telanjang di tema ini. Diganti pada kunci `default_full` —
        // bukan ditambah sebagai kunci baru — supaya halaman berpaginasi yang
        // dibuat nanti ikut benar tanpa perlu menyebut nama template.
        'default_full'   => 'pager/bootstrap5_full',
        'default_simple' => 'CodeIgniter\Pager\Views\default_simple',
        'default_head'   => 'CodeIgniter\Pager\Views\default_head',
    ];

    /**
     * --------------------------------------------------------------------------
     * Items Per Page
     * --------------------------------------------------------------------------
     *
     * The default number of results shown in a single page.
     */
    public int $perPage = 20;
}
