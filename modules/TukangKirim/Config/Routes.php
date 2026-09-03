<?php

use CodeIgniter\Router\RouteCollection;
use Modules\TukangKirim\Config\Module;

/**
 * Route modul Tukang Kirim.
 *
 * Ditemukan otomatis oleh auto-discovery CodeIgniter (Config\Modules::$aliases
 * memuat 'routes'), jadi tidak perlu didaftarkan dari app/Config/Routes.php.
 *
 * Seluruhnya berada di bawah prefix slug modul; controller-nya dicari di
 * namespace modul, bukan App\Controllers.
 *
 * @var RouteCollection $routes
 */

$routes->group(Module::SLUG, ['namespace' => 'Modules\TukangKirim\Controllers'], static function ($routes): void {
    // --- Kirim pesan: satu halaman, dua langkah POST lewat fetch() ---
    $routes->get('/', 'Send::index');
    $routes->post('send/preview', 'Send::preview');
    $routes->post('send/dispatch', 'Send::dispatch');

    // --- Template pesan ---
    $routes->group('templates', static function ($routes): void {
        $routes->get('/', 'Templates::index');
        $routes->get('create', 'Templates::create');
        $routes->post('store', 'Templates::store');
        $routes->get('(:num)/edit', 'Templates::edit/$1');
        $routes->post('(:num)/update', 'Templates::update/$1');
        $routes->post('(:num)/delete', 'Templates::delete/$1');
    });

    // --- Akun MaxChat (rotasi pengiriman) ---
    $routes->group('accounts', static function ($routes): void {
        $routes->get('/', 'MaxchatAccounts::index');
        $routes->get('create', 'MaxchatAccounts::create');
        $routes->post('store', 'MaxchatAccounts::store');
        $routes->get('(:num)/edit', 'MaxchatAccounts::edit/$1');
        $routes->post('(:num)/update', 'MaxchatAccounts::update/$1');
        $routes->post('(:num)/delete', 'MaxchatAccounts::delete/$1');
        $routes->post('(:num)/test', 'MaxchatAccounts::test/$1');
    });

    // --- Riwayat pengiriman ---
    $routes->get('logs', 'Logs::index');
    $routes->get('logs/(:num)', 'Logs::show/$1');
});
