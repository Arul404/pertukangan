<?php

use CodeIgniter\Router\RouteCollection;

/**
 * Route milik shell Pertukangan.
 *
 * Route tiap modul tinggal di modules/<Nama>/Config/Routes.php dan ditemukan
 * otomatis lewat auto-discovery, jadi berkas ini tetap ringkas berapa pun
 * jumlah modul yang terpasang.
 *
 * @var RouteCollection $routes
 */

$routes->get('/', 'Dashboard::index');
