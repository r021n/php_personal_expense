# Panduan Fase 1 — Setup & Fondasi Proyek

**Proyek:** Personal Expense & Receipt Tracker
**Acuan:** `project.md` (Fase 1)
**Pendekatan wajib:** SRP (Single Responsibility), DRY (Don't Repeat Yourself), KISS (Keep It Simple, Stupid)
**Environment audit:** PHP 8.5.12 (CLI), Windows/win32, ekstensi `fileinfo`, `pdo_sqlite`, dan `session` aktif.

> Fase 1 **tidak** membuat database, login, atau CRUD. Fase ini hanya menyiapkan fondasi: struktur folder, konfigurasi, helper keamanan dasar, dan memastikan server lokal bisa dijalankan tanpa error.

---

## Ringkasan Hasil Akhir Fase 1

Setelah panduan ini selesai, folder proyek akan berisi:

```text
D:\coding\web\php_personal_expense\
├── config.php
├── index.php
├── project.md
├── src\
│   └── helpers.php
├── views\
├── uploads\
└── data\
```

Yang sudah berfungsi:

| Komponen | Status |
|---|---|
| Timezone aplikasi | `Asia/Jakarta` |
| Path database | `data/database.sqlite` |
| Path upload | `uploads/` |
| Session aman | `HttpOnly`, `SameSite=Lax`, `use_strict_mode` |
| Helper `e()` | Escaping HTML |
| Helper `redirect()` | Redirect + `exit` |
| Helper `flash()` | Flash message sekali pakai |
| CSRF token | Generate + validasi `hash_equals()` |
| Ekstensi `fileinfo` | Terverifikasi aktif |
| Folder `uploads/` & `data/` | Terverifikasi writable |
| Server lokal | `http://localhost:8000` merespons HTTP 200 |

---

## 0. Buka Terminal di Folder Proyek

**Lokasi:** terminal Windows (Command Prompt / PowerShell / terminal VS Code).

```bat
cd D:\coding\web\php_personal_expense
```

**Yang muncul:** prompt kembali tanpa pesan error. Tidak ada output khusus jika berhasil.

> Semua perintah di panduan ini dijalankan dari folder proyek tersebut.

---

## 1. Verifikasi Environment

### 1.1 Cek versi PHP

```bat
php -v
```

**Yang muncul (kurang lebih):**

```text
PHP 8.5.12 (cli) (built: Oct 20 2026 04:19:35) (ZTS Visual C++ 2022 x64)
Copyright (c) The PHP Group
Zend Engine v4.5.12, Copyright (c) Zend Technologies
    with Zend OPcache v8.5.12, Copyright (c), by Zend Technologies
```

**Arti:** PHP 8.5.12 memenuhi syarat minimal PHP 8.2+.

### 1.2 Cek ekstensi wajib

```bat
php -m | findstr fileinfo
```

**Yang muncul:**

```text
fileinfo
```

Jika `fileinfo` **tidak** muncul:

1. Buka `php.ini` (cek lokasinya dengan `php --ini`).
2. Cari baris `;extension=fileinfo`.
3. Hapus tanda `;` di depannya.
4. Simpan, tutup terminal, buka terminal baru, lalu ulangi pengecekan.

> `fileinfo` wajib aktif karena Fase 5 akan memakai `finfo_file()` untuk memvalidasi MIME asli file nota. Jangan lewati langkah ini.

### 1.3 Cek ekstensi pendukung lain (opsional)

```bat
php -m | findstr pdo_sqlite
php -m | findstr session
```

**Yang muncul:**

```text
pdo_sqlite
session
```

---

## 2. Buat Struktur Folder

**Lokasi:** folder proyek `D:\coding\web\php_personal_expense`.

```bat
mkdir src views uploads data
```

**Yang muncul:** tidak ada output. Ini normal (perintah `mkdir` sukses dan senyap).

Verifikasi:

```bat
dir
```

**Yang muncul (kurang lebih):**

```text
 Directory of D:\coding\web\php_personal_expense

10/27/2026  04:24 PM    <DIR>          data
10/27/2026  04:24 PM    <DIR>          src
10/27/2026  04:24 PM    <DIR>          uploads
10/27/2026  04:24 PM    <DIR>          views
10/27/2026  04:24 PM             2,345 project.md
```

**Fungsi tiap folder:**

| Folder | Tanggung jawab |
|---|---|
| `src/` | Logika aplikasi (helpers, db, auth, expenses, upload) |
| `views/` | Partial HTML (header, footer, form, tabel) |
| `uploads/` | Menyimpan file nota yang diunggah user |
| `data/` | Menyimpan database SQLite (`database.sqlite`) |

> Jangan menaruh file PHP berisi logika di dalam `uploads/` atau `data/`. Dua folder itu hanya untuk data runtime.

---

## 3. Buat `config.php`

**Lokasi file:** `D:\coding\web\php_personal_expense\config.php` (root proyek).

**Buat file baru** bernama `config.php`, lalu isi dengan kode berikut:

```php
<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application Configuration
|--------------------------------------------------------------------------
| Central configuration for the Personal Expense Tracker.
| This file must stay free of business logic (SRP).
*/

// 1. Timezone
date_default_timezone_set('Asia/Jakarta');

// 2. Base paths (portable across Windows/Linux)
define('BASE_PATH', __DIR__);
define('DATA_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'data');
define('UPLOAD_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');
define('DB_PATH', DATA_PATH . DIRECTORY_SEPARATOR . 'database.sqlite');

// 3. Application metadata
define('APP_NAME', 'Personal Expense Tracker');

// 4. Session configuration
// Skip session handling in CLI so maintenance scripts stay safe.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();
}
```

### Penjelasan singkat (kenapa begini)

| Bagian | Alasan |
|---|---|
| `declare(strict_types=1)` | Membuat tipe data lebih ketat, mengurangi bug diam-diam. |
| `date_default_timezone_set('Asia/Jakarta')` | Semua tanggal/waktu konsisten. Ganti jika zona waktu Anda berbeda. |
| `BASE_PATH` | Satu sumber kebenaran untuk lokasi proyek. |
| `DIRECTORY_SEPARATOR` | Agar path tetap benar di Windows maupun Linux (portabel). |
| `DB_PATH` | Fase 2 akan memakai path ini untuk SQLite. |
| `UPLOAD_PATH` | Fase 5 akan memakai path ini untuk menyimpan nota. |
| `PHP_SAPI !== 'cli'` | Mencegah `session_start()` berjalan saat script CLI (mis. `init_db.php`) dijalankan. |
| `httponly => true` | Cookie session tidak bisa dibaca JavaScript (mengurangi risiko XSS mencuri session). |
| `samesite => 'Lax'` | Mitigasi CSRF tambahan dari browser. |
| `session.use_strict_mode` | Menolak session ID yang tidak dikenal (mencegah session fixation). |

### Cek sintaks

```bat
php -l config.php
```

**Yang muncul:**

```text
No syntax errors detected in config.php
```

Jika muncul `Parse error`, periksa kembali tanda kurung, titik koma, dan kutip.

---

## 4. Buat `src/helpers.php`

**Lokasi file:** `D:\coding\web\php_personal_expense\src\helpers.php`.

**Buat file** `helpers.php` di dalam folder `src/`, lalu isi:

```php
<?php
declare(strict_types=1);

/**
 * Escape a string for safe HTML output.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect to a URL and terminate the script.
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Set or consume a flash message.
 *
 * flash('success', 'Saved!')  -> stores the message
 * flash('success')            -> returns and clears the message
 */
function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    if (empty($_SESSION['_flash'][$key])) {
        return null;
    }

    $value = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);

    return $value;
}

/**
 * Return the current CSRF token, generating one if needed.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Render a hidden CSRF input field.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Validate a submitted CSRF token with timing-safe comparison.
 */
function verify_csrf(?string $token): bool
{
    if (!is_string($token) || $token === '' || empty($_SESSION['_csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['_csrf_token'], $token);
}
```

### Penjelasan singkat (SRP + DRY + KISS)

| Fungsi | Tanggung jawab tunggal |
|---|---|
| `e()` | Hanya escaping output HTML. Dipakai berulang di semua view (DRY). |
| `redirect()` | Hanya redirect lalu berhenti. Tipe `never` menegaskan script tidak lanjut. |
| `flash()` | Hanya menyimpan/mengambil pesan sekali pakai. |
| `csrf_token()` | Hanya membuat/mengambil token CSRF. |
| `csrf_field()` | Hanya merender input hidden CSRF. Memakai `e()` (DRY). |
| `verify_csrf()` | Hanya memvalidasi token. Memakai `hash_equals()` agar timing-safe. |

**Catatan keamanan:**

- Token CSRF dibuat dari `random_bytes(32)` lalu diubah ke hex 64 karakter.
- `hash_equals()` dipakai agar perbandingan token tidak bocor lewat timing attack.
- `e()` memakai `ENT_QUOTES | ENT_SUBSTITUTE` agar kutip tunggal, kutip ganda, dan karakter tidak valid tetap aman.
- Flash message disimpan di `$_SESSION['_flash']`, bukan di query string.

### Cek sintaks

```bat
php -l src/helpers.php
```

**Yang muncul:**

```text
No syntax errors detected in src/helpers.php
```

---

## 5. Buat `index.php` (Halaman Uji Sementara)

**Lokasi file:** `D:\coding\web\php_personal_expense\index.php` (root proyek).

> Halaman ini **sementara**. Tujuannya membuktikan `config.php` + `helpers.php` termuat tanpa error dan server lokal merespons. Di fase berikutnya, `index.php` akan berevolusi menjadi dashboard.

**Buat file** `index.php`, lalu isi:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/helpers.php';

$title = 'Fase 1 - Setup Berhasil';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 4rem auto; padding: 0 1rem; }
        .ok { color: #15803d; }
        li { margin-bottom: .35rem; }
    </style>
</head>
<body>
    <h1><?= e(APP_NAME) ?></h1>
    <p class="ok">Fase 1 selesai. Konfigurasi dan helper berhasil dimuat.</p>
    <ul>
        <li>Timezone: <?= e(date_default_timezone_get()) ?></li>
        <li>Database: <?= e(DB_PATH) ?></li>
        <li>Upload: <?= e(UPLOAD_PATH) ?></li>
        <li>fileinfo: <?= extension_loaded('fileinfo') ? 'aktif' : 'tidak aktif' ?></li>
    </ul>
    <form method="post" action="#">
        <?= csrf_field() ?>
        <button type="submit">Uji CSRF field</button>
    </form>
</body>
</html>
```

### Penjelasan singkat

- `require_once` mencegah file yang sama dimuat dua kali (aman, DRY).
- Semua output variabel di-escape dengan `e()`.
- `csrf_field()` sengaja **tidak** di-escape karena ia menghasilkan HTML input yang sudah aman; token di dalamnya sudah di-escape oleh `e()`.
- Halaman ini menampilkan timezone, path database, path upload, dan status `fileinfo` sebagai bukti konfigurasi terbaca.

### Cek sintaks

```bat
php -l index.php
```

**Yang muncul:**

```text
No syntax errors detected in index.php
```

---

## 6. Verifikasi Folder `uploads/` dan `data/` Bisa Ditulis

**Lokasi:** folder proyek.

Jalankan perintah berikut satu per satu di Command Prompt:

```bat
echo test > uploads\.write_test
if exist uploads\.write_test (echo uploads WRITABLE) else (echo uploads NOT WRITABLE)
del uploads\.write_test

echo test > data\.write_test
if exist data\.write_test (echo data WRITABLE) else (echo data NOT WRITABLE)
del data\.write_test
```

**Yang muncul:**

```text
uploads WRITABLE
data WRITABLE
```

**Jika muncul `NOT WRITABLE`:**

1. Pastikan Anda menjalankan terminal sebagai user yang punya izin tulis ke folder tersebut.
2. Klik kanan folder → **Properties** → **Security** → pastikan user Anda punya **Write**.
3. Jika proyek berada di drive yang di-mount read-only, pindahkan proyek ke drive lokal.
4. Ulangi perintah di atas.

> Fase 2 akan membuat `data/database.sqlite`, dan Fase 5 akan menulis file ke `uploads/`. Kedua folder **wajib** writable.

---

## 7. Jalankan Server Lokal

**Lokasi:** folder proyek.

```bat
php -S localhost:8000
```

**Yang muncul di terminal:**

```text
[Tue Oct 27 16:24:51 2026] PHP 8.5.12 Development Server (http://localhost:8000) started
```

> Tanggal/jam akan mengikuti waktu Anda. Jika port 8000 sudah dipakai, gunakan port lain, misalnya `php -S localhost:8001`.

### 7.1 Buka di browser

Buka `http://localhost:8000/` di browser.

**Yang muncul di browser:**

- Judul halaman: **Personal Expense Tracker**
- Pesan hijau: **Fase 1 selesai. Konfigurasi dan helper berhasil dimuat.**
- Daftar:
  - `Timezone: Asia/Jakarta`
  - `Database: D:\coding\web\php_personal_expense\data\database.sqlite`
  - `Upload: D:\coding\web\php_personal_expense\uploads`
  - `fileinfo: aktif`
- Tombol **Uji CSRF field**
- Di HTML source, ada input hidden: `<input type="hidden" name="_csrf_token" value="...">`

### 7.2 Yang muncul di terminal saat halaman diakses

```text
[Tue Oct 27 16:25:13 2026] [::1]:56840 Accepted
[Tue Oct 27 16:25:13 2026] [::1]:56840 [200]: GET /
[Tue Oct 27 16:25:13 2026] [::1]:56840 Closing
```

**Arti:** server menerima koneksi, merespons `200 OK`, lalu menutup koneksi. Tidak ada pesan `Fatal error`, `Warning`, atau `Notice`.

### 7.3 Uji via CLI (opsional)

Buka terminal **baru** (jangan tutup server), lalu jalankan:

```bat
curl -s -o NUL -w "HTTP %{http_code}\n" http://localhost:8000/
```

**Yang muncul:**

```text
HTTP 200
```

> Jika `curl` tidak tersedia, cukup gunakan browser.

### 7.4 Hentikan server

Kembali ke terminal server, tekan **Ctrl+C**.

**Yang muncul:** server berhenti dan prompt kembali.

---

## 8. Checklist Fase 1

Centang satu per satu. Jangan lanjut ke Fase 2 jika ada yang belum terpenuhi.

- [ ] Folder `src/`, `views/`, `uploads/`, `data/` sudah dibuat.
- [ ] `config.php` ada di root proyek.
- [ ] `src/helpers.php` ada.
- [ ] `index.php` ada di root proyek.
- [ ] `php -l config.php` → `No syntax errors detected`.
- [ ] `php -l src/helpers.php` → `No syntax errors detected`.
- [ ] `php -l index.php` → `No syntax errors detected`.
- [ ] `php -m | findstr fileinfo` → `fileinfo`.
- [ ] Folder `uploads/` writable.
- [ ] Folder `data/` writable.
- [ ] `php -S localhost:8000` berjalan.
- [ ] `http://localhost:8000/` merespons `200` dan menampilkan halaman Fase 1.
- [ ] Tidak ada `Fatal error`, `Warning`, atau `Notice` di browser maupun terminal.
- [ ] Input CSRF hidden muncul di form halaman uji.

---

## 9. Troubleshooting Umum

| Gejala | Penyebab | Solusi |
|---|---|---|
| `php` tidak dikenali | PHP belum masuk `PATH` | Tambahkan folder PHP ke `PATH`, buka terminal baru. |
| `findstr` error | Kutip tidak cocok | Gunakan `php -m \| findstr fileinfo` tanpa tanda kutip tambahan. |
| `Address already in use` | Port 8000 dipakai proses lain | Jalankan `php -S localhost:8001`. |
| `session_start(): Cannot start session` | Session dimulai setelah ada output | Pastikan `config.php` di-`require` sebelum HTML apa pun. |
| `Permission denied` saat tulis folder | Izin Windows | Atur permission folder seperti langkah 6. |
| Halaman blank | Ada `Fatal error` tersembunyi | Cek terminal server; aktifkan `display_errors=1` di `php.ini` untuk debugging lokal. |
| `fileinfo` tidak aktif | Ekstensi dikomentari di `php.ini` | Uncomment `extension=fileinfo`, restart terminal. |

---

## 10. Catatan Arsitektur untuk Fase Berikutnya

Fondasi ini sengaja dibuat kecil dan sederhana:

1. **`config.php`** hanya berisi konfigurasi. Jangan tambahkan query atau logika bisnis di sini.
2. **`src/helpers.php`** hanya berisi utilitas lintas fitur. Fungsi baru yang spesifik (mis. `createExpense()`) harus masuk file sendiri, misalnya `src/expenses.php`.
3. **`index.php`** adalah entry point. Nanti ia akan memanggil `require_login()` dan menampilkan dashboard.
4. **Semua output user wajib melewati `e()`.** Ini akan diuji lagi di Fase 10.
5. **Semua form yang mengubah data wajib memakai `csrf_field()` dan `verify_csrf()`.** Ini akan dipakai di Fase 3, 4, 5, dan 8.

---

## 11. Referensi Dokumentasi (Stabil, 2026)

- PHP Manual — Built-in Web Server:
  https://www.php.net/manual/en/features.commandline.webserver.php
- PHP Manual — Session Management Basics (termasuk CSRF):
  https://www.php.net/manual/en/features.session.security.management.php
- PHP Manual — `finfo_file()`:
  https://www.php.net/manual/en/function.finfo-file.php
- PHP Manual — `session_set_cookie_params()`:
  https://www.php.net/manual/en/function.session-set-cookie-params.php
- OWASP — Cross-Site Request Forgery Prevention Cheat Sheet:
  https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html

> Catatan: PHP 8.5 menambahkan beberapa API baru di `Pdo\Sqlite` (mis. transaction mode), tetapi Fase 1 tidak memerlukannya. Fase 2 akan memakai PDO standar (`new PDO('sqlite:...')`) yang stabil dan kompatibel dengan PHP 8.2+.

---

**Selesai.** Jika semua checklist tercentang, fondasi proyek siap masuk ke **Fase 2 — Database & Skema**.
