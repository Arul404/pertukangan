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
     * Seluruh baris yang cocok dikumpulkan lebih dulu, bukan diambil yang
     * pertama: satu kata kunci bisa mengenai beberapa penandatangan, dan sejak
     * modul ini juga MENULIS (nomor WhatsApp) selain mereset kata sandi,
     * menebak salah satu berarti berisiko mengubah data orang yang keliru.
     *
     * @return array{email: string, name: string, phone: ?string, change_url: string, edit_url: ?string, role: string}
     */
    public function findPenandatangan(string $query): array
    {
        $url = $this->baseUrl . $this->config->usersPath
            . '?' . http_build_query([$this->config->searchParam => $query]);
        $page = $this->request('GET', $url);

        $rows = $this->tableRows($page['body']);

        if ($rows === []) {
            throw new TteUserNotFoundException('Tidak ada pengguna yang cocok dengan "' . $query . '".');
        }

        $target   = strtolower($this->config->targetRole);
        $seenRole = false;
        $matches  = [];

        foreach ($rows as $row) {
            if (! str_contains(strtolower($row['text']), $target)) {
                continue;
            }

            $seenRole = true;
            $email    = $this->firstEmail($row['text']);

            if ($email === null) {
                continue;
            }

            $changeUrl = $this->changeLinkFrom($row['node']);

            if ($changeUrl === null) {
                continue;
            }

            $matches[] = [
                'email'      => $email,
                'name'       => $this->nameFrom($row['node'], $email),
                'phone'      => $this->phoneFrom($row['text']),
                'change_url' => $changeUrl,
                // Opsional: fitur perbarui nomor mati bila tombolnya tak terbaca,
                // tetapi reset kata sandi—fungsi utamanya—harus tetap jalan.
                'edit_url'   => $this->editLinkFrom($row['node']),
                'role'       => $this->config->targetRole,
            ];
        }

        if (count($matches) > 1) {
            throw new TteAmbiguousMatchException('Kata kunci "' . $query . '" cocok dengan '
                . count($matches) . ' pengguna. Gunakan kata kunci yang lebih spesifik (email atau NIK) '
                . 'supaya tidak salah orang.');
        }

        if ($matches !== []) {
            return $matches[0];
        }

        if ($seenRole) {
            throw new TteUserNotFoundException('Pengguna dengan role "' . $this->config->targetRole
                . '" ditemukan, tetapi email atau tombol kata sandinya tidak terbaca.');
        }

        throw new TteUserNotFoundException('Tidak ada pengguna ber-role "' . $this->config->targetRole
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

    /**
     * Ubah nomor WhatsApp pengguna lewat form "ubah pengguna" di TTE.
     *
     * Menyimpan form ubah berarti mengirim ULANG seluruh kolomnya, jadi seluruh
     * pemeriksaan diselesaikan SEBELUM satu-satunya POST: begitu ada yang tidak
     * beres, tidak ada apa pun yang terkirim. Sesudah simpan, halaman dibaca
     * ulang — itu satu-satunya sinyal yang membaca nilai TERSIMPAN, bukan yang
     * dirender — sekaligus dipakai membandingkan kolom lain agar kerusakan tak
     * sengaja langsung kelihatan, bukan baru ketahuan berminggu kemudian.
     *
     * @param array{edit_url: string} $user
     *
     * @return array{before: ?string, after: string, changed: bool, verified: bool,
     *                unreadable: list<string>, collateral: array<string, array{0: string, 1: string}>}
     */
    public function updateWhatsapp(array $user, string $number): array
    {
        // Penjaga terakhir: pustaka ini harus mustahil menulis sampah ke TTE
        // walaupun pemanggilnya keliru.
        if (preg_match('/^\+?\d{8,15}$/', $number) !== 1) {
            throw new TteScraperException('Nomor tujuan tidak sah untuk disimpan ke TTE: "' . $number . '".');
        }

        $editUrl = trim((string) ($user['edit_url'] ?? ''));

        if ($editUrl === '') {
            throw new TteScraperException('Tombol "ubah" tidak terbaca pada baris pengguna, jadi nomor tidak bisa diperbarui.');
        }

        $formUrl = $this->absoluteUrl($editUrl);
        $page    = $this->request('GET', $formUrl);

        if ($page['code'] >= 400) {
            throw new TteScraperException('Halaman ubah pengguna TTE tidak bisa dibuka (HTTP ' . $page['code'] . ').');
        }

        // Beginilah wujud nyata 403/sesi habis: TTE mengalihkan ke halaman login.
        if ($this->hasPasswordField($page['body']) && str_contains($page['url'], $this->config->loginPath)) {
            throw new TteScraperException('Sesi TTE berakhir atau akun tidak berwenang mengubah data pengguna.');
        }

        $field = $this->config->whatsappField;
        $form  = $this->fieldForm($page['body'], $field);

        if ($form === null) {
            throw new TteScraperException('Kolom "' . $field . '" tidak ditemukan pada form ubah pengguna TTE. '
                . 'Tidak ada data yang dikirim. Sesuaikan tte.whatsappField bila nama kolomnya berubah.');
        }

        if ($this->config->editStrictFields && $form['unreadable'] !== []) {
            throw new TteScraperException('Nilai kolom ' . implode(', ', $form['unreadable'])
                . ' pada form TTE tidak bisa dipastikan dari halaman, jadi penyimpanan dibatalkan '
                . 'agar data pengguna tidak tertimpa nilai tebakan. Tidak ada yang dikirim.');
        }

        $fieldsBefore = $form['fields'];
        $before       = $fieldsBefore[$field] ?? null;
        $formatted    = $this->formatPhoneLike($before, $number);

        // Sudah sama: jangan menulis apa pun. Ini juga yang membuat klik ganda
        // pada tombol Perbarui tidak berbahaya.
        if ($before !== null && $this->digitsOnly($before) === $this->digitsOnly($formatted)) {
            return [
                'before'     => $before,
                'after'      => $before,
                'changed'    => false,
                'verified'   => true,
                'unreadable' => $form['unreadable'],
                'collateral' => [],
            ];
        }

        $fields = $fieldsBefore;

        // Browser tidak pernah mem-prefill value input password, jadi hasil panen
        // selalu ''. Mengirim password='' berisiko mengosongkan sandi pada
        // aplikasi yang memeriksa isset().
        foreach ($form['password_fields'] as $passwordField) {
            if (($fields[$passwordField] ?? '') === '') {
                unset($fields[$passwordField]);
            }
        }

        $fields[$field] = $formatted;

        $action = $this->absoluteUrl($form['action'] !== '' ? $form['action'] : $editUrl);
        $result = $this->request('POST', $action, $fields);

        if ($result['code'] >= 400) {
            throw new TteScraperException('TTE menolak perubahan nomor (HTTP ' . $result['code'] . ').');
        }

        // Baca ulang: satu-satunya cara mengetahui apa yang benar-benar tersimpan.
        $verified   = false;
        $collateral = [];

        try {
            $recheck = $this->request('GET', $formUrl);
            $after   = $this->fieldForm($recheck['body'], $field);
        } catch (TteScraperException) {
            $after = null;
        }

        if ($after !== null && array_key_exists($field, $after['fields'])) {
            $stored = $after['fields'][$field];

            if ($this->digitsOnly($stored) === $this->digitsOnly($formatted)) {
                $verified = true;
            } elseif ($before !== null && $this->digitsOnly($stored) === $this->digitsOnly($before)) {
                throw new TteScraperException('TTE menerima permintaan tetapi nomor tidak berubah '
                    . '(kemungkinan ditolak validasi). Tidak ada yang perlu dibatalkan.');
            }

            foreach ($fieldsBefore as $key => $value) {
                if ($key === $field || in_array($key, $form['password_fields'], true)) {
                    continue;
                }

                // Token CSRF memang berganti tiap request; itu bukan kerusakan.
                if ($key === $this->config->tokenField) {
                    continue;
                }

                $now = $after['fields'][$key] ?? '';

                if ($now !== $value) {
                    $collateral[$key] = [$value, $now];
                }
            }
        }

        return [
            'before'     => $before,
            'after'      => $formatted,
            'changed'    => true,
            // Ambiguitas bukan kegagalan: pesan tetap dikirim ke nomor yang
            // diketik operator, jadi ini tidak boleh memblokir reset.
            'verified'   => $verified,
            'unreadable' => $form['unreadable'],
            'collateral' => $collateral,
        ];
    }

    /** Hanya digitnya, untuk membandingkan dua nomor tanpa peduli formatnya. */
    protected function digitsOnly(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    /**
     * Tulis nomor dalam bentuk yang sudah dipakai TTE. TTE menyimpan 08…, jadi
     * jangan lawan konvensinya sendiri tanpa alasan.
     */
    protected function formatPhoneLike(?string $existing, string $number): string
    {
        $digits = $this->digitsOnly($number);

        if (str_starts_with($digits, '62')) {
            $local = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $local = $digits;
        } else {
            $local = '0' . $digits;
        }

        $international = '62' . ltrim($local, '0');

        $style = $this->config->whatsappFormat;

        if ($style === 'local') {
            return $local;
        }

        if ($style === 'international') {
            return $international;
        }

        // auto: ikuti bentuk nilai lama.
        $existingDigits = $existing !== null ? $this->digitsOnly($existing) : '';

        return str_starts_with($existingDigits, '62') ? $international : $local;
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
     * Pencocokan dilakukan PER TOKEN (dipisah spasi/baris), bukan pada teks yang
     * sudah digabung: kalau spasi ikut dibuang, nomor bisa menyatu dengan angka
     * kolom sebelah (mis. "081364118400 03" -> "08136411840003") sehingga MaxChat
     * menolaknya. Tiap token hanya diterima bila SELURUHNYA berupa nomor seluler
     * yang sah: awalan 08/62/+62, digit kedua setelah 8 adalah 1-9 (mengecualikan
     * NIK dan awalan tak sah seperti 080), dengan panjang wajar. Formatnya sendiri
     * (tanda hubung/titik/kurung) tetap ditoleransi karena dibuang per token.
     */
    protected function phoneFrom(string $text): ?string
    {
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $token) {
            $clean = preg_replace('/[().\-]/', '', $token);

            if (is_string($clean) && preg_match('/^(?:\+?62|0)8[1-9]\d{7,10}$/', $clean) === 1) {
                return $clean;
            }
        }

        return null;
    }

    /** Pecah daftar kata kunci ber-koma dari config jadi potongan huruf kecil. */
    protected function needles(string $csv): array
    {
        $out = [];

        foreach (explode(',', $csv) as $piece) {
            $piece = strtolower(trim($piece));

            if ($piece !== '') {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * href tautan pertama di dalam sebuah baris yang labelnya cocok $needles.
     *
     * $reject diuji LEBIH DULU: sebuah tautan yang cocok daftar tolak dilewati
     * tanpa pernah diuji ke $needles. Itulah yang membuat tombol "UBAH" dan
     * "KATA SANDI" mustahil tertukar — pemisahannya struktural, bukan kebetulan
     * karena labelnya kebetulan berbeda.
     *
     * @param list<string> $needles
     * @param list<string> $reject
     */
    protected function linkFrom(DOMElement $row, array $needles, array $reject = []): ?string
    {
        $xpath = new DOMXPath($row->ownerDocument);

        foreach ($xpath->query('.//a', $row) as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }

            $href = $a->getAttribute('href');

            // Tombol yang aksinya dijalankan JavaScript (mis. "Kirim Pesan"
            // dengan href="javascript:void(0)") bukan URL yang bisa diikuti.
            if ($href === '' || $href === '#' || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }

            $label = strtolower(
                $a->textContent . ' ' . $a->getAttribute('title')
                . ' ' . $a->getAttribute('aria-label') . ' ' . $href,
            );

            foreach ($reject as $bad) {
                if (str_contains($label, $bad)) {
                    continue 2;
                }
            }

            foreach ($needles as $needle) {
                if (str_contains($label, $needle)) {
                    return $href;
                }
            }
        }

        return null;
    }

    /** href tombol "ubah kata sandi" pada sebuah baris. */
    protected function changeLinkFrom(DOMElement $row): ?string
    {
        return $this->linkFrom($row, $this->needles($this->config->passwordLinkNeedles));
    }

    /** href tombol "ubah data pengguna" pada sebuah baris. */
    protected function editLinkFrom(DOMElement $row): ?string
    {
        return $this->linkFrom(
            $row,
            $this->needles($this->config->editLinkNeedles),
            $this->needles($this->config->passwordLinkNeedles),
        );
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
     * Baca form PERTAMA yang cocok dengan $formXPath, apa adanya.
     *
     * Seluruh input tersembunyi ikut terbawa tanpa kode ini perlu tahu artinya —
     * itulah sebabnya `changePassword()` berhasil selama ini: form ubah sandi
     * TTE memakai method spoofing `_method=PUT` yang tak pernah disebut di mana
     * pun di kelas ini. Regresi yang menjatuhkan hidden field akan mematahkan
     * reset kata sandi secara diam-diam, jadi jangan pernah menyaring field di
     * sini; saring di pemanggil bila perlu.
     *
     * `unreadable` memuat nama kolom yang nilainya hanya bisa DITEBAK dari
     * markup (mis. <select> tanpa atribut selected, yang nilainya dipasang
     * JavaScript). Untuk sekadar mengirim ulang form sandi itu tidak berbahaya,
     * tetapi untuk menyimpan form ubah pengguna itu berarti berpotensi menggeser
     * kolom lain — {@see self::updateWhatsapp()} menolak menyimpan bila ada.
     *
     * @return array{action: string, fields: array<string, string>, password_fields: list<string>, unreadable: list<string>}|null
     */
    protected function readForm(string $html, string $formXPath): ?array
    {
        $xpath = $this->dom($html);
        $forms = $xpath->query($formXPath);

        if ($forms === false) {
            return null;
        }

        foreach ($forms as $form) {
            if (! $form instanceof DOMElement) {
                continue;
            }

            $fields         = [];
            $passwordFields = [];
            $unreadable     = [];

            foreach ($xpath->query('.//input', $form) as $input) {
                if (! $input instanceof DOMElement) {
                    continue;
                }

                $name = $input->getAttribute('name');

                if ($name === '' || $input->hasAttribute('disabled')) {
                    continue; // Browser tidak mengirim kontrol disabled.
                }

                $type = strtolower($input->getAttribute('type'));

                if (in_array($type, ['submit', 'button', 'reset', 'file', 'image'], true)) {
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

            foreach ($xpath->query('.//select', $form) as $select) {
                if (! $select instanceof DOMElement) {
                    continue;
                }

                $name = $select->getAttribute('name');

                if ($name === '' || $select->hasAttribute('disabled')) {
                    continue;
                }

                $chosen = $xpath->query('.//option[@selected]', $select);

                if ($select->hasAttribute('multiple')) {
                    $key = str_ends_with($name, '[]') ? $name : $name . '[]';

                    foreach ($chosen as $option) {
                        if ($option instanceof DOMElement) {
                            $fields[$key] = $this->optionValue($option);
                        }
                    }

                    continue;
                }

                $option = $chosen->item(0);

                if ($option === null) {
                    // Browser mengirim opsi pertama, jadi itu yang dipakai —
                    // tetapi nilainya tebakan, maka dicatat.
                    $option       = $xpath->query('.//option', $select)->item(0);
                    $unreadable[] = $name;
                }

                if ($option instanceof DOMElement) {
                    $fields[$name] = $this->optionValue($option);
                }
            }

            // Textarea sebelumnya diabaikan total — akibatnya tiap textarea ikut
            // TERKIRIM KOSONG saat form disimpan ulang.
            foreach ($xpath->query('.//textarea', $form) as $textarea) {
                if (! $textarea instanceof DOMElement) {
                    continue;
                }

                $name = $textarea->getAttribute('name');

                if ($name !== '' && ! $textarea->hasAttribute('disabled')) {
                    $fields[$name] = $textarea->textContent;
                }
            }

            return [
                'action'          => $form->getAttribute('action'),
                'fields'          => $fields,
                'password_fields' => $passwordFields,
                'unreadable'      => $unreadable,
            ];
        }

        return null;
    }

    /**
     * Nilai sebuah <option>. Atribut value yang tidak ada berbeda dari value
     * kosong: browser mengirim teks opsinya, sedangkan getAttribute() memberi ''.
     */
    protected function optionValue(DOMElement $option): string
    {
        return $option->hasAttribute('value')
            ? $option->getAttribute('value')
            : trim($option->textContent);
    }

    /**
     * Baca form yang memuat kolom kata sandi.
     *
     * @return array{action: string, fields: array<string, string>, password_fields: list<string>, unreadable: list<string>}|null
     */
    protected function passwordForm(string $html): ?array
    {
        return $this->readForm($html, '//form[.//input[@type="password"]]');
    }

    /**
     * Baca form yang memuat sebuah kolom bernama $name.
     *
     * Form dicari lewat NAMA KOLOM, bukan pola URL: URL yang dipakai menulis
     * tidak boleh hasil tebakan.
     *
     * @return array{action: string, fields: array<string, string>, password_fields: list<string>, unreadable: list<string>}|null
     */
    protected function fieldForm(string $html, string $name): ?array
    {
        // Nilainya datang dari config; satu tanda kutip nyasar menghasilkan
        // ekspresi XPath rusak yang gagalnya tidak kelihatan.
        if (preg_match('/^[A-Za-z0-9_\-\[\]]+$/', $name) !== 1) {
            throw new TteScraperException('Nama kolom TTE tidak sah: "' . $name . '". Periksa tte.whatsappField.');
        }

        return $this->readForm($html, '//form[.//*[@name="' . $name . '"]]');
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
