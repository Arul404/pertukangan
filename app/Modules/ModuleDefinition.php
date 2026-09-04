<?php

namespace App\Modules;

/**
 * Kontrak sebuah modul terhadap shell Pertukangan.
 *
 * Setiap modul memiliki satu turunan kelas ini (biasanya di
 * `modules/<Nama>/Config/Module.php`) yang menjelaskan dirinya: slug untuk URL,
 * nama & ikon untuk sidebar dan dashboard, serta daftar menu internalnya.
 *
 * Shell tidak pernah tahu isi sebuah modul — ia hanya membaca metadata di sini.
 * Menambah modul baru berarti: buat folder modul, daftarkan namespace-nya di
 * Config\Autoload::$psr4, lalu daftarkan kelas definisinya di Config\AppModules.
 */
abstract class ModuleDefinition
{
    /**
     * Slug modul: segmen URL pertama sekaligus kunci di registry.
     * Contoh 'tukang-kirim' -> semua route modul ada di bawah /tukang-kirim.
     */
    abstract public function slug(): string;

    /** Nama yang dibaca manusia, tampil di sidebar dan kartu dashboard. */
    abstract public function name(): string;

    /** Kelas ikon Bootstrap Icons, contoh 'bi-send-fill'. */
    abstract public function icon(): string;

    /** Satu kalimat penjelas untuk kartu dashboard. */
    abstract public function description(): string;

    /**
     * Menu internal modul, tampil bersarang di bawah nama modul pada sidebar.
     *
     * Tiap entri boleh salah satu dari:
     *  - Item biasa: ['label' => string, 'icon' => string, 'path' => string].
     *    `path` relatif terhadap slug modul; string kosong = halaman utama.
     *    Nilai ini dibandingkan dengan segmen URI kedua untuk menandai aktif.
     *  - Sub-kelompok: ['label' => string, 'items' => list<item biasa>], untuk
     *    mengelompokkan menu (mis. 'TTE', 'BSrE'). `label` jadi judul kelompok.
     *
     * @return list<array<string, mixed>>
     */
    abstract public function menu(): array;
}
