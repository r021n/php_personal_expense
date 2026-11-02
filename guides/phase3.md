# Panduan Fase 3 — Autentikasi & Proteksi Route

**Proyek:** Personal Expense & Receipt Tracker  
**Acuan:** `project.md` — Fase 3  
**Folder kerja:** `D:\coding\web\php_personal_expense`  
**Teknologi:** PHP 8.2+, PDO SQLite, session PHP  
**Pendekatan:** SRP, DRY, KISS

> Fase ini menambahkan login satu pengguna, logout, akun awal, dan perlindungan dashboard. Fase ini **belum** membuat CRUD pengeluaran, upload nota, filter, ringkasan, atau ekspor CSV.

## Hasil akhir yang dituju

- Pengunjung tanpa login yang membuka `index.php` diarahkan ke `login.php`.
- Password disimpan sebagai hash `PASSWORD_DEFAULT`, bukan teks biasa.
- Login berhasil memanggil `session_regenerate_id(true)` dan menyimpan ID pengguna di session.
- Login gagal menampilkan pesan umum `Username atau password salah.`
- Form login dan logout memakai token CSRF dari `src/helpers.php`.
- Logout hanya menerima POST, mengosongkan data session, menghapus cookie session, dan menghancurkan session.
- Akun awal dibuat satu kali dari CLI. Password dibaca dari environment dan tidak disimpan dalam kode.

## Struktur file setelah Fase 3

```text
php_personal_expense\
├── config.php                 (pakai yang sudah ada; jangan diubah)
├── index.php                  (ubah: dashboard yang dilindungi login)
├── login.php                  (baru)
├── logout.php                 (baru)
├── seed_user.php              (baru; hanya CLI)
├── init_db.php                (pakai yang sudah ada)
└── src\
    ├── auth.php               (baru)
    ├── db.php                 (pakai yang sudah ada)
    ├── helpers.php            (pakai yang sudah ada)
    └── schema.php             (pakai yang sudah ada)
```

### Tanggung jawab tiap file (SRP)

| File | Tanggung jawab |
|---|---|
| `src/auth.php` | Login, status login, perlindungan route, dan logout. |
| `login.php` | Menampilkan form login dan memproses POST login. |
| `logout.php` | Memeriksa POST dan CSRF, lalu menjalankan logout. |
| `seed_user.php` | Membuat akun awal melalui terminal, bukan browser. |
| `index.php` | Dashboard sementara yang hanya dapat dibuka setelah login. |
| `src/db.php` | Menyediakan koneksi SQLite yang sudah ada melalui `getPDO()`. |
| `src/helpers.php` | Menyediakan `e()`, `redirect()`, dan helper CSRF yang sudah ada. |

Fungsi autentikasi dan koneksi tidak ditulis ulang pada setiap halaman (DRY). Belum ada framework atau sistem multi-user yang tidak diperlukan (KISS).

---

## 0. Buka terminal di folder proyek

Buka Command Prompt, PowerShell, atau terminal VS Code, lalu jalankan:

```bat
cd D:\coding\web\php_personal_expense
```

**Yang terlihat:** prompt terminal berpindah ke folder proyek. Perintah berikutnya dijalankan dari folder ini.

## 1. Pastikan database Fase 2 siap

Jika belum menjalankan inisialisasi database, jalankan:

```bat
php init_db.php
```

**Output yang diharapkan (lokasi dan versi SQLite bisa berbeda):**

```text
Database initialized: D:\coding\web\php_personal_expense\data\database.sqlite
Tables: expenses, users
Indexes: idx_expenses_expense_date, idx_expenses_user_id
SQLite version: 3.53.4
```

`seed_user.php` mengandalkan tabel `users` dari Fase 2. Menjalankan ulang `init_db.php` aman; script tersebut tidak menghapus akun atau transaksi.

Pastikan extension session dan SQLite tersedia:

```bat
php -m | findstr /i pdo_sqlite
php -m | findstr /i session
```

**Output setiap perintah:** masing-masing menampilkan `pdo_sqlite` dan `session`.

---

## 2. Buat `src/auth.php`

**Lokasi:** `D:\coding\web\php_personal_expense\src\auth.php`  
Buat file baru di dalam `src`, lalu tulis kode berikut:

```php
<?php

declare(strict_types=1);

/**
 * Verify credentials, rotate the session ID, and store the authenticated user.
 */
function login(PDO $pdo, string $username, string $password): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new LogicException('A session must be active before login.');
    }

    if ($username === '' || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();

    if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    if (!session_regenerate_id(true)) {
        return false;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];

    return true;
}

/** Return whether the current session has a valid authenticated user ID. */
function is_logged_in(): bool
{
    return session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION['user_id'])
        && is_int($_SESSION['user_id'])
        && $_SESSION['user_id'] > 0;
}

/** Redirect guests to the login page before rendering a protected route. */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/** Clear session data, expire its browser cookie, and destroy stored data. */
function logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
```

### Cara kerja bagian penting

- `login()` mencari username memakai prepared statement; password tidak pernah ditempel ke SQL.
- `password_verify()` membandingkan password yang dikirim dengan hash pada tabel `users`.
- Pesan kegagalan nanti sama untuk username yang tidak ada maupun password yang salah.
- `session_regenerate_id(true)` dijalankan hanya setelah kredensial benar untuk mengganti ID session sebelum status login disimpan.
- Maksimum 72 byte mencegah pemotongan password pada algoritma bcrypt yang dipakai PHP `PASSWORD_DEFAULT` saat ini. Password awal juga dibatasi minimal 12 byte.
- `require_login()` menggunakan `redirect()` dari helper proyek, sehingga halaman lain bisa memakai perlindungan yang sama.
- Logout menghapus array session, mengirim cookie kedaluwarsa dengan parameter cookie yang sama, lalu memanggil `session_destroy()`.

Periksa sintaks:

```bat
php -l src\auth.php
```

**Output yang diharapkan:** `No syntax errors detected in src\auth.php`.

---

## 3. Buat `seed_user.php` untuk akun awal

**Lokasi:** `D:\coding\web\php_personal_expense\seed_user.php` (root proyek).  
Buat file baru lalu isi:

```php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/db.php';

$username = trim((string) (getenv('APP_SEED_USERNAME') ?: 'admin'));
$password = getenv('APP_SEED_PASSWORD');

if ($username === '') {
    fwrite(STDERR, "Seed gagal: APP_SEED_USERNAME tidak boleh kosong.\n");
    exit(1);
}

if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
    fwrite(STDERR, "Seed gagal: set APP_SEED_PASSWORD dengan password 12-72 byte tanpa karakter NUL.\n");
    exit(1);
}

try {
    $pdo = getPDO();
    $pdo->beginTransaction();

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount > 0) {
        throw new RuntimeException('Tabel users sudah berisi akun; seed tidak mengubah data yang ada.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)'
    );
    $statement->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $pdo->commit();
    fwrite(STDOUT, "Akun awal berhasil dibuat: {$username}" . PHP_EOL);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Seed gagal: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
```

### Jalankan seed dengan password yang tidak masuk riwayat perintah

Contoh berikut untuk **PowerShell**. Jalankan dari folder proyek. PowerShell meminta password secara tersembunyi, lalu mengirimkannya sementara ke proses PHP. Password tidak ditulis sebagai teks pada baris perintah.

```powershell
$env:APP_SEED_USERNAME = "admin"
$secure = Read-Host "Password awal (12-72 karakter)" -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:APP_SEED_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    php seed_user.php
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    Remove-Item Env:APP_SEED_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:APP_SEED_USERNAME -ErrorAction SilentlyContinue
    $secure.Dispose()
}
```

**Yang muncul jika berhasil:**

```text
Akun awal berhasil dibuat: admin
```

Ganti `admin` jika ingin username lain. Masukkan password 12–72 byte; untuk menghindari perbedaan hitungan karakter dan byte, gunakan karakter ASCII. Password tidak disimpan di file PHP; database hanya menerima hasil `password_hash()`.

**Jika environment password belum diisi:** script berhenti dengan kode gagal dan menampilkan:

```text
Seed gagal: set APP_SEED_PASSWORD dengan password 12-72 byte tanpa karakter NUL.
```

**Jika akun sudah ada:** script tidak menimpa atau menghapusnya. Pesan yang tampil:

```text
Seed gagal: Tabel users sudah berisi akun; seed tidak mengubah data yang ada.
```

> Jangan jalankan seed sebagai pengganti fitur ganti password. Seed memang hanya untuk instalasi awal dan hanya menerima tabel `users` yang masih kosong.

Periksa sintaks sebelum menjalankan:

```bat
php -l seed_user.php
```

**Output yang diharapkan:** `No syntax errors detected in seed_user.php`.

---

## 4. Buat `login.php`

**Lokasi:** `D:\coding\web\php_personal_expense\login.php` (root proyek).  
Buat file baru dan isi:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedUsername = $_POST['username'] ?? '';
    $postedPassword = $_POST['password'] ?? '';
    $username = is_string($postedUsername) ? trim($postedUsername) : '';

    if (!verify_csrf(is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : null)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (!is_string($postedUsername) || !is_string($postedPassword) || $username === '' || $postedPassword === '') {
        $error = 'Username atau password salah.';
    } elseif (login(getPDO(), $username, $postedPassword)) {
        redirect('index.php');
    } else {
        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(APP_NAME) ?></title>
</head>

<body>
    <main>
        <h1>Login Personal Expense Tracker</h1>

        <?php if ($error !== null): ?>
            <p role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="login.php">
            <?= csrf_field() ?>

            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Masuk</button>
        </form>
    </main>
</body>

</html>
```

Perhatikan urutan `require_once`: `config.php` memulai session untuk request web; helper CSRF dimuat sebelum form digunakan; `db.php` dan `auth.php` menyediakan koneksi dan login. Semua teks yang dicetak ke HTML melewati `e()`.

Periksa sintaks:

```bat
php -l login.php
```

**Output yang diharapkan:** `No syntax errors detected in login.php`.

---

## 5. Buat `logout.php`

**Lokasi:** `D:\coding\web\php_personal_expense\logout.php` (root proyek).  
Buat file baru lalu isi:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Logout hanya menerima permintaan POST.');
}

$token = $_POST['_csrf_token'] ?? null;
if (!verify_csrf(is_string($token) ? $token : null)) {
    http_response_code(403);
    exit('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
}

logout();
redirect('login.php');
```

Logout tidak dibuat sebagai link GET. Ini mengurangi risiko logout dipicu oleh tautan lintas situs. Tombol logout pada dashboard mengirim form POST dengan token CSRF.

Periksa sintaks:

```bat
php -l logout.php
```

**Output yang diharapkan:** `No syntax errors detected in logout.php`.

---

## 6. Ubah `index.php` menjadi dashboard terproteksi

**Lokasi:** `D:\coding\web\php_personal_expense\index.php`.  
Ganti isi file Fase 1 dengan kode berikut:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/auth.php';

require_login();

$title = 'Dashboard';
$username = (string) $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 720px;
            margin: 4rem auto;
            padding: 0 1rem;
        }

        .ok {
            color: #15803d;
        }
    </style>
</head>

<body>
    <h1><?= e(APP_NAME) ?></h1>
    <p class="ok">Login berhasil. Selamat datang, <?= e($username) ?>.</p>
    <p>Halaman ini adalah dashboard sementara. Fitur pengeluaran akan dibuat pada Fase 4.</p>
    <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit">Logout</button>
    </form>
</body>

</html>
```

`require_login()` harus dijalankan sebelum HTML atau spasi apa pun dikirim ke browser. Jika tidak, PHP tidak dapat mengirim header redirect. Username yang ditampilkan di-escape dengan `e()`.

Periksa sintaks:

```bat
php -l index.php
```

**Output yang diharapkan:** `No syntax errors detected in index.php`.

---

## 7. Jalankan pemeriksaan sintaks PHP

Dari root proyek, jalankan:

```bat
php -l src\auth.php
php -l seed_user.php
php -l login.php
php -l logout.php
php -l index.php
```

**Output setiap perintah:** `No syntax errors detected in <nama-file>`.

Jika muncul `Parse error`, periksa kembali tanda kurung, kutip, titik koma, dan pastikan file yang diedit berada pada lokasi di atas.
Proyek ini memakai Vanilla PHP dan tidak mempunyai langkah build terpisah; `php -l` memeriksa sintaks setiap file.

---

## 8. Jalankan aplikasi dan verifikasi lewat browser

### 8.1 Start development server

Di terminal yang berada di root proyek:

```bat
php -S localhost:8000
```

**Output terminal (tanggal/waktu dapat berbeda):**

```text
PHP 8.5.12 Development Server (http://localhost:8000) started
```

Biarkan terminal ini tetap terbuka. Buka browser ke `http://localhost:8000/`.

### 8.2 Pastikan dashboard melindungi pengunjung

Saat belum login, buka `http://localhost:8000/`.

**Yang terjadi:** browser diarahkan ke `http://localhost:8000/login.php` dan menampilkan judul **Login Personal Expense Tracker**, input username, input password, dan tombol **Masuk**. Status HTTP untuk `/` adalah `302`; halaman login merespons `200`.

### 8.3 Uji login gagal

Isi username/password yang salah lalu klik **Masuk**.

**Yang muncul:** pesan `Username atau password salah.`. Jangan tampilkan pesan berbeda yang mengungkap apakah username tertentu terdaftar.

### 8.4 Uji login benar

Login menggunakan akun yang berhasil dibuat pada langkah 3.

**Yang muncul:** dashboard dengan pesan `Login berhasil. Selamat datang, admin.` (atau username yang Anda seed) dan tombol **Logout**. Session ID diganti saat autentikasi berhasil.

### 8.5 Uji logout

Klik tombol **Logout** pada dashboard.

**Yang terjadi:** browser kembali ke halaman login. Buka `http://localhost:8000/` lagi; browser harus diarahkan kembali ke login. Session dihapus dan cookie kedaluwarsa.

Jika mengetik `http://localhost:8000/logout.php` langsung di browser, permintaan GET harus ditolak dengan status `405` dan pesan `Logout hanya menerima permintaan POST.`.

Setelah selesai, hentikan development server dengan **Ctrl+C** pada terminal server.

---

## 9. Checklist selesai Fase 3

- [ ] `src/auth.php`, `login.php`, `logout.php`, dan `seed_user.php` dibuat di lokasi yang benar.
- [ ] `index.php` menjalankan `require_login()` sebelum mengeluarkan HTML.
- [ ] `php init_db.php` memastikan tabel `users` tersedia.
- [ ] Akun awal berhasil dibuat sekali melalui CLI dengan hash `PASSWORD_DEFAULT`.
- [ ] Menjalankan seed kedua kali tidak menimpa atau menghapus akun yang ada.
- [ ] Password salah menghasilkan pesan error umum.
- [ ] Password benar membawa pengguna ke dashboard.
- [ ] Login berhasil meregenerasi session ID dan menyimpan `user_id` serta `username` di session.
- [ ] Pengunjung tanpa login diarahkan ke halaman login.
- [ ] Logout POST dengan token CSRF menghapus data session dan cookie.
- [ ] Logout GET ditolak.
- [ ] Semua file PHP lolos `php -l`.

---

## Troubleshooting

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| `could not find driver` | `pdo_sqlite` belum aktif pada PHP CLI. | Jalankan `php -m`, aktifkan extension di `php.ini` CLI, lalu buka terminal baru. |
| `no such table: users` | Database belum diinisialisasi. | Jalankan `php init_db.php` dari root proyek. |
| Seed meminta `APP_SEED_PASSWORD` | Environment password belum diset atau kurang dari 12/lebih dari 72 byte. | Jalankan blok PowerShell seed pada langkah 3; gunakan password ASCII 12–72 karakter. |
| Seed menyatakan tabel sudah berisi akun | Ada satu atau lebih akun dalam tabel. | Jangan hapus akun untuk mengulang seed; login dengan akun yang sudah ada. |
| Browser selalu kembali ke login setelah password benar | Akun tidak ada, password berbeda, atau database/path yang dipakai bukan yang di-seed. | Pastikan seed dan server dijalankan dari folder proyek yang sama serta file `data/database.sqlite` yang benar. |
| `headers already sent` | Ada output/spasi sebelum `require_login()` atau sebelum redirect. | Pastikan PHP dimulai dari `<?php` pada baris pertama dan tidak ada HTML sebelum guard. |
| Logout GET menampilkan `405` | Logout sengaja hanya menerima POST. | Gunakan tombol Logout di dashboard, jangan membuka URL logout sebagai tautan. |
| Login menampilkan token tidak valid | Form lama, session cookie terhapus, atau token berubah. | Muat ulang `login.php`, lalu kirim form yang baru. |

---

## Referensi resmi PHP

Dokumentasi resmi yang digunakan untuk implementasi ini (PHP Manual yang aktif pada 2026):

- [`password_hash()`](https://www.php.net/manual/en/function.password-hash.php) — hash password dan `PASSWORD_DEFAULT`.
- [`password_verify()`](https://www.php.net/manual/en/function.password-verify.php) — verifikasi password terhadap hash.
- [`session_regenerate_id()`](https://www.php.net/manual/en/function.session-regenerate-id.php) — mengganti ID session setelah autentikasi.
- [`session_destroy()`](https://www.php.net/manual/en/function.session-destroy.php) — menghancurkan data session; cookie harus dihapus terpisah.
- [Session security settings](https://www.php.net/manual/en/session.security.ini.php) — strict mode, cookie-only, HttpOnly, Secure, dan SameSite.
- [`session_start()`](https://www.php.net/manual/en/function.session-start.php) — session harus dimulai sebelum output header/HTML.
- [PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php) — parameter query tidak digabung sebagai SQL.

**Selesai.** Setelah checklist terpenuhi, aplikasi siap melanjutkan ke **Fase 4 — CRUD pengeluaran inti**.
