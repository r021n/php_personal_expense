# Panduan Fase 4 — CRUD Pengeluaran Inti

**Proyek:** Personal Expense & Receipt Tracker  
**Acuan:** `project.md` — Fase 4  
**Folder kerja:** `D:\coding\web\php_personal_expense`  
**Teknologi:** PHP 8.2+ (diuji pada PHP 8.5.12), PDO SQLite, session PHP  
**Pendekatan:** SRP, DRY, KISS

> Fase ini menambahkan pencatatan, daftar, dan penghapusan pengeluaran milik pengguna yang login. Fase ini **belum** menangani upload nota, filter bulan/kategori, ringkasan, atau ekspor CSV.

## Hasil akhir yang dituju

- Pengguna yang sudah login bisa membuka `expenses.php`.
- Form menerima nominal, kategori, tanggal, dan catatan.
- Nominal disimpan sebagai bilangan bulat rupiah dan harus lebih dari `0`.
- Tanggal divalidasi sebagai tanggal Gregorian nyata dengan format `YYYY-MM-DD`.
- Kategori hanya boleh berasal dari daftar yang diizinkan.
- Data disimpan dengan `user_id` dari session, bukan dari input form.
- Daftar transaksi hanya menampilkan data milik pengguna yang login.
- Transaksi dapat dihapus hanya jika memang milik pengguna tersebut.
- Semua query memakai prepared statement.
- Semua output yang berasal dari pengguna di-escape dengan `e()`.
- Form create dan delete memakai token CSRF yang sudah tersedia dari Fase 1/3.

## Struktur file setelah Fase 4

```text
php_personal_expense\
├── config.php                 (pakai yang sudah ada; jangan diubah)
├── index.php                  (ubah: tambah tautan ke expenses.php)
├── expenses.php               (baru: halaman form + daftar + hapus)
├── login.php                  (pakai yang sudah ada)
├── logout.php                 (pakai yang sudah ada)
├── seed_user.php              (pakai yang sudah ada)
├── init_db.php                (pakai yang sudah ada)
└── src\
    ├── auth.php               (pakai yang sudah ada)
    ├── db.php                 (pakai yang sudah ada)
    ├── expenses.php           (baru: validasi + operasi database)
    ├── helpers.php            (pakai yang sudah ada)
    └── schema.php             (pakai yang sudah ada)
```

### Tanggung jawab tiap file (SRP)

| File | Tanggung jawab |
|---|---|
| `src/expenses.php` | Daftar kategori, validasi input, create, list, dan delete pengeluaran. |
| `expenses.php` | Menangani request HTTP, memanggil fungsi domain, dan merender form/daftar. |

Pemisahan ini membuat aturan validasi dan query tidak ditulis ulang di halaman. Halaman hanya mengurus alur HTTP dan tampilan (DRY + SRP). Tidak ada framework, repository pattern, atau lapisan tambahan yang belum diperlukan (KISS).

---

## 0. Buka terminal di folder proyek

Buka Command Prompt, PowerShell, atau terminal VS Code, lalu jalankan:

```bat
cd D:\coding\web\php_personal_expense
```

**Yang terlihat:** prompt terminal berpindah ke folder proyek. Semua perintah berikut dijalankan dari folder ini.

## 1. Pastikan fondasi Fase 1–3 siap

### 1.1 Pastikan database dan tabel tersedia

```bat
php init_db.php
```

**Output yang diharapkan (lokasi dan versi SQLite dapat berbeda):**

```text
Database initialized: D:\coding\web\php_personal_expense\data\database.sqlite
Tables: expenses, users
Indexes: idx_expenses_expense_date, idx_expenses_user_id
SQLite version: 3.53.4
```

Perintah ini aman dijalankan ulang; skema memakai `CREATE TABLE IF NOT EXISTS` dan tidak menghapus data.

### 1.2 Pastikan extension yang dipakai aktif

```bat
php -m | findstr /i pdo_sqlite
php -m | findstr /i session
```

**Output yang diharapkan:**

```text
pdo_sqlite
session
```

Jika salah satu tidak muncul, aktifkan extension tersebut di `php.ini` CLI lalu buka terminal baru.

### 1.3 Pastikan sudah punya akun login

Fase 4 dilindungi `require_login()`, jadi Anda harus bisa login. Jika akun awal belum dibuat, ikuti langkah seed pada `guides/phase3.md`. Jangan membuat akun baru dari halaman pengeluaran.

---

## 2. Buat `src/expenses.php`

**Lokasi:** `D:\coding\web\php_personal_expense\src\expenses.php`  
Buat file baru di dalam folder `src`, lalu tulis kode berikut:

```php
<?php

declare(strict_types=1);

/**
 * Return the only categories accepted by the expense form.
 *
 * @return list<string>
 */
function expenseCategories(): array
{
    return [
        'Makanan',
        'Transportasi',
        'Tagihan',
        'Belanja',
        'Kesehatan',
        'Hiburan',
        'Pendidikan',
        'Lainnya',
    ];
}

/**
 * Validate raw expense form input.
 *
 * @param array<string, mixed> $input
 * @return array{
 *     data: array{amount: int, category: string, expense_date: string, note: ?string},
 *     errors: array<string, string>
 * }
 */
function validateExpenseInput(array $input): array
{
    $amountInput = $input['amount'] ?? '';
    $categoryInput = $input['category'] ?? '';
    $dateInput = $input['expense_date'] ?? '';
    $noteInput = $input['note'] ?? '';

    $data = [
        'amount' => 0,
        'category' => '',
        'expense_date' => '',
        'note' => null,
    ];
    $errors = [];

    $amount = is_string($amountInput)
        ? filter_var($amountInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    if ($amount === false) {
        $errors['amount'] = 'Nominal harus berupa bilangan bulat lebih dari 0.';
    } else {
        $data['amount'] = (int) $amount;
    }

    if (!is_string($categoryInput) || !in_array($categoryInput, expenseCategories(), true)) {
        $errors['category'] = 'Kategori tidak valid.';
    } else {
        $data['category'] = $categoryInput;
    }

    $date = false;
    if (
        is_string($dateInput)
        && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $dateInput) === 1
    ) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
    }

    $dateErrors = $date instanceof DateTimeImmutable
        ? DateTimeImmutable::getLastErrors()
        : false;
    $dateHasErrors = $dateErrors !== false
        && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);

    if (!$date instanceof DateTimeImmutable || $dateHasErrors) {
        $errors['expense_date'] = 'Tanggal tidak valid. Gunakan format YYYY-MM-DD.';
    } else {
        $data['expense_date'] = $date->format('Y-m-d');
    }

    if (!is_string($noteInput)) {
        $errors['note'] = 'Catatan tidak valid.';
    } else {
        $note = trim($noteInput);

        if (strlen($note) > 500) {
            $errors['note'] = 'Catatan maksimal 500 karakter.';
        } else {
            $data['note'] = $note === '' ? null : $note;
        }
    }

    return [
        'data' => $data,
        'errors' => $errors,
    ];
}

/**
 * Insert one expense owned by the given user.
 *
 * @param array{amount: int, category: string, expense_date: string, note: ?string} $expense
 * @return int New expense ID.
 */
function createExpense(PDO $pdo, int $userId, array $expense): int
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user ID is required.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO expenses (user_id, amount, category, expense_date, note)
         VALUES (:user_id, :amount, :category, :expense_date, :note)'
    );
    $statement->execute([
        'user_id' => $userId,
        'amount' => $expense['amount'],
        'category' => $expense['category'],
        'expense_date' => $expense['expense_date'],
        'note' => $expense['note'],
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Return all expenses owned by the given user, newest first.
 *
 * @return list<array<string, mixed>>
 */
function listExpenses(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, amount, category, expense_date, note, created_at
         FROM expenses
         WHERE user_id = :user_id
         ORDER BY expense_date DESC, id DESC'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

/**
 * Delete an expense only when it belongs to the given user.
 */
function deleteExpense(PDO $pdo, int $userId, int $expenseId): bool
{
    if ($userId < 1 || $expenseId < 1) {
        return false;
    }

    $statement = $pdo->prepare(
        'DELETE FROM expenses WHERE id = :id AND user_id = :user_id'
    );
    $statement->execute([
        'id' => $expenseId,
        'user_id' => $userId,
    ]);

    return $statement->rowCount() > 0;
}
```

### Cara kerja bagian penting

- `expenseCategories()` menjadi satu-satunya sumber daftar kategori. Dropdown dan validasi server memakai daftar yang sama, sehingga tidak ada duplikasi aturan (DRY).
- `validateExpenseInput()` mengembalikan dua hal: data yang sudah dinormalisasi dan daftar error. Halaman tidak perlu menebak-nebak hasil validasi.
- `filter_var(..., FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])` memastikan nominal adalah bilangan bulat minimal `1`. Pemeriksaan memakai `=== false` karena `0` adalah nilai valid pada filter, tetapi di sini `0` memang ditolak oleh `min_range`.
- Format tanggal diperiksa dengan regex `YYYY-MM-DD`, lalu `DateTimeImmutable::createFromFormat('!Y-m-d', ...)`. Karakter `!` mereset jam/menit/detik ke nol sehingga tanggal tidak bergantung pada waktu sekarang.
- `DateTimeImmutable::getLastErrors()` akan berisi warning untuk tanggal yang “meluap”, misalnya `2026-02-30`, sehingga tanggal seperti itu ditolak.
- `in_array(..., true)` memakai perbandingan ketat. Kategori di luar daftar tidak akan lolos.
- `createExpense()` menerima `user_id` sebagai parameter terpisah. Nilai ini berasal dari session, bukan dari field form, sehingga pengguna tidak bisa memalsukan pemilik data.
- `listExpenses()` dan `deleteExpense()` selalu memakai `WHERE user_id = :user_id`. Inilah lapisan pertahanan agar data pengguna lain tidak terlihat atau terhapus.
- Semua nilai dikirim melalui placeholder PDO. Tidak ada input pengguna yang ditempel langsung ke SQL.
- `(int) $pdo->lastInsertId()` mengubah nilai string dari PDO SQLite menjadi integer.
- Catatan dibatasi 500 byte agar tidak membebani tabel dan tampilan. String kosong disimpan sebagai `NULL`.

Periksa sintaks:

```bat
php -l src\expenses.php
```

**Output yang diharapkan:**

```text
No syntax errors detected in src\expenses.php
```

---

## 3. Buat `expenses.php`

**Lokasi:** `D:\coding\web\php_personal_expense\expenses.php` (root proyek).  
Buat file baru lalu isi kode berikut:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/expenses.php';

require_login();

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];

$errors = [];
$old = [
    'amount' => '',
    'category' => '',
    'expense_date' => date('Y-m-d'),
    'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['_csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $errors['_form'] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif ($action === 'create') {
        $old = [
            'amount' => is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '',
            'category' => is_string($_POST['category'] ?? null) ? $_POST['category'] : '',
            'expense_date' => is_string($_POST['expense_date'] ?? null) ? $_POST['expense_date'] : '',
            'note' => is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
        ];

        $result = validateExpenseInput($old);

        if ($result['errors'] !== []) {
            $errors = $result['errors'];
        } else {
            createExpense($pdo, $userId, $result['data']);
            flash('success', 'Pengeluaran berhasil disimpan.');
            redirect('expenses.php');
        }
    } elseif ($action === 'delete') {
        $idInput = $_POST['id'] ?? '';
        $expenseId = is_string($idInput)
            ? filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if ($expenseId === false) {
            $errors['_form'] = 'Transaksi yang ingin dihapus tidak valid.';
        } elseif (deleteExpense($pdo, $userId, (int) $expenseId)) {
            flash('success', 'Transaksi berhasil dihapus.');
            redirect('expenses.php');
        } else {
            flash('error', 'Transaksi tidak ditemukan atau bukan milik Anda.');
            redirect('expenses.php');
        }
    } else {
        $errors['_form'] = 'Aksi tidak dikenal.';
    }
}

$expenses = listExpenses($pdo, $userId);
$successMessage = flash('success');
$flashError = flash('error');
$categories = expenseCategories();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengeluaran - <?= e(APP_NAME) ?></title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 960px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th,
        td {
            border-bottom: 1px solid #e5e7eb;
            padding: .55rem;
            text-align: left;
            vertical-align: top;
        }

        .field {
            margin-bottom: .8rem;
        }

        .field label {
            display: block;
            font-weight: 600;
            margin-bottom: .25rem;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            max-width: 28rem;
            padding: .45rem;
            box-sizing: border-box;
        }

        .error {
            color: #b91c1c;
        }

        .success {
            color: #15803d;
        }

        .muted {
            color: #6b7280;
        }
    </style>
</head>

<body>
    <nav>
        <a href="index.php">Dashboard</a>
    </nav>

    <h1>Catat Pengeluaran</h1>

    <?php if (isset($errors['_form'])): ?>
        <p class="error" role="alert"><?= e($errors['_form']) ?></p>
    <?php endif; ?>

    <?php if ($successMessage !== null): ?>
        <p class="success" role="status"><?= e($successMessage) ?></p>
    <?php endif; ?>

    <?php if ($flashError !== null): ?>
        <p class="error" role="alert"><?= e($flashError) ?></p>
    <?php endif; ?>

    <form method="post" action="expenses.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="field">
            <label for="amount">Nominal (Rp)</label>
            <input id="amount" name="amount" type="number" min="1" step="1" value="<?= e($old['amount']) ?>" required>
            <?php if (isset($errors['amount'])): ?>
                <span class="error"><?= e($errors['amount']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="category">Kategori</label>
            <select id="category" name="category" required>
                <option value="">-- Pilih kategori --</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category) ?>" <?= $old['category'] === $category ? 'selected' : '' ?>>
                        <?= e($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['category'])): ?>
                <span class="error"><?= e($errors['category']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="expense_date">Tanggal</label>
            <input id="expense_date" name="expense_date" type="date" value="<?= e($old['expense_date']) ?>" required>
            <?php if (isset($errors['expense_date'])): ?>
                <span class="error"><?= e($errors['expense_date']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="note">Catatan (opsional)</label>
            <textarea id="note" name="note" maxlength="500"><?= e($old['note']) ?></textarea>
            <?php if (isset($errors['note'])): ?>
                <span class="error"><?= e($errors['note']) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit">Simpan Pengeluaran</button>
    </form>

    <h2>Daftar Transaksi</h2>

    <?php if ($expenses === []): ?>
        <p class="muted">Belum ada transaksi. Tambahkan pengeluaran pertama Anda melalui form di atas.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Kategori</th>
                    <th>Nominal</th>
                    <th>Catatan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td><?= e((string) $expense['expense_date']) ?></td>
                        <td><?= e((string) $expense['category']) ?></td>
                        <td>Rp <?= e(number_format((int) $expense['amount'], 0, ',', '.')) ?></td>
                        <td><?= e((string) ($expense['note'] ?? '')) ?></td>
                        <td>
                            <form method="post" action="expenses.php" onsubmit="return confirm('Hapus transaksi ini?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= e((string) $expense['id']) ?>">
                                <button type="submit">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>

</html>
```

### Cara kerja bagian penting

- `require_login()` dipanggil sebelum HTML agar pengunjung tanpa login langsung diarahkan ke `login.php` tanpa error “headers already sent”.
- `$userId = (int) $_SESSION['user_id']` adalah satu-satunya sumber identitas pemilik transaksi.
- `$_SERVER['REQUEST_METHOD'] === 'POST'` memisahkan aksi tulis dari tampilan daftar.
- `action=create` menjalankan validasi. Jika valid, data disimpan lalu halaman di-redirect ke `expenses.php`. Pola redirect-after-POST mencegah data terkirim ulang saat browser di-refresh.
- `action=delete` mengambil `id` dari hidden input, memvalidasinya sebagai integer minimal `1`, lalu memanggil `deleteExpense()` yang juga memeriksa `user_id`.
- `verify_csrf()` melindungi form create dan delete. Tanpa token yang cocok, aksi tidak dijalankan.
- Jika validasi gagal, `$old` dipakai untuk mengisi ulang form supaya pengguna tidak perlu mengetik ulang. Semua nilai `$old` di-escape saat dicetak.
- `flash('success', ...)` menyimpan pesan sekali pakai. Pesan ditampilkan setelah redirect, lalu otomatis hilang pada request berikutnya.
- `listExpenses()` dipanggil setelah blok POST. Untuk request POST yang berhasil, kode sudah di-redirect lebih dulu, jadi baris ini hanya dijalankan pada GET atau saat validasi gagal.
- Nominal ditampilkan dengan `number_format(..., 0, ',', '.')` agar terbaca sebagai rupiah tanpa desimal.
- Form hapus memakai `confirm()` sebagai konfirmasi di sisi browser. Ini kenyamanan UI, bukan pengganti pengecekan kepemilikan di server.
- Semua teks yang berasal dari database atau input pengguna dicetak melalui `e()`.

Periksa sintaks:

```bat
php -l expenses.php
```

**Output yang diharapkan:**

```text
No syntax errors detected in expenses.php
```

---

## 4. Ubah `index.php` — tambahkan tautan ke halaman pengeluaran

**Lokasi:** `D:\coding\web\php_personal_expense\index.php`.  
Cari baris:

```php
    <p class="ok">Login berhasil. Selamat datang, <?= e($username) ?></p>
```

Tambahkan satu baris tautan tepat setelahnya:

```php
    <p><a href="expenses.php">Kelola pengeluaran</a></p>
```

Bagian tersebut menjadi:

```php
    <p class="ok">Login berhasil. Selamat datang, <?= e($username) ?></p>
    <p><a href="expenses.php">Kelola pengeluaran</a></p>
```

Tautan ini hanya tampil setelah login karena seluruh `index.php` dilindungi `require_login()`.

Periksa sintaks:

```bat
php -l index.php
```

**Output yang diharapkan:**

```text
No syntax errors detected in index.php
```

---

## 5. Jalankan pemeriksaan sintaks semua file yang berubah

Dari root proyek:

```bat
php -l src\expenses.php
php -l expenses.php
php -l index.php
```

**Output yang diharapkan untuk setiap perintah:**

```text
No syntax errors detected in <nama-file>
```

Contoh lengkap:

```text
No syntax errors detected in src\expenses.php
No syntax errors detected in expenses.php
No syntax errors detected in index.php
```

Proyek ini memakai Vanilla PHP, sehingga tidak ada langkah build terpisah. Pemeriksaan sintaks dengan `php -l` dan pengujian browser adalah pengganti build pada proyek ini.

---

## 6. Jalankan aplikasi

Dari root proyek:

```bat
php -S localhost:8000
```

**Output terminal (tanggal/waktu dapat berbeda):**

```text
PHP 8.5.12 Development Server (http://localhost:8000) started
```

Biarkan terminal ini tetap terbuka. Buka browser ke `http://localhost:8000/`.

---

## 7. Verifikasi lewat browser

### 7.1 Proteksi route

1. Pastikan Anda belum login.
2. Buka `http://localhost:8000/expenses.php`.

**Yang terjadi:** browser diarahkan ke `http://localhost:8000/login.php`. Halaman pengeluaran tidak bisa dibuka tanpa session login.

### 7.2 Login dan buka halaman pengeluaran

1. Login memakai akun dari Fase 3.
2. Di dashboard, klik tautan **Kelola pengeluaran** atau buka `http://localhost:8000/expenses.php`.

**Yang muncul:**

- Judul **Catat Pengeluaran**.
- Form berisi **Nominal (Rp)**, **Kategori**, **Tanggal**, dan **Catatan (opsional)**.
- Tombol **Simpan Pengeluaran**.
- Bagian **Daftar Transaksi** dengan empty state: `Belum ada transaksi. Tambahkan pengeluaran pertama Anda melalui form di atas.` jika belum ada data.

### 7.3 Simpan pengeluaran valid

Isi contoh:

| Field | Nilai |
|---|---|
| Nominal | `25000` |
| Kategori | `Makanan` |
| Tanggal | tanggal hari ini |
| Catatan | `Makan siang` |

Klik **Simpan Pengeluaran**.

**Yang terjadi:** halaman kembali ke `expenses.php`, muncul pesan hijau `Pengeluaran berhasil disimpan.`, dan baris baru tampil di tabel dengan nominal `Rp 25.000`.

### 7.4 Tambah beberapa data untuk memastikan urutan

Tambahkan transaksi dengan tanggal berbeda. Daftar diurutkan dari `expense_date` terbaru, lalu `id` terbaru.

**Yang muncul:** transaksi dengan tanggal paling baru berada di paling atas.

### 7.5 Uji validasi server

Validasi di browser (`required`, `min`, `type=number`) membantu, tetapi aturan wajib tetap diuji di server. Cara paling mudah: buka **Developer Tools → Network/Elements**, hapus atribut `required`/`min` pada input, lalu kirim nilai tidak valid.

Kasus yang harus ditolak dan pesan yang muncul:

| Input | Pesan |
|---|---|
| Nominal kosong, `0`, negatif, atau desimal | `Nominal harus berupa bilangan bulat lebih dari 0.` |
| Kategori diubah di luar daftar | `Kategori tidak valid.` |
| Tanggal seperti `2026-02-30` atau format selain `YYYY-MM-DD` | `Tanggal tidak valid. Gunakan format YYYY-MM-DD.` |
| Catatan lebih dari 500 byte | `Catatan maksimal 500 karakter.` |

Saat validasi gagal, halaman **tidak** menyimpan data. Nilai yang sudah diketik tetap tampil di form dan pesan error muncul di bawah field terkait.

### 7.6 Hapus transaksi

Klik **Hapus** pada salah satu baris.

**Yang terjadi:** browser menampilkan konfirmasi `Hapus transaksi ini?`. Setelah dikonfirmasi, halaman reload dan muncul pesan hijau `Transaksi berhasil dihapus.`. Baris tersebut hilang dari tabel, sedangkan baris lain tetap ada.

Jika Anda mengubah `id` pada hidden input menjadi ID milik pengguna lain atau ID yang tidak ada, server tidak akan menghapus apa pun. Pesan yang muncul: `Transaksi tidak ditemukan atau bukan milik Anda.`

### 7.7 Uji logout lalu akses ulang

Klik **Logout**, lalu buka `http://localhost:8000/expenses.php`.

**Yang terjadi:** browser kembali diarahkan ke halaman login. Data pengeluaran tidak bisa diakses tanpa login.

Hentikan development server dengan **Ctrl+C** pada terminal server.

---

## 8. Periksa data yang tersimpan lewat CLI (opsional)

Jalankan dari root proyek:

```bat
php -r "require 'config.php'; require 'src/db.php'; $rows = getPDO()->query('SELECT id, user_id, amount, category, expense_date, note FROM expenses ORDER BY id')->fetchAll(); foreach ($rows as $row) { echo implode(' | ', array_map('strval', $row)), PHP_EOL; }"
```

**Output contoh (nilai mengikuti data Anda):**

```text
1 | 1 | 25000 | Makanan | 2026-11-09 | Makan siang
2 | 1 | 15000 | Transportasi | 2026-11-08 | 
```

Yang perlu dipastikan:

- Kolom `user_id` berisi ID pengguna yang sedang login.
- Kolom `amount` berisi angka bulat tanpa titik atau koma.
- Kolom `expense_date` berformat `YYYY-MM-DD`.
- Catatan kosong tampil sebagai `NULL` di database, bukan string kosong.

---

## 9. Checklist selesai Fase 4

- [ ] `src/expenses.php` berisi `expenseCategories()`, `validateExpenseInput()`, `createExpense()`, `listExpenses()`, dan `deleteExpense()`.
- [ ] `expenses.php` dilindungi `require_login()` sebelum HTML.
- [ ] Form create mengirim `action=create` dan token CSRF.
- [ ] Form delete mengirim `action=delete`, `id`, dan token CSRF.
- [ ] Nominal `0`, negatif, kosong, dan desimal ditolak.
- [ ] Kategori di luar daftar ditolak.
- [ ] Tanggal tidak valid seperti `2026-02-30` ditolak.
- [ ] Data baru langsung muncul di daftar.
- [ ] Data tersimpan dengan `user_id` dari session.
- [ ] Daftar hanya menampilkan transaksi milik pengguna yang login.
- [ ] Transaksi bisa dihapus dan baris lain tidak terpengaruh.
- [ ] Pengguna lain tidak bisa menghapus transaksi berdasarkan ID.
- [ ] Semua query memakai prepared statement.
- [ ] Semua output pengguna memakai `e()`.
- [ ] `index.php` memiliki tautan ke `expenses.php`.
- [ ] Semua file lolos `php -l`.
- [ ] Belum ada fitur upload nota di fase ini.

---

## Troubleshooting

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| `could not find driver` | `pdo_sqlite` belum aktif pada PHP CLI. | Jalankan `php -m`, aktifkan extension, lalu buka terminal baru. |
| `no such table: expenses` | Database belum diinisialisasi. | Jalankan `php init_db.php` dari root proyek. |
| Halaman pengeluaran selalu kembali ke login | Belum login atau session hilang. | Login lewat `login.php`, lalu buka lagi `expenses.php`. |
| `headers already sent` | Ada spasi/HTML sebelum `require_login()` atau sebelum `redirect()`. | Pastikan file dimulai dengan `<?php` pada baris pertama dan tidak ada output sebelum guard. |
| `Nominal harus berupa bilangan bulat lebih dari 0.` padahal sudah diisi | Nilai mengandung titik, koma, atau desimal. | Masukkan angka bulat tanpa pemisah ribuan, misalnya `25000`, bukan `25.000` atau `25000.50`. |
| `Tanggal tidak valid` pada input yang terlihat benar | Format bukan `YYYY-MM-DD` atau tanggal tidak ada di kalender. | Gunakan date picker atau ketik `2026-11-09`; jangan memakai `09-11-2026`. |
| `Kategori tidak valid` | Nilai `category` diubah manual di luar daftar. | Pilih kategori dari dropdown. Jika menambah kategori, ubah hanya `expenseCategories()`. |
| Pesan sukses/error tidak muncul | Flash sudah dikonsumsi request sebelumnya atau redirect tidak terjadi. | Ulangi aksi dari form yang baru dimuat. |
| Hapus tidak berefek | Token CSRF kedaluwarsa, ID tidak valid, atau transaksi bukan milik user. | Muat ulang halaman, lalu hapus lagi dari daftar yang baru. |
| Data terlihat oleh pengguna lain | Query tidak memfilter `user_id`. | Pastikan daftar memakai `listExpenses()` dan hapus memakai `deleteExpense()`; jangan menulis query lepas di halaman. |

---

## Referensi resmi PHP

Dokumentasi yang menjadi acuan implementasi ini (PHP Manual aktif pada 2026):

- [PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php) — placeholder parameter dan pencegahan SQL injection.
- [`PDO::prepare()`](https://www.php.net/manual/en/pdo.prepare.php) — menyiapkan statement sebelum eksekusi.
- [`PDO::lastInsertId()`](https://www.php.net/manual/en/pdo.lastinsertid.php) — mengambil ID baris yang baru disisipkan.
- [`filter_var()`](https://www.php.net/manual/en/function.filter-var.php) — validasi integer dan opsi `min_range`.
- [`DateTimeImmutable::createFromFormat()`](https://www.php.net/manual/en/datetimeimmutable.createfromformat.php) — parsing tanggal dengan format ketat; karakter `!` mereset field waktu.
- [`DateTimeImmutable::getLastErrors()`](https://www.php.net/manual/en/datetimeimmutable.getlasterrors.php) — mendeteksi tanggal yang meluap atau gagal diparse.
- [PDO error handling](https://www.php.net/manual/en/pdo.error-handling.php) — mode exception yang sudah aktif di `src/db.php`.

**Selesai.** Setelah checklist terpenuhi, aplikasi siap melanjutkan ke **Fase 5 — Upload nota & validasi file**.
