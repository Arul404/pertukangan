<?php

namespace App\Controllers;

use Config\AppModules;

/**
 * Halaman muka Pertukangan: pemilih modul.
 *
 * Isinya murni dibaca dari registry, jadi modul yang baru didaftarkan langsung
 * muncul di sini tanpa mengubah controller maupun view.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        return view('dashboard/index', [
            'title'   => 'Beranda',
            'modules' => config(AppModules::class)->all(),
        ]);
    }
}
