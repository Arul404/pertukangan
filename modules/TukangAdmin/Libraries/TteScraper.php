<?php

namespace Modules\TukangAdmin\Libraries;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\TukangAdmin\Config\Tte as TteConfig;

/**
 * Otomatisasi alur web aplikasi TTE Kabupaten Magelang lewat scraping.
 *
 * TTE tidak menyediakan API, jadi satu-satunya jalan adalah menirukan langkah
 * operator di browser: login, cari pengguna berdasarkan nomor HP, buka form
 * "ubah kata sandi", lalu submit kata sandi baru. TTE sendiri berbasis
 * CodeIgniter (cookie ci_session + token csrf_token_tte yang berganti tiap
 * request), jadi setiap POST wajib menyertakan token segar yang di-scrape dari
 * halaman yang memuat formnya.
 *
 * Sesi dijaga lewat cookie jar cURL berumur satu instance. Parsing HTML memakai
 * DOMXPath (bawaan PHP). Nama field & selector diambil dari {@see TteConfig}
 * supaya penyesuaian saat markup TTE berubah terpusat di satu berkas.
 *
 * CATATAN IMPLEMENTASI: struktur tabel /users dan form ubah-sandi hanya terlihat
 * setelah login. Parser di bawah dibuat toleran (mencari lewat pola, bukan
 * kelas CSS spesifik) dan difinalkan saat implementasi dengan login betulan.
 */
class TteScraper
{
    protected TteConfig $config;
    protected string $baseUrl;
    protected string $cookieJar;

    public function __construct(?TteConfig $config = null, ?string $baseUrl = null)
    {
        $this->config    = $config ?? config(TteConfig::class);
        $this->baseUrl   = rtrim($baseUrl ?: $this->config->baseURL, '/');
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'tte_');
    }

    public function __destruct()
    {
        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /**
     * Masuk ke TTE. Melempar TteScraperException bila kredensial ditolak.
     */
    public function login(string $username, string $password): void
    {
        $loginUrl = $this->baseUrl . $this->config->loginPath;

        $page  = $this->request('GET', $loginUrl);
        $token = $this->tokenFrom($page['body']);

        if ($token === null) {
            throw new TteScraperException('Token login TTE tidak ditemukan — halaman login mungkin berubah.');
        }

        $result = $this->request('POST', $loginUrl, [
            $this->config->tokenField    => $token,
            $this->config->loginField    => $username,
            $this->config->passwordField => $password,
        ]);

        // Gagal bila masih terdampar di halaman login (form kata sandi tampil
        // lagi) atau URL akhir belum beranjak dari /login.
        $stillOnLogin = str_contains($result['url'], $this->config->loginPath)
            || $this->hasPasswordField($result['body']);

        if ($stillOnLogin) {
            throw new TteScraperException('Login TTE gagal. Periksa kembali username dan kata sandi.');
        }
    }

    /**
     * Cari pengguna ber-role penandatangan lewat kata kunci (email atau NIK).
     *
     * Halaman /users memakai satu kotak pencarian bebas (parameter q), jadi
     * email maupun NIK sama-sama diteruskan sebagai kata kunci. Nomor HP tidak
     * lagi diketik operator melainkan dibaca dari baris hasil pencarian.
     *
     * @return array{email: string, name: string, phone: ?string, change_url: string, role: string}
     */
    public function findPenandatangan(string $query): array
    {
        $url = $this->baseUrl . $this->config->usersPath
            . '?' . http_build_query([$this->config->searchParam => $query]);
        $page = $this->request('GET', $url);

        $rows = $this->tableRows($page['body']);

        if ($rows === []) {
            throw new TteScraperException('Tidak ada pengguna yang cocok dengan "' . $query . '".');
        }

        $target   = strtolower($this->config->targetRole);
        $seenRole = false;

        foreach ($rows as $row) {
            $rowText = strtolower($row['text']);

            if (! str_contains($rowText, $target)) {
                continue;
            }

            $seenRole = true;
            $email    = $this->firstEmail($row['text']);

            if ($email === null) {
                continue;
            }

            $changeUrl = $this->changeLinkFrom($row['node']);

            if ($changeUrl === null) {
                throw new TteScraperException('Tombol "kata sandi" tidak ditemukan pada baris pengguna tersebut.');
            }

            return [
                'email'      => $email,
                'name'       => $this->nameFrom($row['node'], $email),
                'phone'      => $this->phoneFrom($row['text']),
                'change_url' => $changeUrl,
                'role'       => $this->config->targetRole,
            ];
        }

        if ($seenRole) {
            throw new TteScraperException('Pengguna dengan role "' . $this->config->targetRole
                . '" ditemukan, tetapi email atau tombol kata sandinya tidak terbaca.');
        }

        throw new TteScraperException('Tidak ada pengguna ber-role "' . $this->config->targetRole
            . '" untuk kata kunci "' . $query . '".');
    }

    /**
     * Ubah kata sandi pengguna hasil {@see self::findPenandatangan()}.
     *
     * Form ubah-sandi dibaca apa adanya: seluruh input tersembunyi (termasuk
     * token segar) dipertahankan, dan setiap input bertipe password diisi kata
     * sandi baru — dengan begitu nama field tepatnya tidak perlu ditebak.
     *
     * @param array{change_url: string} $user
     */
    public function changePassword(array $user, string $newPassword): void
    {
        $formUrl = $this->absoluteUrl($user['change_url']);
        $page    = $this->request('GET', $formUrl);

        $form = $this->passwordForm($page['body']);

        if ($form === null) {
            throw new TteScraperException('Form ubah kata sandi tidak ditemukan di halaman TTE.');
        }

        if ($form['password_fields'] === []) {
            throw new TteScraperException('Kolom kata sandi baru tidak terbaca pada form TTE.');
        }

        // Semua field password (baru + konfirmasi) diisi nilai yang sama.
        $fields = $form['fields'];

        foreach ($form['password_fields'] as $name) {
            $fields[$name] = $newPassword;
        }

        $action = $this->absoluteUrl($form['action'] !== '' ? $form['action'] : $user['change_url']);
        $result = $this->request('POST', $action, $fields);

        // Pada perubahan yang berhasil, TTE mengalihkan ke daftar /users (tidak
        // ada lagi input password). Bila form kata sandi muncul kembali, artinya
        // submit ditolak dan formnya dirender ulang — itu tanda gagal yang lebih
        // andal daripada menebak-nebak teks pesan galat.
        if ($result['code'] >= 400 || $this->hasPasswordField($result['body'])) {
            throw new TteScraperException('TTE menolak perubahan kata sandi (HTTP ' . $result['code'] . ').');
        }
    }

    // ---------------------------------------------------------------------
    // Bagian HTTP
    // ---------------------------------------------------------------------

    /**
     * Satu request cURL yang mempertahankan cookie sesi antar panggilan.
     *
     * @param array<string, string>|null $post Field form-urlencoded, atau null untuk GET.
     *
     * @return array{url: string, code: int, body: string}
     */
    protected function request(string $method, string $url, ?array $post = null): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_USERAGENT      => $this->config->userAgent,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifySsl ? 2 : 0,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
        }

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new TteScraperException('Gagal menghubungi TTE: ' . $error);
        }

        $info = curl_getinfo($ch);
        curl_close($ch);

        return [
            'url'  => (string) ($info['url'] ?? $url),
            'code' => (int) ($info['http_code'] ?? 0),
            'body' => (string) $body,
        ];
    }

    // ---------------------------------------------------------------------
    // Bagian parsing HTML
    // ---------------------------------------------------------------------

    protected function dom(string $html): DOMXPath
    {
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Prefix encoding agar karakter UTF-8 (mis. nama) tidak rusak.
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return new DOMXPath($doc);
    }

    /**
     * Nilai token csrf_token_tte dari sebuah halaman.
     */
    protected function tokenFrom(string $html): ?string
    {
        $xpath = $this->dom($html);
        $node  = $xpath->query('//input[@name="' . $this->config->tokenField . '"]/@value')->item(0);

        return $node !== null ? $node->nodeValue : null;
    }

    /**
     * Apakah halaman masih memuat input bertipe password (indikator form login
     * atau form ubah-sandi yang belum berhasil dilewati)?
     */
    protected function hasPasswordField(string $html): bool
    {
        return $this->dom($html)->query('//input[@type="password"]')->length > 0;
    }

    /**
     * Baris tabel hasil pencarian beserta teks gabungan tiap baris.
     *
     * @return list<array{node: DOMElement, text: string}>
     */
    protected function tableRows(string $html): array
    {
        $xpath = $this->dom($html);
        $rows  = [];

        foreach ($xpath->query('//table//tr[td]') as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            // Teks digabung PER SEL dengan pemisah baris. Tanpa pemisah, sel
            // yang bersebelahan menyatu ("Budi Santosobudi@...") sehingga regex
            // email dan deteksi role bisa salah tangkap lintas kolom.
            $cells = [];

            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = trim((string) $td->textContent);
            }

            $rows[] = ['node' => $tr, 'text' => implode("\n", $cells)];
        }

        return $rows;
    }

    /**
     * Email pertama yang muncul di sepotong teks.
     */
    protected function firstEmail(string $text): ?string
    {
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /**
     * Nomor HP seluler Indonesia pertama yang muncul di sepotong teks.
     *
     * Sengaja di-anchor ke awalan seluler (08/62/+62 diikuti 8) supaya NIK
     * (16 digit yang tidak berawalan itu) tidak salah tertangkap sebagai nomor.
     * Pemisah umum (spasi, titik, tanda hubung, kurung) dibersihkan per baris
     * sebelum dicocokkan agar nomor berformat "0812-3456-7890" tetap terbaca.
     */
    protected function phoneFrom(string $text): ?string
    {
        foreach (preg_split('/\R/', $text) ?: [$text] as $line) {
            $clean = preg_replace('/[\s().\-]/', '', $line);

            if (is_string($clean) && preg_match('/(?:\+?62|0)8\d{7,12}/', $clean, $m) === 1) {
                return $m[0];
            }
        }

        return null;
    }

    /**
     * href tautan/tombol "kata sandi" di dalam sebuah baris.
     */
    protected function changeLinkFrom(DOMElement $row): ?string
    {
        $xpath = new DOMXPath($row->ownerDocument);

        foreach ($xpath->query('.//a', $row) as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }

            $label = strtolower($a->textContent . ' ' . $a->getAttribute('title') . ' ' . $a->getAttribute('href'));

            if (str_contains($label, 'sandi') || str_contains($label, 'password')) {
                $href = $a->getAttribute('href');

                if ($href !== '' && $href !== '#') {
                    return $href;
                }
            }
        }

        return null;
    }

    /**
     * Tebakan nama pengguna: sel teks pertama yang bukan email, bukan angka,
     * dan bukan nama role — di tabel pengguna kolom nama umumnya mendahului
     * kolom lainnya, jadi "yang pertama cocok" lebih andal daripada "terpanjang"
     * (nama role seperti "Penandatangan" justru sering lebih panjang).
     */
    protected function nameFrom(DOMElement $row, string $email): string
    {
        $role = strtolower($this->config->targetRole);

        foreach ($row->getElementsByTagName('td') as $td) {
            $value = trim((string) $td->textContent);

            if ($value === '' || mb_strlen($value) >= 80) {
                continue;
            }

            if (str_contains($value, '@') || preg_match('/^[\d\s+\-]+$/', $value) === 1) {
                continue;
            }

            if (strtolower($value) === $role) {
                continue;
            }

            return $value;
        }

        return $email;
    }

    /**
     * Baca form yang memuat kolom kata sandi.
     *
     * @return array{action: string, fields: array<string, string>, password_fields: list<string>}|null
     */
    protected function passwordForm(string $html): ?array
    {
        $xpath = $this->dom($html);

        foreach ($xpath->query('//form[.//input[@type="password"]]') as $form) {
            if (! $form instanceof DOMElement) {
                continue;
            }

            $fields         = [];
            $passwordFields = [];

            foreach ($xpath->query('.//input', $form) as $input) {
                if (! $input instanceof DOMElement) {
                    continue;
                }

                $name = $input->getAttribute('name');

                if ($name === '') {
                    continue;
                }

                $type = strtolower($input->getAttribute('type'));

                if ($type === 'submit' || $type === 'button' || $type === 'reset') {
                    continue;
                }

                if ($type === 'checkbox' || $type === 'radio') {
                    if ($input->hasAttribute('checked')) {
                        $fields[$name] = $input->getAttribute('value');
                    }

                    continue;
                }

                $fields[$name] = $input->getAttribute('value');

                if ($type === 'password') {
                    $passwordFields[] = $name;
                }
            }

            // Sertakan select (mis. field yang wajib) dengan opsi terpilihnya.
            foreach ($xpath->query('.//select', $form) as $select) {
                if (! $select instanceof DOMElement) {
                    continue;
                }

                $name = $select->getAttribute('name');

                if ($name === '') {
                    continue;
                }

                $selected = $xpath->query('.//option[@selected]', $select)->item(0)
                    ?? $xpath->query('.//option', $select)->item(0);

                if ($selected instanceof DOMElement) {
                    $fields[$name] = $selected->getAttribute('value');
                }
            }

            return [
                'action'          => $form->getAttribute('action'),
                'fields'          => $fields,
                'password_fields' => $passwordFields,
            ];
        }

        return null;
    }

    /**
     * Ubah href relatif menjadi URL absolut terhadap host TTE.
     */
    protected function absoluteUrl(string $href): string
    {
        if ($href === '') {
            return $this->baseUrl;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        if (str_starts_with($href, '/')) {
            return $this->baseUrl . $href;
        }

        return $this->baseUrl . '/' . ltrim($href, '/');
    }
}
