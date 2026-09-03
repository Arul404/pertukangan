# Pertukangan

Aplikasi web modular (CodeIgniter 4 + MySQL) untuk perkakas internal. Shell-nya
menyediakan dashboard dan sidebar pemilih modul; tiap perkakas hidup sebagai
**modul** mandiri di bawah `modules/`.

## Modul

| Modul | URL | Isi |
|---|---|---|
| [Tukang Kirim](#modul-tukang-kirim) | `/tukang-kirim` | Kirim pesan WhatsApp lewat API MaxChat |

## Struktur

```
app/                                 SHELL — dipakai lintas modul
├── Config/AppModules.php            registry: daftar kelas definisi modul
├── Config/Autoload.php              pendaftaran namespace tiap modul
├── Controllers/Dashboard.php        halaman '/' — kartu pemilih modul
├── Controllers/BaseController.php   induk semua controller, termasuk modul
├── Helpers/module_helper.php        module_url(), active_module()
├── Modules/ModuleDefinition.php     kontrak metadata + menu sebuah modul
└── Views/layouts/{main,_sidebar}.php  kerangka halaman & sidebar

modules/TukangKirim/                 MODUL
├── Config/Module.php                slug, nama, ikon, menu sidebar
├── Config/Routes.php                route modul (ditemukan otomatis)
├── Config/{MaxChat,PasswordPolicy}.php
├── Controllers/  Libraries/  Models/  Views/
└── Database/{Migrations,Seeds}/
```

Shell tidak pernah tahu isi sebuah modul — ia hanya membaca metadata dari
`ModuleDefinition`. Sebaliknya modul hanya bergantung pada `BaseController` dan
helper navigasi.

## Menambah modul baru

1. Buat `modules/NamaModul/` mengikuti struktur di atas.
2. Buat `Config/Module.php` yang meng-`extend` `App\Modules\ModuleDefinition`
   (isi `slug()`, `name()`, `icon()`, `description()`, `menu()`).
3. Daftarkan namespace-nya di `app/Config/Autoload.php` (`$psr4`) dan di
   `composer.json` bila perlu untuk analisis statis.
4. Tambahkan kelas definisinya ke `app/Config/AppModules.php`.

Route modul di `modules/NamaModul/Config/Routes.php` ditemukan otomatis oleh
auto-discovery CodeIgniter — tidak perlu disentuh dari `app/Config/Routes.php`.

Di dalam modul, **selalu** pakai `module_url('path')` alih-alih `site_url()`
supaya prefix URL hanya ditulis sekali (di `slug()`). View modul dipanggil
dengan `view(Module::VIEWS . 'folder/berkas')` — tanpa namespace, FileLocator
hanya mencari di `app/Views`.

## Kebutuhan

PHP 8.2+ (ekstensi `intl`, `mbstring`, `curl`, `json`, `mysqlnd`), MySQL 8, Composer.

## Pemasangan

```bash
composer install                 # bila vendor/ belum ada
cp env.example .env
php spark key:generate
```

```sql
CREATE DATABASE pertukangan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php spark migrate --all          # --all wajib: migrasi ada di namespace modul
php spark db:seed "Modules\TukangKirim\Database\Seeds\InitialSeeder"
php spark serve --port 8080      # buka http://localhost:8080
```

## Pengujian

```bash
vendor/bin/phpunit tests/unit
```

---

## Modul: Tukang Kirim

Mengirim pesan WhatsApp lewat API MaxChat, dengan template pesan, placeholder
otomatis, dan **preview wajib** sebelum pesan benar-benar dikirim.

Setara dengan menjalankan:

```bash
curl -X POST 'https://core.maxchat.id/diskominfo/api/messages' \
  -H 'accept: application/json' \
  -H 'Authorization: Bearer <token akun>' \
  -H 'Content-Type: application/json' \
  -d '{"to": "628xxx", "type": "text", "text": "..."}'
```

### Fitur

- **Template pesan** — CRUD penuh. Template pertama sudah tersedia: *Reset Password - Standar*.
- **Placeholder dinamis** — tulis `[apa_saja]` di template; saat mengirim, setiap placeholder
  otomatis menjadi kolom isian.
- **`[password]` otomatis** — nilainya dibangkitkan sistem: 12 karakter (minimal 10), selalu
  memuat huruf kapital, huruf kecil, angka, dan karakter spesial, memakai `random_int()` (CSPRNG).
  Karakter ambigu (`I l 1 O 0`) dibuang agar tidak salah baca.
- **Preview wajib** — pesan final ditampilkan lengkap untuk diverifikasi manual. Yang dikirim
  adalah teks yang tersimpan di session saat preview, jadi tidak bisa berubah setelah diverifikasi.
- **Password tampil sekali** — muncul di panel preview dan panel hasil; di database yang tersimpan
  hanya versi ter-mask (`********`), termasuk di dalam payload/respons API.
- **Multi-akun & failover** — pengiriman dirotasi antar akun MaxChat; bila satu akun gagal,
  dicoba akun berikutnya.
- **Riwayat** — semua pengiriman tercatat beserta status, kode HTTP, dan respons MaxChat.
- **Mode uji coba** — `maxchat.dryRun = true` membuat pesan tidak benar-benar dikirim.

### Konfigurasi (`.env`)

Kredensial tiap akun (base URL + token) tersimpan di tabel `maxchat_accounts`
dan dikelola lewat menu **Akun MaxChat**, bukan di `.env`. Yang tersisa di
`.env` hanya setelan global:

| Kunci | Arti |
|---|---|
| `maxchat.timeout` | Timeout request dalam detik. |
| `maxchat.dryRun` | `true` = pesan tidak dikirim, hanya dicatat sebagai `dryrun`. |
| `maxchat.countryCode` | Kode negara untuk normalisasi nomor (`08xx` → `628xx`). |

Prefix `maxchat.` tetap berlaku meski kelas config-nya kini ber-namespace modul:
CodeIgniter menurunkan prefix env dari nama kelas pendek yang di-lowercase.

Aturan password diatur di
[modules/TukangKirim/Config/PasswordPolicy.php](modules/TukangKirim/Config/PasswordPolicy.php)
(panjang dan kumpulan karakter). Batas minimum 10 karakter dipatok di konstanta `MIN_LENGTH`.

### Alur pemakaian

Semuanya terjadi di satu halaman; panel kanan berganti isi mengikuti tahapan,
halaman tidak pernah berpindah.

1. **Kolom kiri** → pilih template, isi placeholder, isi nomor tujuan.
2. **Lihat Preview** → panel kanan menampilkan teks final buatan server dan password yang dibuat.
3. **Kirim Sekarang** → pesan dikirim, hasil dan password tampil di panel yang sama.

### Berkas penting

```
modules/TukangKirim/Config/MaxChat.php            setelan global API (dibaca dari .env)
modules/TukangKirim/Config/PasswordPolicy.php     aturan panjang & komposisi password
modules/TukangKirim/Libraries/PasswordGenerator   pembuat password acak
modules/TukangKirim/Libraries/PlaceholderParser   pembaca & pengganti placeholder [nama]
modules/TukangKirim/Libraries/MaxChatService      pemanggil endpoint + normalisasi nomor
modules/TukangKirim/Libraries/MaxChatDispatcher   rotasi akun & failover
modules/TukangKirim/Controllers/Send.php          alur form -> preview -> kirim -> hasil
```
