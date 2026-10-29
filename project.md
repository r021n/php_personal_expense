**Personal Expense & Receipt Tracker** adalah proyek kedua yang sangat pas setelah Anda menguasai dasar *request-response* dan PDO. Proyek ini menaikkan level pemahaman PHP Anda ke empat konsep krusial di dunia nyata: **State Management (`$_SESSION`)**, **Autentikasi & Password Hashing**, **Multipart File Upload (`$_FILES`)**, serta **Agregasi Data SQL & Ekspor CSV**.

Dengan alokasi waktu **6 jam (3 hari × 2 jam)**, Anda akan membangun aplikasi pencatat keuangan pribadi lengkap dengan sistem *login* dan fitur unggah foto struk/nota.

---

## Teknologi yang Dipakai (Tech Stack)

Masih menggunakan ekosistem yang ringan dan cepat seperti proyek pertama, namun dengan tambahan fitur *native* PHP yang lebih mendalam:

* **Bahasa:** PHP 8.2+ (Vanilla PHP).
* **Database:** **SQLite 3 via PDO** (melanjutkan penggunaan PDO, kali ini dengan klausa agregasi seperti `SUM()`, `GROUP BY`, dan filter tanggal).
* **State & Security:** PHP Native Sessions (`session_start()`) dan fungsi kriptografi bawaan (`password_hash()` & `password_verify()`).
* **File Handling:** Superglobal `$_FILES` untuk memvalidasi dan menyimpan gambar nota ke dalam folder lokal (`uploads/`).
* **Frontend:** HTML5 + **Tailwind CSS (via CDN)**.

---

## Fitur Utama & Komponen Sistem

### 1. Fitur yang Harus Ada

* **Single-User Authentication:** Halaman *Login* dan *Logout*. Hanya pengguna yang sudah *login* yang bisa melihat dan menambah data pengeluaran (*route protection* via `$_SESSION`).
* **Catat Pengeluaran + Upload Nota:** Form untuk menginput nominal, kategori (Makanan, Transportasi, Tagihan, dll.), tanggal, catatan, serta **opsional unggah foto struk** (`.jpg` / `.png`, maksimal 2MB).
* **Validasi Keamanan File:** Memeriksa ukuran file dan tipe MIME asli menggunakan `finfo_file()` atau `mime_content_type()` (bukan sekadar mengecek ekstensi nama file) sebelum dipindahkan dengan `move_uploaded_file()`.
* **Ringkasan & Filter Bulanan:** Menampilkan kartu total pengeluaran bulan ini, rincian total per kategori, dan filter transaksi berdasarkan bulan/kategori via `$_GET`.
* **Export ke CSV:** Tombol untuk mengunduh laporan transaksi ke file `.csv` yang bisa dibuka di Excel/Google Sheets menggunakan fungsi `fputcsv()` dan manipulasi HTTP Header (`Content-Type: text/csv`).

---

## Audit Environment & Device

Audit dilakukan pada 27 Oktober 2026 di working directory `D:\coding\web\php_personal_expense` (Windows/win32).

### Hasil Pemeriksaan

| Komponen | Status | Keterangan |
|---|---|---|
| PHP | ✅ | PHP 8.5.12 (CLI), memenuhi syarat PHP 8.2+ |
| PDO + SQLite | ✅ | Driver `sqlite`, modul `pdo_sqlite` dan `sqlite3` aktif |
| Session PHP | ✅ | `session_start()` tersedia |
| Upload file | ✅ | `file_uploads = 1`, `upload_max_filesize = 2M`, `post_max_size = 8M` |
| Ekstensi `fileinfo` | ✅ | `finfo` dan `mime_content_type()` aktif dan siap digunakan |
| Ekstensi GD | ⚠️ | Tidak aktif; opsional, hanya perlu jika ingin resize/thumbnail nota |
| Ekstensi OpenSSL | ⚠️ | Tidak aktif; tidak dipakai oleh proyek ini |
| Composer | ⚠️ | Tidak terpasang; tidak wajib karena proyek memakai Vanilla PHP |
| MySQL | ⚠️ | Tidak terpasang; tidak masalah karena proyek memakai SQLite |
| Node.js / npm | ✅ | Node v24.20.0 dan npm 11.19.0; hanya opsional untuk tooling frontend |
| Git | ✅ | Git 2.55.0 terpasang |

### Kesimpulan Audit

Environment sudah **100% siap** untuk memulai coding. Ekstensi `fileinfo` sudah aktif, sehingga validasi MIME pada Fase 5 bisa langsung diterapkan tanpa perubahan konfigurasi tambahan.

Catatan: audit perangkat keras fisik (CPU/RAM/disk) tidak dapat dilakukan lewat terminal ini. Namun, proyek ini ringan karena hanya memakai Vanilla PHP + SQLite, sehingga kebutuhan hardware sangat rendah.

---

## Rencana Pengerjaan 10 Fase

Pendekatan: **SRP** (satu file/fungsi satu tanggung jawab), **DRY** (helper dan query dipakai ulang), **KISS** (fitur sederhana, tanpa framework). Asumsi: **1 fase = 1 sesi ±30 menit**.

### Fase 1 — Setup & fondasi proyek

**Dikerjakan:**
- Buat struktur folder: `config.php`, `src/`, `views/`, `uploads/`, `data/`.
- Siapkan `config.php`: timezone, path database, path upload, dan pengaturan session.
- Buat `src/helpers.php`: `e()` untuk escaping, `redirect()`, `flash()`, dan CSRF token sederhana.
- Verifikasi ekstensi `fileinfo` sudah aktif karena wajib untuk validasi MIME.
- Uji server lokal dengan `php -S localhost:8000`.

**Wajib ada setelah selesai:**
- Struktur folder rapi dan konsisten.
- `config.php` + `helpers.php` berfungsi.
- Halaman kosong bisa diakses tanpa error.
- Folder `uploads/` dan `data/` bisa ditulis.
- `php -m | findstr fileinfo` menunjukkan `fileinfo` aktif.

### Fase 2 — Database & skema

**Dikerjakan:**
- Buat `src/db.php` dengan fungsi `getPDO()` memakai SQLite dan error mode exception.
- Buat skema tabel:
  - `users`: id, username, password_hash, created_at.
  - `expenses`: id, user_id, amount, category, expense_date, note, receipt_path, created_at.
- Tambahkan index pada `user_id` dan `expense_date`.
- Buat script `init_db.php` untuk membuat database dan tabel.

**Wajib ada setelah selesai:**
- File `data/database.sqlite` terbentuk.
- Tabel `users` dan `expenses` ada.
- Koneksi PDO berhasil diuji.
- `init_db.php` aman dijalankan ulang tanpa merusak data.

### Fase 3 — Autentikasi & proteksi route

**Dikerjakan:**
- Buat `login.php`, `logout.php`, dan `src/auth.php`.
- Implementasi `login()` dengan `password_verify()`.
- Seed satu user awal memakai `password_hash()`.
- Implementasi `require_login()` untuk melindungi halaman.
- Setelah login, panggil `session_regenerate_id(true)`.
- Logout harus menghapus session.

**Wajib ada setelah selesai:**
- Login benar → masuk ke dashboard.
- Login salah → muncul pesan error.
- Halaman terproteksi otomatis redirect ke login.
- Logout berhasil menghapus session.
- Ada satu akun awal yang bisa dipakai.

### Fase 4 — CRUD pengeluaran inti

**Dikerjakan:**
- Buat `src/expenses.php` dengan fungsi:
  - `createExpense()`
  - `listExpenses()`
  - `deleteExpense()`
- Buat form input: nominal, kategori, tanggal, catatan.
- Validasi: nominal > 0, tanggal valid, kategori dari daftar yang diizinkan.
- Gunakan prepared statement.
- Tampilkan daftar transaksi milik user yang login.

**Wajib ada setelah selesai:**
- Pengeluaran bisa disimpan dan muncul di daftar.
- Data tersimpan dengan `user_id` yang benar.
- Validasi dasar berjalan.
- Transaksi bisa dihapus.
- Belum ada fitur upload di fase ini.

### Fase 5 — Upload nota & validasi file

**Dikerjakan:**
- Tambahkan input file opsional di form.
- Buat `src/upload.php` dengan fungsi `handleReceiptUpload()`.
- Validasi ukuran maksimal 2MB.
- Validasi MIME asli dengan `finfo_file()`: hanya `image/jpeg` dan `image/png`.
- Generate nama file acak, lalu pindahkan dengan `move_uploaded_file()`.
- Simpan path ke kolom `receipt_path`.
- Saat transaksi dihapus, hapus juga file notanya.

**Wajib ada setelah selesai:**
- Upload JPG/PNG valid berhasil dan tampil di daftar.
- File >2MB ditolak.
- File dengan MIME palsu ditolak.
- File tersimpan di `uploads/`.
- Hapus transaksi ikut menghapus file nota.

### Fase 6 — Filter transaksi via GET

**Dikerjakan:**
- Tambahkan filter bulan dan kategori memakai `$_GET`.
- Modifikasi `listExpenses()` agar mendukung filter.
- Gunakan `strftime('%Y-%m', expense_date)` untuk filter bulan di SQLite.
- Bangun query dinamis dengan prepared statement.
- Pertahankan nilai filter di form.

**Wajib ada setelah selesai:**
- Filter bulan berfungsi.
- Filter kategori berfungsi.
- Kombinasi bulan + kategori berfungsi.
- Tidak ada SQL injection.
- Ada tampilan empty state saat data kosong.

### Fase 7 — Ringkasan bulanan & per kategori

**Dikerjakan:**
- Buat fungsi agregasi:
  - `getMonthlyTotal()`
  - `getCategoryTotals()`
- Gunakan `SUM()` dan `GROUP BY`.
- Tampilkan kartu total pengeluaran bulan terpilih.
- Tampilkan rincian total per kategori.
- Hubungkan ringkasan dengan filter bulan.

**Wajib ada setelah selesai:**
- Total bulanan tampil dan nilainya benar.
- Total per kategori tampil dan benar.
- Ganti bulan → ringkasan ikut berubah.
- Data tetap terfilter per user.

### Fase 8 — Export CSV

**Dikerjakan:**
- Buat `export.php` yang dilindungi `require_login()`.
- Ambil data memakai filter yang sama dengan daftar transaksi.
- Set header:
  - `Content-Type: text/csv`
  - `Content-Disposition: attachment`
- Tulis data dengan `fputcsv()` ke `php://output`.
- Nama file: `expenses-YYYY-MM.csv`.

**Wajib ada setelah selesai:**
- Tombol download CSV tersedia.
- File CSV bisa dibuka di Excel/Google Sheets.
- Data yang diexport sesuai filter aktif.
- Hanya user login yang bisa export.

### Fase 9 — UI polish & partials

**Dikerjakan:**
- Pisahkan `views/header.php` dan `views/footer.php`.
- Pakai Tailwind CDN.
- Rapikan navigasi, form, kartu ringkasan, dan tabel transaksi.
- Terapkan `e()` pada semua output.
- Tambahkan flash message dan tampilan error yang konsisten.
- Buat layout responsif sederhana.

**Wajib ada setelah selesai:**
- Semua halaman memakai header/footer yang sama.
- Tampilan konsisten dan responsif.
- Flash message muncul setelah aksi.
- Tidak ada output user yang tidak di-escape.

### Fase 10 — Testing, keamanan & dokumentasi

**Dikerjakan:**
- Uji manual semua fitur: auth, CRUD, upload, filter, ringkasan, CSV.
- Uji kasus negatif: login salah, file oversize, MIME palsu, akses tanpa login.
- Cek XSS dan SQL injection dasar.
- Perbaiki bug yang ditemukan.
- Tulis `README.md`: cara setup, akun awal, dan cara menjalankan.

**Wajib ada setelah selesai:**
- Semua fitur utama lulus uji manual.
- Tidak ada bug kritis.
- `README.md` lengkap.
- Proyek bisa dijalankan ulang dari nol sesuai dokumentasi.