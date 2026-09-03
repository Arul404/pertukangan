<?php

/**
 * Helper navigasi antar modul.
 *
 * Semua tautan di dalam sebuah modul dibangun lewat module_url() supaya prefix
 * URL modul hanya ditulis di satu tempat: slug pada kelas definisi modul. Bila
 * suatu saat slug berubah, tidak ada satu pun view yang perlu disentuh.
 */

use Config\AppModules;

if (! function_exists('uri_segments')) {
    /**
     * Segmen URI saat ini sebagai larik ber-indeks 0.
     *
     * Dipakai menggantikan URI::getSegment(), yang melempar HTTPException
     * begitu nomor segmen melewati panjang URI + 1 — di '/' bahkan permintaan
     * segmen kedua sudah melempar, dan nilai bawaannya tidak menolong.
     *
     * @return list<string>
     */
    function uri_segments(): array
    {
        return service('uri')->getSegments();
    }
}

if (! function_exists('active_module')) {
    /**
     * Slug modul yang sedang dibuka, diambil dari segmen URI pertama.
     *
     * Mengembalikan null di dashboard atau bila segmen pertama bukan modul
     * terdaftar — jadi pemanggilnya wajib menangani ketiadaan modul.
     */
    function active_module(): ?string
    {
        $segment = uri_segments()[0] ?? '';

        if ($segment === '') {
            return null;
        }

        return config(AppModules::class)->find($segment) !== null ? $segment : null;
    }
}

if (! function_exists('active_module_path')) {
    /**
     * Path item menu yang sedang dibuka, yaitu segmen URI kedua.
     *
     * String kosong berarti halaman utama modul — nilai yang sama dipakai
     * ModuleDefinition::menu() untuk menandai item pertamanya.
     */
    function active_module_path(): string
    {
        return uri_segments()[1] ?? '';
    }
}

if (! function_exists('module_url')) {
    /**
     * URL absolut ke sebuah path di dalam modul.
     *
     * module_url()             -> /tukang-kirim         (halaman utama modul aktif)
     * module_url('templates')  -> /tukang-kirim/templates
     * module_url('', 'lain')   -> /lain                 (modul lain, eksplisit)
     *
     * @param string      $path Path relatif terhadap slug modul.
     * @param string|null $slug Slug modul; default modul yang sedang aktif.
     */
    function module_url(string $path = '', ?string $slug = null): string
    {
        $slug ??= active_module();

        // Di luar konteks modul (mis. dashboard) tidak ada prefix yang bisa
        // ditebak; kembalikan URL apa adanya daripada menghasilkan tautan salah.
        if ($slug === null) {
            return site_url($path);
        }

        $path = trim($path, '/');

        return site_url($path === '' ? $slug : $slug . '/' . $path);
    }
}
