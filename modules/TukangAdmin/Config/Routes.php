<?php

use CodeIgniter\Router\RouteCollection;
use Modules\TukangAdmin\Config\Module;

/**
 * Route modul Tukang Admin.
 *
 * Ditemukan otomatis oleh auto-discovery CodeIgniter (Config\Modules::$aliases
 * memuat 'routes'), jadi tidak perlu didaftarkan dari app/Config/Routes.php.
 *
 * Seluruhnya berada di bawah prefix slug modul; controller-nya dicari di
 * namespace modul, bukan App\Controllers.
 *
 * @var RouteCollection $routes
 */

$routes->group(Module::SLUG, ['namespace' => 'Modules\TukangAdmin\Controllers'], static function ($routes): void {
    // --- Reset password: satu halaman, dua langkah POST lewat fetch() ---
    $routes->get('/', 'ResetPassword::index');
    $routes->post('reset/search', 'ResetPassword::search');
    $routes->post('reset/dispatch', 'ResetPassword::dispatch');

    // --- Kredensial login TTE ---
    $routes->group('akun', static function ($routes): void {
        $routes->get('/', 'TteAccount::index');
        $routes->post('save', 'TteAccount::save');
        $routes->post('test', 'TteAccount::test');
    });

    // --- Reset passphrase BSrE: satu halaman, dua langkah POST lewat fetch() ---
    $routes->group('passphrase', static function ($routes): void {
        $routes->get('/', 'ResetPassphrase::index');
        $routes->post('search', 'ResetPassphrase::search');
        $routes->post('dispatch', 'ResetPassphrase::dispatch');
    });

    // --- Kredensial login BSrE + hubungkan (login Keycloak + TOTP) ---
    $routes->group('akun-bsre', static function ($routes): void {
        $routes->get('/', 'BsreAccount::index');
        $routes->post('save', 'BsreAccount::save');
        $routes->post('login', 'BsreAccount::login');
    });
});
