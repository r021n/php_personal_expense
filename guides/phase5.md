# Panduan Fase 5 — Upload Nota & Validasi File

**Proyek:** Personal Expense & Receipt Tracker  
**Acuan:** `project.md` — Fase 5  
**Folder kerja:** `D:\coding\web\php_personal_expense`  
**Teknologi:** PHP 8.2+ (diuji pada PHP 8.5.12), PDO SQLite, `$_FILES`, `fileinfo`  
**Pendekatan:** SRP, DRY, KISS

> Fase ini menambahkan unggah foto struk (opsional) beserta validasi ukuran dan MIME asli, serta penghapusan file nota saat transaksi dihapus. Fase ini **belum** menangani filter bulan/kategori, ringkasan, atau ekspor CSV.

## Hasil akhir yang dituju

- Form pencatatan punya input file opsional untuk foto nota.
- Form memakai `enctype="multipart/form-data"`.
- Nota wajib JPG atau PNG, maksimal 2 MB.
- Tipe file dicek dari **isi file** memakai `finfo_file()` / `finfo::file()`, bukan dari ekstensi dan bukan dari `$_FILES['type']`.
- Nama file dihasilkan acak (32 karakter hex + ekstensi hasil deteksi), bukan nama asli dari pengguna.
- File dipindahkan hanya dengan `move_uploaded_file()`.
- Kolom `receipt_path` menyimpan nama file di dalam `uploads/`.
- Daftar transaksi menampilkan tautan **Lihat nota** bila ada foto.
- Menghapus transaksi ikut menghapus file notanya.
- Upload bersifat opsional: tanpa file pun transaksi tetap tersimpan.

## Struktur file setelah Fase 5

```text
php_personal_expense\
├── config.php                 (pakai yang sudah ada; jangan diubah)
├── index.php                  (pakai yang sudah ada)
├── expenses.php               (ubah: form upload + hapus file nota)
├── login.php                  (pakai yang sudah ada)
├── logout.php                 (pakai yang sudah ada)
├── uploads\                   (folder tujuan file nota)
├── data\                      (pakai yang sudah ada)
└── src\
    ├── auth.php               (pakai yang sudah ada)
    ├── db.php                 (pakai yang sudah ada)
    ├── expenses.php           (ubah: simpan & baca receipt_path)
    ├── helpers.php            (pakai yang sudah ada)
    ├── schema.php             (pakai yang sudah ada; kolom receipt_path sudah ada)
    └── upload.php             (baru: validasi & simpan nota)
```

### Tanggung jawab tiap file (SRP)

| File | Tanggung jawab |
|---|---|
| `src/upload.php` | Aturan ukuran, MIME, nama file acak, simpan, dan hapus file nota. |
| `src/expenses.php` | Operasi database pengeluaran, termasuk kolom `receipt_path`. |
| `expenses.php` | Alur HTTP: validasi form → upload → simpan; dan hapus baris → hapus file. |

Halaman tidak pernah menyentuh `$_FILES` selain meneruskannya ke `handleReceiptUpload()`. Modul upload tidak pernah menyentuh database. Aturan file hanya ditulis di satu tempat (DRY).

---

## 0. Buka terminal di folder proyek

```bat
cd D:\coding\web\php_personal_expense
```

**Yang terlihat:** prompt terminal berpindah ke folder proyek.

## 1. Pastikan fondasi Fase 1–4 siap

### 1.1 Pastikan `fileinfo` aktif

```bat
php -m | findstr fileinfo
```

**Output yang diharapkan:**

```text
fileinfo
```

### 1.2 Pastikan database dan kolom `receipt_path` siap

```bat
php init_db.php
```

**Output yang diharapkan:**

```text
Database initialized: D:\coding\web\php_personal_expense\data\database.sqlite
Tables: expenses, users
Indexes: idx_expenses_expense_date, idx_expenses_user_id
SQLite version: 3.53.4
```

Kolom `receipt_path` sudah dibuat oleh `src/schema.php` sejak Fase 2, jadi **tidak perlu migrasi**.

---

## 2. Buat `src/upload.php`

**Lokasi:** `D:\coding\web\php_personal_expense\src\upload.php`  
Buat file baru di folder `src`, lalu tulis kode berikut:

```php
<?php

declare(strict_types=1);

/**
 * Maximum receipt size in bytes (2 MB).
 */
function receiptMaxBytes(): int
{
    return 2 * 1024 * 1024;
}

/**
 * Allowed receipt MIME types mapped to their file extension.
 *
 * @return array<string, string>
 */
function receiptAllowedTypes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
}

/**
 * Build the public URL of a stored receipt file.
 */
function receiptUrl(string $filename): string
{
    return 'uploads/' . rawurlencode($filename);
}

/**
 * Validate one $_FILES entry without touching the destination folder.
 *
 * @param array<string, mixed> $file
 * @return array{ok: bool, error: ?string, tmp_name: ?string, filename: ?string}
 */
function validateReceiptUpload(array $file): array
{
    $reject = static fn (string $message): array => [
        'ok' => false,
        'error' => $message,
        'tmp_name' => null,
        'filename' => null,
    ];
    $absent = [
        'ok' => true,
        'error' => null,
        'tmp_name' => null,
        'filename' => null,
    ];

    if (!isset($file['error'])) {
        return $absent;
    }

    if (is_array($file['error'])) {
        return $reject('Permintaan upload tidak valid.');
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return $absent;
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return $reject('Ukuran nota melebihi 2 MB.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $reject('Nota gagal diunggah. Coba lagi.');
    }

    $tmpName = $file['tmp_name'] ?? null;

    if (!is_string($tmpName) || $tmpName === '') {
        return $reject('Nota gagal diunggah. Coba lagi.');
    }

    $size = is_file($tmpName) ? filesize($tmpName) : false;

    if ($size === false) {
        return $reject('Nota gagal diunggah. Coba lagi.');
    }

    if ($size < 1) {
        return $reject('Nota tidak boleh kosong.');
    }

    if ($size > receiptMaxBytes()) {
        return $reject('Ukuran nota melebihi 2 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);

    if (!is_string($mime) || !isset(receiptAllowedTypes()[$mime])) {
        return $reject('Nota harus berupa file JPG atau PNG.');
    }

    return [
        'ok' => true,
        'error' => null,
        'tmp_name' => $tmpName,
        'filename' => bin2hex(random_bytes(16)) . '.' . receiptAllowedTypes()[$mime],
    ];
}

/**
 * Validate and store one uploaded receipt.
 *
 * @param array<string, mixed> $file One entry of $_FILES.
 * @return array{path: ?string, error: ?string} Stored file name, or the rejection message.
 */
function handleReceiptUpload(array $file): array
{
    $check = validateReceiptUpload($file);

    if ($check['error'] !== null) {
        return ['path' => null, 'error' => $check['error']];
    }

    if ($check['tmp_name'] === null || $check['filename'] === null) {
        return ['path' => null, 'error' => null];
    }

    if (!is_dir(UPLOAD_PATH) && !mkdir(UPLOAD_PATH, 0775, true) && !is_dir(UPLOAD_PATH)) {
        return ['path' => null, 'error' => 'Folder uploads tidak dapat ditulis.'];
    }

    if (!is_writable(UPLOAD_PATH)) {
        return ['path' => null, 'error' => 'Folder uploads tidak dapat ditulis.'];
    }

    $destination = UPLOAD_PATH . DIRECTORY_SEPARATOR . $check['filename'];

    if (!move_uploaded_file($check['tmp_name'], $destination)) {
        return ['path' => null, 'error' => 'Nota gagal disimpan. Coba lagi.'];
    }

    return ['path' => $check['filename'], 'error' => null];
}

/**
 * Delete a stored receipt by file name only.
 */
function deleteReceiptFile(string $filename): bool
{
    if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '..')) {
        return false;
    }

    $path = UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename;

    if (!is_file($path)) {
        return false;
    }

    return @unlink($path);
}
```

### Cara kerja bagian penting

- `validateReceiptUpload()` hanya memeriksa, tidak menulis file. Pemisahan ini membuat aturan validasi bisa diuji tanpa request upload sungguhan (SRP).
- `$_FILES['error']` dicek lebih dulu. `UPLOAD_ERR_NO_FILE` berarti pengguna tidak memilih file — karena upload opsional, ini **bukan** error.
- `is_array($file['error'])` menangkis serangan di mana penyerang mengirim struktur `$_FILES` berbentuk array.
- `UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` dipetakan ke pesan 2 MB, bukan pesan generik.
- Ukuran dibaca ulang dari disk dengan `filesize()`, tidak hanya percaya `$_FILES['size']`.
- MIME dibaca dari **isi file** dengan `new finfo(FILEINFO_MIME_TYPE)->file()`. `$_FILES['type']` tidak pernah dipakai karena dikirim browser dan bisa dipalsukan.
- `finfo_close()` tidak dipakai karena sudah **DEPRECATED sejak PHP 8.5.0**; objek `finfo` dibebaskan otomatis.
- Nama file = `bin2hex(random_bytes(16))` + ekstensi hasil deteksi MIME. Nama asli dari pengguna dibuang sepenuhnya, sehingga tidak ada risiko path traversal atau file berekstensi `.php`.
- `move_uploaded_file()` adalah satu-satunya jalan file masuk ke `uploads/`. Fungsi ini sekaligus memastikan file memang benar-benar diunggah lewat POST.
- `deleteReceiptFile()` hanya menerima nama file polos. `basename()` dan penolakan `..` memastikan fungsi ini tidak bisa dipakai menghapus file di luar `uploads/`.
- `receiptUrl()` memakai `rawurlencode()` sehingga tautan aman meski nama file kelak berubah.

Periksa sintaks:

```bat
php -l src\upload.php
```

**Output yang diharapkan:**

```text
No syntax errors detected in src\upload.php
```

---

## 3. Ubah `src/expenses.php`

**Lokasi:** `D:\coding\web\php_personal_expense\src\expenses.php`

### 3.1 Simpan `receipt_path` saat insert

CARI kode:

```php
function createExpense(PDO $pdo, int $userId, array $expense): int
```

GANTI dengan:

```php
function createExpense(PDO $pdo, int $userId, array $expense, ?string $receiptPath = null): int
```

CARI kode:

```php
    $statement = $pdo->prepare(
        'INSERT INTO expenses (user_id, amount, category, expense_date, note) VALUES (:user_id, :amount, :category, :expense_date, :note)'
    );
    $statement->execute([
        'user_id' => $userId,
        'amount' => $expense['amount'],
        'category' => $expense['category'],
        'expense_date' => $expense['expense_date'],
        'note' => $expense['note'],
    ]);
```

GANTI dengan:

```php
    $statement = $pdo->prepare(
        'INSERT INTO expenses (user_id, amount, category, expense_date, note, receipt_path)
         VALUES (:user_id, :amount, :category, :expense_date, :note, :receipt_path)'
    );
    $statement->execute([
        'user_id' => $userId,
        'amount' => $expense['amount'],
        'category' => $expense['category'],
        'expense_date' => $expense['expense_date'],
        'note' => $expense['note'],
        'receipt_path' => $receiptPath,
    ]);
```

### 3.2 Ambil `receipt_path` di daftar

CARI kode:

```php
        'SELECT id, amount, category, expense_date, note, created_at
```

GANTI dengan:

```php
        'SELECT id, amount, category, expense_date, note, receipt_path, created_at
```

### 3.3 Tambahkan pembaca receipt untuk alur hapus

LETAKKAN fungsi berikut DI BAWAH fungsi `listExpenses()`:

```php
function getExpenseReceiptPath(PDO $pdo, int $userId, int $expenseId): ?string
{
    if ($userId < 1 || $expenseId < 1) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT receipt_path FROM expenses WHERE id = :id AND user_id = :user_id'
    );
    $statement->execute(['id' => $expenseId, 'user_id' => $userId]);
    $path = $statement->fetchColumn();

    return is_string($path) && $path !== '' ? $path : null;
}
```

### Cara kerja bagian penting

- Parameter `$receiptPath` diberi default `null`, sehingga pemanggilan lama tanpa foto tetap valid (KISS).
- `getExpenseReceiptPath()` tetap memfilter `user_id`. Pengguna lain tidak bisa membaca path nota milik orang lain.
- Path dibaca **sebelum** baris dihapus, karena setelah `DELETE` datanya sudah tidak ada.
- `deleteExpense()` tidak diubah. Ia tetap fungsi murni database; penghapusan file ditangani halaman (SRP).

Periksa sintaks:

```bat
php -l src\expenses.php
```

**Output yang diharapkan:**

```text
No syntax errors detected in src\expenses.php
```

---

## 4. Ubah `expenses.php`

**Lokasi:** `D:\coding\web\php_personal_expense\expenses.php` (root proyek)

### 4.1 Muat modul upload

CARI kode:

```php
require_once __DIR__ . "/src/expenses.php";
```

LETAKKAN baris berikut DI BAWAHNYA:

```php
require_once __DIR__ . "/src/upload.php";
```

### 4.2 Perbaiki kecil: nilai `note` salah sumber

CARI kode:

```php
            'note' => is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '',
```

GANTI dengan:

```php
            'note' => is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
```

> Perbaikan typo Fase 4: sebelumnya form diisi ulang dengan nilai `amount`, bukan `note`.

### 4.3 Jalankan upload pada aksi create

CARI kode:

```php
        $result = validateExpenseInput($old);

        if ($result['errors'] != []) {
            $errors = $result['errors'];
        } else {
            createExpense($pdo, $userId, $result['data']);
            flash('success', 'Pengeluaran berhasil disimpan');
            redirect('expenses.php');
        }
```

GANTI dengan:

```php
        $result = validateExpenseInput($old);
        $errors = $result['errors'];
        $upload = ['path' => null, 'error' => null];

        if ($errors === []) {
            $upload = handleReceiptUpload($_FILES['receipt'] ?? []);

            if ($upload['error'] !== null) {
                $errors['receipt'] = $upload['error'];
            }
        }

        if ($errors === []) {
            createExpense($pdo, $userId, $result['data'], $upload['path']);
            flash('success', 'Pengeluaran berhasil disimpan');
            redirect('expenses.php');
        }
```

### 4.4 Hapus file nota saat transaksi dihapus

CARI kode:

```php
        if ($expenseId === false) {
            $errors['_form'] = 'Transaksi yang ingin dihapus tidak valid.';
        } elseif (deleteExpense($pdo, $userId, (int) $expenseId)) {
            flash('success', 'Transaksi berhasil dihapus.');
            redirect('expenses.php');
        } else {
            flash('error', 'Transaksi tidak ditemukan atau bukan milik Anda.');
            redirect('expenses.php');
        }
```

GANTI dengan:

```php
        if ($expenseId === false) {
            $errors['_form'] = 'Transaksi yang ingin dihapus tidak valid.';
        } else {
            $receiptPath = getExpenseReceiptPath($pdo, $userId, (int) $expenseId);

            if (deleteExpense($pdo, $userId, (int) $expenseId)) {
                if ($receiptPath !== null) {
                    deleteReceiptFile($receiptPath);
                }
                flash('success', 'Transaksi berhasil dihapus.');
                redirect('expenses.php');
            } else {
                flash('error', 'Transaksi tidak ditemukan atau bukan milik Anda.');
                redirect('expenses.php');
            }
        }
```

### 4.5 Aktifkan multipart pada form

CARI kode:

```html
    <form method="post" action="expenses.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
```

GANTI dengan:

```html
    <form method="post" action="expenses.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= e((string) receiptMaxBytes()) ?>">
```

> `enctype` wajib; tanpa ini `$_FILES` tidak pernah terisi. `MAX_FILE_SIZE` hanya kenyamanan untuk browser, aturan tetap di server.

### 4.6 Tambahkan input file

CARI blok field `note` (diawali `<label for="note">` dan diakhiri `<?php endif; ?>` sebelum `<button type="submit">`).

LETAKKAN blok berikut DI BAWAH blok field `note`:

```html
        <div class="field">
            <label for="receipt">Foto Nota (opsional, JPG/PNG maks 2MB)</label>
            <input type="file" name="receipt" id="receipt" accept="image/jpeg,image/png">
            <?php if (isset($errors['receipt'])): ?>
                <span class="error"><?= e($errors['receipt']) ?></span>
            <?php endif; ?>
        </div>
```

### 4.7 Tambahkan kolom Nota di tabel

CARI kode:

```html
                    <th>Catatan</th>
                    <th>Aksi</th>
```

GANTI dengan:

```html
                    <th>Catatan</th>
                    <th>Nota</th>
                    <th>Aksi</th>
```

CARI blok `foreach` pada `<tbody>` (diawali `<?php foreach ($expenses as $expense): ?>` sampai `<?php endforeach; ?>`).

GANTI dengan:

```html
                <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td><?= e((string) $expense['expense_date']) ?></td>
                        <td><?= e((string) $expense['category']) ?></td>
                        <td>Rp <?= e(number_format((int) $expense['amount'], 0, ',', '.')) ?></td>
                        <td><?= e((string) ($expense['note'] ?? '')) ?></td>
                        <td>
                            <?php $receiptPath = $expense['receipt_path'] ?? null; ?>
                            <?php if (is_string($receiptPath) && $receiptPath !== ''): ?>
                                <a href="<?= e(receiptUrl($receiptPath)) ?>" target="_blank" rel="noopener">Lihat nota</a>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form action="expenses.php" method="post" onsubmit="return confirm('Hapus transaksi ini?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= e((string) $expense['id']) ?>">
                                <button type="submit">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
```

> Blok ini sekaligus memperbaiki baris `<tr>` yang hilang pada Fase 4, sehingga tabel valid.

### Cara kerja bagian penting

- Upload hanya dijalankan setelah data pengeluaran lolos validasi. Jika data tidak valid, file tidak dipindahkan, jadi tidak ada file yatim di `uploads/`.
- Jika upload ditolak, `$errors['receipt']` diisi dan `createExpense()` tidak dipanggil. Tidak ada baris database dan tidak ada file tersimpan.
- `$_FILES['receipt'] ?? []` membuat kode tetap aman bila field tidak terkirim sama sekali.
- Pada aksi hapus, path dibaca dulu, baris dihapus, baru filenya dihapus. Urutan ini penting: bila penghapusan database gagal, file nota masih utuh.
- `deleteReceiptFile()` dipanggil hanya jika `receipt_path` tidak `NULL`.

Periksa sintaks:

```bat
php -l expenses.php
```

**Output yang diharapkan:**

```text
No syntax errors detected in expenses.php
```

---

## 5. Jalankan pemeriksaan sintaks semua file yang berubah

```bat
php -l src\upload.php
php -l src\expenses.php
php -l expenses.php
```

**Output yang diharapkan:**

```text
No syntax errors detected in src\upload.php
No syntax errors detected in src\expenses.php
No syntax errors detected in expenses.php
```

Proyek ini Vanilla PHP, jadi tidak ada langkah build terpisah. `php -l` plus pengujian browser adalah pengganti build.

---

## 6. Jalankan aplikasi

```bat
php -S localhost:8000
```

**Output terminal:**

```text
PHP 8.5.12 Development Server (http://localhost:8000) started
```

Biarkan terminal terbuka, lalu buka `http://localhost:8000/` dan login memakai akun Fase 3.

---

## 7. Verifikasi lewat browser

Buka `http://localhost:8000/expenses.php` setelah login.

### 7.1 Form siap menerima file

**Yang muncul:**

- Field **Foto Nota (opsional, JPG/PNG maks 2MB)** dengan tombol *Choose File*.
- Kolom **Nota** pada tabel, berisi `-` untuk transaksi tanpa foto.

### 7.2 Upload JPG/PNG valid

Isi form, pilih foto struk `.jpg` atau `.png` berukuran di bawah 2 MB, lalu klik **Simpan Pengeluaran**.

**Yang terjadi:**

- Pesan hijau `Pengeluaran berhasil disimpan`.
- Baris baru muncul dengan tautan **Lihat nota**.
- Klik tautan → foto terbuka di tab baru.

### 7.3 Upload file lebih dari 2 MB

Pilih gambar berukuran > 2 MB, lalu simpan.

**Yang muncul:** pesan merah `Ukuran nota melebihi 2 MB.`  
**Yang tidak terjadi:** tidak ada baris baru, tidak ada file baru di `uploads/`.

### 7.4 Upload MIME palsu

Buat file teks biasa lalu beri nama `fake.jpg`:

```bat
notepad uploads\..\fake.jpg
```

Isi teks apa saja, simpan, tutup. Pilih file itu lewat input **Foto Nota**, lalu simpan.

**Yang muncul:** pesan merah `Nota harus berupa file JPG atau PNG.`  
**Yang tidak terjadi:** tidak ada baris baru, tidak ada file masuk `uploads/`.

> Ekstensi `.jpg` tidak menolak apa pun karena yang dicek adalah isi file.

### 7.5 Upload tanpa file (opsional)

Isi form tanpa memilih foto, lalu simpan.

**Yang terjadi:** transaksi tersimpan normal, kolom **Nota** menampilkan `-`.

### 7.6 Hapus transaksi beserta notanya

Klik **Hapus** pada transaksi yang punya foto, lalu konfirmasi.

**Yang terjadi:**

- Pesan hijau `Transaksi berhasil dihapus.`
- Baris hilang dari tabel.
- File notanya ikut terhapus dari `uploads/` (lihat langkah 8.2).

### 7.7 Akses tanpa login

Logout, lalu buka `http://localhost:8000/expenses.php`.

**Yang terjadi:** diarahkan ke `login.php`.

Hentikan server dengan **Ctrl+C**.

---

## 8. Verifikasi lewat CLI

Jalankan dari root proyek setelah meng-upload minimal satu nota.

### 8.1 Cek `receipt_path` di database

```bat
php -r "require 'config.php'; require 'src/db.php'; foreach (getPDO()->query('SELECT id, receipt_path FROM expenses ORDER BY id') as $row) { echo $row['id'], ' | ', $row['receipt_path'] ?? 'NULL', PHP_EOL; }"
```

**Output contoh:**

```text
1 | 9b53ef690acebdb603f4a58dcc170610.png
2 | NULL
```

Yang perlu dipastikan: transaksi dengan foto berisi 32 karakter hex + `.jpg`/`.png`; transaksi tanpa foto berisi `NULL`, bukan string kosong.

### 8.2 Cek isi folder `uploads/`

```bat
php -r "print_r(array_values(array_diff(scandir('uploads'), ['.', '..'])));"
```

**Output contoh:**

```text
Array
(
    [0] => 9b53ef690acebdb603f4a58dcc170610.png
)
```

Yang perlu dipastikan: hanya berisi nama acak berekstensi `.jpg`/`.png`, tidak ada `.php`, tidak ada nama asli file Anda, dan jumlahnya sama dengan jumlah transaksi berfoto.

---

## 9. Checklist selesai Fase 5

- [ ] `src/upload.php` berisi `receiptMaxBytes()`, `receiptAllowedTypes()`, `receiptUrl()`, `validateReceiptUpload()`, `handleReceiptUpload()`, dan `deleteReceiptFile()`.
- [ ] Form memakai `enctype="multipart/form-data"`.
- [ ] Input file `name="receipt"` tersedia dan bersifat opsional.
- [ ] Upload JPG valid berhasil dan tampil sebagai tautan **Lihat nota**.
- [ ] Upload PNG valid berhasil dan tampil di daftar.
- [ ] File lebih dari 2 MB ditolak dengan pesan `Ukuran nota melebihi 2 MB.`
- [ ] File dengan isi bukan gambar (MIME palsu) ditolak dengan pesan `Nota harus berupa file JPG atau PNG.`
- [ ] Nama file tersimpan acak, bukan nama asli dari pengguna.
- [ ] File benar-benar tersimpan di `uploads/`.
- [ ] `receipt_path` terisi di database dan `NULL` bila tanpa foto.
- [ ] Menghapus transaksi ikut menghapus file notanya.
- [ ] Transaksi tanpa foto tetap bisa disimpan dan dihapus.
- [ ] `$_FILES['type']` tidak dipakai di mana pun.
- [ ] Semua output pengguna memakai `e()`.
- [ ] Semua file lolos `php -l`.

---

## Troubleshooting

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| `$_FILES` selalu kosong | Form tidak punya `enctype="multipart/form-data"`. | Tambahkan atribut `enctype` pada tag `<form>` create. |
| Pesan `Nota gagal diunggah. Coba lagi.` | `upload_max_filesize` di `php.ini` lebih kecil dari 2 MB, atau file terkirim separuh jalan. | Cek `php -i \| findstr upload_max_filesize`; pastikan minimal `2M`. |
| Pesan `Ukuran nota melebihi 2 MB.` untuk file kecil | `MAX_FILE_SIZE` hidden field terlalu kecil atau `post_max_size` terlampaui. | Pastikan `post_max_size` ≥ `upload_max_filesize` ≥ 2M. |
| Pesan `Nota harus berupa file JPG atau PNG.` pada file yang benar | File rusak, screenshot aneh, atau ekstensi `.jpeg` dengan konten bukan JPEG. | Buka file di penampil gambar; coba ulangi dengan PNG. |
| Pesan `Folder uploads tidak dapat ditulis.` | Folder `uploads/` tidak ada atau tidak bisa ditulis. | Pastikan folder `uploads/` ada di root proyek dan writable. |
| Tautan **Lihat nota** membuka 404 | File sudah terhapus atau nama tidak cocok. | Cocokkan isi `uploads/` dengan kolom `receipt_path` lewat langkah 8. |
| Foto tidak ikut terhapus saat transaksi dihapus | `receipt_path` `NULL` atau path tidak terbaca. | Pastikan `getExpenseReceiptPath()` dipanggil **sebelum** `deleteExpense()`. |
| `Call to undefined function handleReceiptUpload()` | `src/upload.php` belum dibuat atau belum di-`require`. | Buat file di langkah 2 dan cek langkah 4.1. |
| `Undefined array key "receipt_path"` | `listExpenses()` belum menambahkan kolom tersebut. | Cek langkah 3.2. |
| Upload ditolak padahal file valid | `fileinfo` tidak aktif sehingga `finfo` tidak dikenal. | Jalankan `php -m \| findstr fileinfo`, aktifkan lalu buka terminal baru. |

---

## Referensi resmi PHP

Dokumentasi yang menjadi acuan implementasi ini (PHP Manual aktif pada 2026):

- [Handling file uploads](https://www.php.net/manual/en/features.file-upload.php) — gambaran umum unggahan.
- [POST method uploads](https://www.php.net/manual/en/features.file-upload.post-method.php) — struktur `$_FILES`, atribut `enctype`, dan peringatan agar tidak mempercayai `$_FILES['type']`.
- [Error Messages Explained](https://www.php.net/manual/en/features.file-upload.errors.php) — kode `UPLOAD_ERR_*`.
- [`move_uploaded_file()`](https://www.php.net/manual/en/function.move-uploaded-file.php) — memindahkan hanya file yang benar-benar diunggah via POST.
- [`is_uploaded_file()`](https://www.php.net/manual/en/function.is-uploaded-file.php) — pemeriksaan asal file.
- [`finfo_file()`](https://www.php.net/manual/en/function.finfo-file.php) — membaca MIME dari isi file.
- [`finfo_close()`](https://www.php.net/manual/en/function.finfo-close.php) — **DEPRECATED sejak PHP 8.5.0**, objek `finfo` dibebaskan otomatis.
- [`random_bytes()`](https://www.php.net/manual/en/function.random-bytes.php) — nama file acak yang kriptografis aman.
- [`basename()`](https://www.php.net/manual/en/function.basename.php) — mencegah path traversal.
- [`rawurlencode()`](https://www.php.net/manual/en/function.rawurlencode.php) — aman untuk URL tautan nota.

**Selesai.** Setelah checklist terpenuhi, aplikasi siap melanjutkan ke **Fase 6 — Filter transaksi via GET**.
