# Panduan Fase 2 — Database & Skema

**Proyek:** Personal Expense & Receipt Tracker
**Acuan:** `project.md` (Fase 2)
**Pendekatan wajib:** SRP (Single Responsibility), DRY (Don't Repeat Yourself), KISS (Keep It Simple, Stupid)
**Environment terverifikasi:** PHP 8.5.12 (CLI), Windows/win32, ekstensi `pdo_sqlite` dan `sqlite3` aktif, SQLite 3.53.4.

> Fase 2 **hanya** menyiapkan database, koneksi PDO, skema tabel, dan index. Fase ini **tidak** membuat login, CRUD, upload nota, atau dashboard. Halaman `index.php` dari Fase 1 tidak perlu diubah.

---

## Ringkasan Hasil Akhir Fase 2

Setelah panduan ini selesai, proyek akan memiliki tambahan:

```text
D:\coding\web\php_personal_expense\
├── config.php              (tidak diubah)
├── index.php               (tidak diubah)
├── init_db.php             (baru)
├── src\
│   ├── helpers.php         (tidak diubah)
│   ├── db.php              (baru)
│   └── schema.php          (baru)
├── data\
│   └── database.sqlite     (dibuat oleh init_db.php, diabaikan Git)
└── uploads\                (tidak diubah)
```

| Komponen | Status setelah Fase 2 |
|---|---|
| Koneksi PDO SQLite | `getPDO()` di `src/db.php` |
| Error mode PDO | `PDO::ERRMODE_EXCEPTION` |
| Fetch mode default | `PDO::FETCH_ASSOC` |
| Emulasi prepared statement | Dimatikan |
| Foreign key SQLite | Aktif per koneksi |
| Tabel `users` | Ada |
| Tabel `expenses` | Ada |
| Index `idx_expenses_user_id` | Ada |
| Index `idx_expenses_expense_date` | Ada |
| `init_db.php` | Aman dijalankan berulang |
| `data/database.sqlite` | Terbentuk |

### Pembagian tanggung jawab file (SRP)

| File | Tanggung jawab tunggal |
|---|---|
| `config.php` | Konfigurasi path, timezone, dan session. Tidak diubah di Fase 2. |
| `src/db.php` | Membuat/mengembalikan koneksi PDO dan mengatur perilakunya. |
| `src/schema.php` | Mendefinisikan DDL tabel dan index. Tidak membuat koneksi. |
| `init_db.php` | Entry point CLI: ambil koneksi → buat skema → laporkan hasil. |

Pemisahan ini membuat koneksi bisa dipakai ulang oleh fitur Fase 3–8 (DRY), sementara DDL tetap di satu tempat (DRY) dan tiap file tetap fokus (SRP).

---

## 0. Buka Terminal di Folder Proyek

**Lokasi:** terminal Windows (Command Prompt / PowerShell / terminal VS Code).

```bat
cd D:\coding\web\php_personal_expense
```

**Yang muncul:** prompt kembali tanpa pesan error. Tidak ada output khusus jika berhasil.

> Semua perintah di panduan ini dijalankan dari folder proyek tersebut.

---

## 1. Verifikasi Prasyarat Database

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

**Arti:** PHP 8.2+ terpenuhi. Fase 2 memakai API PDO standar yang kompatibel dengan PHP 8.2+.

### 1.2 Cek driver PDO SQLite

```bat
php -m | findstr pdo_sqlite
```

**Yang muncul:**

```text
pdo_sqlite
```

```bat
php -m | findstr sqlite3
```

**Yang muncul:**

```text
sqlite3
```

Jika salah satu **tidak** muncul:

1. Cari lokasi `php.ini` dengan `php --ini`.
2. Buka `php.ini`.
3. Hapus tanda `;` pada baris `;extension=pdo_sqlite` dan `;extension=sqlite3`.
4. Simpan, tutup terminal, buka terminal baru, lalu ulangi pengecekan.

> Tanpa `pdo_sqlite`, `new PDO('sqlite:...')` akan gagal dengan pesan `could not find driver`.

### 1.3 Pastikan folder `data/` bisa ditulis

**Lokasi:** folder proyek.

```bat
echo test > data\.write_test
if exist data\.write_test (echo data WRITABLE) else (echo data NOT WRITABLE)
del data\.write_test
```

**Yang muncul:**

```text
data WRITABLE
```

Jika muncul `data NOT WRITABLE`, perbaiki izin folder `data/` sebelum lanjut. SQLite perlu membuat file database dan file journal di folder itu.

---

## 2. Buat `src/db.php` — Koneksi PDO

**Lokasi file:** `D:\coding\web\php_personal_expense\src\db.php`.
**Buat file baru** bernama `db.php` di dalam folder `src/`, lalu isi dengan kode berikut:

```php
<?php

declare(strict_types=1);

/**
 * Return a shared PDO connection to the SQLite database.
 *
 * The connection is created once per request (lazy singleton), configured
 * with exceptions and associative fetches, and has foreign keys enabled.
 */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // SQLite disables foreign keys per connection by default.
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Wait briefly instead of failing instantly on a locked database.
    $pdo->exec('PRAGMA busy_timeout = 5000');

    return $pdo;
}
```

### Penjelasan singkat (kenapa begini)

| Bagian | Alasan |
|---|---|
| `static $pdo = null` | Satu koneksi dipakai ulang dalam satu request. Tidak membuka koneksi baru di setiap pemanggilan (KISS + efisien). |
| `'sqlite:' . DB_PATH` | Memakai konstanta `DB_PATH` dari `config.php`, sehingga lokasi database hanya didefinisikan sekali (DRY). |
| `ATTR_ERRMODE => ERRMODE_EXCEPTION` | Kesalahan SQL langsung menjadi `PDOException`, tidak gagal diam-diam. Ini diminta brief. |
| `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC` | Hasil query otomatis berupa array asosiatif, konsisten di seluruh aplikasi. |
| `ATTR_EMULATE_PREPARES => false` | Memakai prepared statement asli SQLite, bukan emulasi string. |
| `PRAGMA foreign_keys = ON` | SQLite **mematikan** foreign key secara default. Tanpa baris ini, `ON DELETE CASCADE` tidak berjalan. |
| `PRAGMA busy_timeout = 5000` | Jika database sedang terkunci sebentar, SQLite menunggu hingga 5 detik sebelum melempar error. |

> `getPDO()` bergantung pada konstanta `DB_PATH`. File ini **bukan** entry point; jangan jalankan langsung dengan `php src/db.php`. File ini selalu di-`require` setelah `config.php`.

### Cek sintaks

```bat
php -l src\db.php
```

**Yang muncul:**

```text
No syntax errors detected in src\db.php
```

---

## 3. Buat `src/schema.php` — Definisi Skema

**Lokasi file:** `D:\coding\web\php_personal_expense\src\schema.php`.
**Buat file baru** bernama `schema.php` di dalam folder `src/`, lalu isi:

```php
<?php

declare(strict_types=1);

/**
 * Create the database schema if it does not exist yet.
 *
 * Every statement is idempotent, so running this repeatedly is safe and
 * never deletes existing rows.
 */
function initializeSchema(PDO $pdo): void
{
    $statements = [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY,
            username      TEXT    NOT NULL UNIQUE,
            password_hash TEXT    NOT NULL,
            created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS expenses (
            id           INTEGER PRIMARY KEY,
            user_id      INTEGER NOT NULL,
            amount       INTEGER NOT NULL CHECK (amount > 0),
            category     TEXT    NOT NULL,
            expense_date TEXT    NOT NULL,
            note         TEXT,
            receipt_path TEXT,
            created_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )
        SQL,
        'CREATE INDEX IF NOT EXISTS idx_expenses_user_id ON expenses (user_id)',
        'CREATE INDEX IF NOT EXISTS idx_expenses_expense_date ON expenses (expense_date)',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}
```

### Struktur tabel `users`

| Kolom | Tipe | Aturan | Keterangan |
|---|---|---|---|
| `id` | `INTEGER` | `PRIMARY KEY` | Alias rowid SQLite. Otomatis unik dan naik. |
| `username` | `TEXT` | `NOT NULL UNIQUE` | Tidak boleh kosong dan tidak boleh duplikat. |
| `password_hash` | `TEXT` | `NOT NULL` | Nanti diisi hasil `password_hash()` pada Fase 3. Jangan simpan password asli. |
| `created_at` | `TEXT` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` | Waktu pembuatan akun dalam UTC. |

### Struktur tabel `expenses`

| Kolom | Tipe | Aturan | Keterangan |
|---|---|---|---|
| `id` | `INTEGER` | `PRIMARY KEY` | Alias rowid SQLite. |
| `user_id` | `INTEGER` | `NOT NULL`, FK ke `users.id` | Pemilik transaksi. |
| `amount` | `INTEGER` | `NOT NULL CHECK (amount > 0)` | Nominal Rupiah dalam bilangan bulat. Database menolak 0 atau negatif. |
| `category` | `TEXT` | `NOT NULL` | Kategori teks. Daftar kategori yang diizinkan divalidasi di Fase 4. |
| `expense_date` | `TEXT` | `NOT NULL` | Tanggal format `YYYY-MM-DD`, misalnya `2026-10-29`. |
| `note` | `TEXT` | Boleh `NULL` | Catatan opsional. |
| `receipt_path` | `TEXT` | Boleh `NULL` | Path file nota, diisi pada Fase 5. |
| `created_at` | `TEXT` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` | Waktu record dibuat dalam UTC. |

### Keputusan desain penting

| Keputusan | Alasan |
|---|---|
| `amount` memakai `INTEGER`, bukan `REAL` | Menghindari galat pembulatan floating point pada uang. Rupiah disimpan sebagai bilangan bulat. |
| `expense_date` disimpan `TEXT` `YYYY-MM-DD` | Format ISO mudah diurutkan, difilter dengan `BETWEEN`, dan cocok untuk `GROUP BY` bulan. |
| `created_at` memakai `CURRENT_TIMESTAMP` (UTC) | Waktu UTC adalah acuan yang konsisten. Konversi ke `Asia/Jakarta` dilakukan saat menampilkan data. |
| `ON DELETE CASCADE` | Jika user dihapus, transaksi miliknya ikut terhapus. Tidak ada data yatim (orphan). |
| `username UNIQUE` | Mencegah dua akun dengan username sama. |
| `CHECK (amount > 0)` | Lapisan pengaman terakhir di database, walau validasi form tetap dilakukan di Fase 4. |
| `CREATE ... IF NOT EXISTS` | Menjalankan ulang script tidak error dan tidak menghapus data. |

### Kenapa index ini penting

| Index | Mempercepat query |
|---|---|
| `idx_expenses_user_id` | `WHERE user_id = ?` — memisahkan data milik user yang login. |
| `idx_expenses_expense_date` | `WHERE expense_date BETWEEN ? AND ?` dan filter bulan pada Fase 6–7. |

> Index **tidak** mengubah struktur tabel. Index hanya mempercepat pembacaan. Jangan menambahkan index untuk kolom yang jarang difilter agar tidak membebani operasi tulis.

### Cek sintaks

```bat
php -l src\schema.php
```

**Yang muncul:**

```text
No syntax errors detected in src\schema.php
```

---

## 4. Buat `init_db.php` — Script Inisialisasi Database

**Lokasi file:** `D:\coding\web\php_personal_expense\init_db.php` (root proyek).
**Buat file baru** bernama `init_db.php`, lalu isi:

```php
<?php

declare(strict_types=1);

// Maintenance scripts must not be reachable through the web server.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/schema.php';

try {
    $pdo = getPDO();
    initializeSchema($pdo);

    $tables = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name IN ('users', 'expenses')
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $indexes = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'index'
           AND name IN ('idx_expenses_user_id', 'idx_expenses_expense_date')
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    echo 'Database initialized: ' . DB_PATH . PHP_EOL;
    echo 'Tables: ' . implode(', ', $tables) . PHP_EOL;
    echo 'Indexes: ' . implode(', ', $indexes) . PHP_EOL;
    echo 'SQLite version: ' . $pdo->query('SELECT sqlite_version()')->fetchColumn() . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database initialization failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
```

### Penjelasan singkat

| Bagian | Alasan |
|---|---|
| `PHP_SAPI !== 'cli'` | `init_db.php` hanya boleh dijalankan dari terminal. Jika diakses lewat browser, langsung 404. |
| `require_once config.php` | Memuat `DB_PATH`, timezone, dan konfigurasi. |
| `require_once src/db.php` + `src/schema.php` | Memakai koneksi dan DDL yang sudah terpisah (DRY). |
| `try/catch Throwable` | Menangkap error koneksi/skema, menulis pesan ke `STDERR`, lalu `exit(1)` agar otomatisasi bisa mendeteksi kegagalan. |
| Query `sqlite_master` | Membuktikan tabel dan index benar-benar ada setelah inisialisasi. |
| `PHP_EOL` | Baris baru yang benar di Windows. |

### Cek sintaks

```bat
php -l init_db.php
```

**Yang muncul:**

```text
No syntax errors detected in init_db.php
```

---

## 5. Jalankan `init_db.php` Pertama Kali

**Lokasi:** folder proyek.

```bat
php init_db.php
```

**Yang muncul (kurang lebih):**

```text
Database initialized: D:\coding\web\php_personal_expense\data\database.sqlite
Tables: expenses, users
Indexes: idx_expenses_expense_date, idx_expenses_user_id
SQLite version: 3.53.4
```

**Arti tiap baris:**

- `Database initialized: ...` → koneksi PDO berhasil dan file database dibuat.
- `Tables: expenses, users` → kedua tabel ada. Urutan alfabetis karena `ORDER BY name`.
- `Indexes: ...` → kedua index ada.
- `SQLite version: 3.53.4` → versi SQLite yang dipakai driver. Angka bisa berbeda di mesin lain.

Jika muncul `Database initialization failed: ...`, lihat bagian Troubleshooting.

### 5.1 Pastikan file database terbentuk

```bat
dir data\database.sqlite
```

**Yang muncul (kurang lebih):**

```text
 Volume in drive D is personal
 Volume Serial Number is 888A-B20C

 Directory of D:\coding\web\php_personal_expense\data

29/10/2026  16:15            24.576 database.sqlite
               1 File(s)         24.576 bytes
               0 Dir(s)  52.362.756.096 bytes free
```

Tanggal, jam, dan ukuran file akan berbeda. Yang penting: file `database.sqlite` ada dan ukurannya lebih dari 0 byte.

> `data/database.sqlite` sengaja diabaikan Git (lihat `.gitignore`). File ini adalah data runtime lokal, bukan kode sumber. Jangan commit.

---

## 6. Uji Keamanan Menjalankan Ulang `init_db.php`

Jalankan perintah yang sama sekali lagi:

```bat
php init_db.php
```

**Yang muncul:** output yang sama seperti sebelumnya.

```text
Database initialized: D:\coding\web\php_personal_expense\data\database.sqlite
Tables: expenses, users
Indexes: idx_expenses_expense_date, idx_expenses_user_id
SQLite version: 3.53.4
```

**Arti:** `CREATE TABLE IF NOT EXISTS` dan `CREATE INDEX IF NOT EXISTS` tidak melakukan apa pun jika objek sudah ada. Tidak ada error, tabel tidak dibuat ulang, dan data lama tidak terhapus.

> **Penting:** sifat aman-ulang ini berlaku untuk pembuatan awal. Jika nanti kolom tabel berubah di fase lanjutan, `IF NOT EXISTS` **tidak** otomatis menambah/mengubah kolom. Perubahan skema setelah Fase 2 memerlukan migrasi terpisah. Jangan mengandalkan `init_db.php` sebagai alat migrasi.

---

## 7. Verifikasi Tambahan (Opsional tapi Disarankan)

### 7.1 Pastikan `init_db.php` tidak bisa diakses lewat browser

Buka terminal **baru**, lalu jalankan server dari folder proyek:

```bat
php -S localhost:8000
```

**Yang muncul di terminal:**

```text
[Tue Oct 27 16:24:51 2026] PHP 8.5.12 Development Server (http://localhost:8000) started
```

Di terminal lain, uji akses web ke script maintenance:

```bat
curl -s -o NUL -w "HTTP %{http_code}\n" http://localhost:8000/init_db.php
```

**Yang muncul:**

```text
HTTP 404
```

**Arti:** guard `PHP_SAPI !== 'cli'` bekerja. Script database tidak bisa dipicu dari internet/browser.

> Jika `curl` tidak tersedia, buka `http://localhost:8000/init_db.php` di browser. Browser akan menampilkan halaman 404/blank, bukan output inisialisasi. Setelah selesai, hentikan server dengan **Ctrl+C**.

### 7.2 Pastikan halaman Fase 1 masih normal

Buka `http://localhost:8000/`.

**Yang muncul:** halaman **Personal Expense Tracker** dengan pesan **Fase 1 selesai. Konfigurasi dan helper berhasil dimuat.** Tidak ada `Fatal error`, `Warning`, atau `Notice`.

> Fase 2 tidak mengubah `index.php`, jadi halaman ini memang masih halaman uji Fase 1.

---

## 8. Checklist Fase 2

Centang satu per satu. Jangan lanjut ke Fase 3 jika ada yang belum terpenuhi.

- [ ] `php -m | findstr pdo_sqlite` → `pdo_sqlite`.
- [ ] `php -m | findstr sqlite3` → `sqlite3`.
- [ ] Folder `data/` writable.
- [ ] `src/db.php` ada dan `php -l src\db.php` → `No syntax errors detected`.
- [ ] `src/schema.php` ada dan `php -l src\schema.php` → `No syntax errors detected`.
- [ ] `init_db.php` ada dan `php -l init_db.php` → `No syntax errors detected`.
- [ ] `php init_db.php` berhasil tanpa `Database initialization failed`.
- [ ] File `data/database.sqlite` terbentuk dan ukurannya > 0 byte.
- [ ] Output inisialisasi menampilkan `Tables: expenses, users`.
- [ ] Output inisialisasi menampilkan kedua index: `idx_expenses_user_id` dan `idx_expenses_expense_date`.
- [ ] `php init_db.php` dijalankan dua kali dan tetap berhasil.
- [ ] Tidak ada data lama yang terhapus saat script dijalankan ulang.
- [ ] `getPDO()` memakai `ERRMODE_EXCEPTION`.
- [ ] Foreign key aktif (`PRAGMA foreign_keys = ON`).
- [ ] `init_db.php` mengembalikan `HTTP 404` jika diakses lewat web (opsional).
- [ ] Halaman `http://localhost:8000/` dari Fase 1 masih tampil tanpa error.

---

## 9. Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| `could not find driver` | Ekstensi `pdo_sqlite` tidak aktif | Aktifkan `extension=pdo_sqlite` di `php.ini`, buka terminal baru. |
| `unable to open database file` | Folder `data/` tidak ada/tidak writable, atau `DB_PATH` salah | Pastikan folder `data/` ada dan writable. Cek `DB_PATH` di `config.php`. |
| `database is locked` | File database sedang dipakai proses lain | Tutup SQLite Browser/DB tool yang membuka file itu. `busy_timeout` 5000 ms membantu, tetapi bukan solusi untuk koneksi yang benar-benar macet. |
| `SQLSTATE[HY000]: General error: 1 no such table` | `init_db.php` belum dijalankan atau memakai file database lain | Jalankan `php init_db.php`, lalu cek `DB_PATH`. |
| `Fatal error: Uncaught Error: Undefined constant "DB_PATH"` | `src/db.php` dijalankan langsung tanpa `config.php` | Jangan `php src/db.php`. Jalankan `php init_db.php`, atau `require config.php` lebih dulu. |
| `Parse error` saat `php -l` | Kurung, kutip, atau titik koma salah | Bandingkan kode dengan panduan ini. |
| `Foreign key constraint failed` | `user_id` tidak ada di tabel `users` | Ini perilaku benar. Pastikan user dibuat dulu sebelum insert transaksi. |
| `CHECK constraint failed: amount > 0` | Nominal 0/negatif | Ini perilaku benar. Validasi nominal di Fase 4 harus > 0. |
| `UNIQUE constraint failed: users.username` | Username duplikat | Gunakan username lain. Ini mencegah akun ganda. |
| Akses `init_db.php` di browser menghasilkan 404 | Guard CLI bekerja | Ini memang disengaja. Jalankan lewat terminal. |
| `data/database.sqlite` tidak muncul di `git status` | File diabaikan `.gitignore` | Ini benar. Database adalah data runtime, bukan kode. |
| Menjalankan ulang `init_db.php` tidak menambah kolom baru | `CREATE TABLE IF NOT EXISTS` tidak melakukan migrasi | Buat migrasi terpisah jika skema berubah setelah Fase 2. |
| Waktu `created_at` terlihat berbeda dari jam lokal | SQLite `CURRENT_TIMESTAMP` menyimpan UTC | Konversi ke `Asia/Jakarta` saat menampilkan data di fase UI. |

---

## 10. Catatan Arsitektur untuk Fase 3

1. **Semua halaman baru** harus me-`require` `config.php`, lalu `src/helpers.php`, lalu `src/db.php` bila butuh database. Gunakan `require_once`.
2. **Jangan buat koneksi PDO baru** di halaman/login. Panggil `getPDO()` agar satu koneksi dipakai ulang (DRY).
3. **Tabel `users` masih kosong** setelah Fase 2. Seed akun awal dilakukan di Fase 3 memakai `password_hash()`, bukan menyimpan password asli.
4. **`username` unik** sudah dijamin database. Login tetap perlu menangani kasus username tidak ditemukan dan password salah dengan pesan yang aman.
5. **`amount` adalah INTEGER Rupiah.** Saat form Fase 4 memproses input, konversi ke integer dan tolak nilai <= 0.
6. **`expense_date` wajib `YYYY-MM-DD`.** Validasi tanggal sebenarnya (termasuk tanggal mustahil) dilakukan di Fase 4; database hanya menjaga kolom tidak null.
7. **`receipt_path` disiapkan sekarang, diisi di Fase 5.** Simpan path relatif terhadap folder proyek, jangan path absolut mesin lokal.
8. **Semua query yang melibatkan input user wajib prepared statement.** `getPDO()` sudah mematikan emulasi prepared statement.
9. **Semua output user wajib melewati `e()`.** Aturan ini tetap berlaku dari Fase 1.
10. **Jangan mengubah `config.php` untuk Fase 2.** Semua path sudah tersedia lewat konstanta.

---

## 11. Referensi Dokumentasi (Stabil, 2026)

- PHP Manual — PDO::__construct (opsi koneksi dan error mode):
  https://www.php.net/manual/en/pdo.construct.php
- PHP Manual — PDO Error Handling:
  https://www.php.net/manual/en/pdo.error-handling.php
- PHP Manual — PDO SQLite Driver:
  https://www.php.net/manual/en/ref.pdo-sqlite.php
- PHP Manual — PDOStatement::fetch (mode fetch):
  https://www.php.net/manual/en/pdostatement.fetch.php
- SQLite — CREATE TABLE (termasuk `IF NOT EXISTS`, `CHECK`, foreign key):
  https://www.sqlite.org/lang_createtable.html
- SQLite — CREATE INDEX (termasuk `IF NOT EXISTS`):
  https://www.sqlite.org/lang_createindex.html
- SQLite — Foreign Key Support (foreign key nonaktif secara default):
  https://www.sqlite.org/foreignkeys.html
- SQLite — PRAGMA foreign_keys:
  https://www.sqlite.org/pragma.html#pragma_foreign_keys

> Catatan: SQLite menyimpan `CURRENT_TIMESTAMP` dalam UTC dengan format `YYYY-MM-DD HH:MM:SS`. PHP 8.5 menyediakan kelas `Pdo\Sqlite`, tetapi panduan ini sengaja memakai `PDO` standar (`new PDO('sqlite:...')`) karena stabil, sederhana, dan kompatibel dengan PHP 8.2+ sesuai brief.

---

## 12. Hasil yang Diharapkan Setelah Fase 2

Jika semua langkah dan checklist selesai:

- Database SQLite lokal sudah siap dipakai.
- Skema `users` dan `expenses` sesuai brief.
- Relasi user → transaksi dijaga foreign key.
- Nominal tidak bisa 0/negatif di level database.
- Query filter user dan tanggal sudah punya index.
- `init_db.php` aman dijalankan ulang tanpa merusak data.
- Koneksi PDO siap dipakai ulang oleh Fase 3.

**Selesai.** Fondasi database siap masuk ke **Fase 3 — Autentikasi & proteksi route**.
