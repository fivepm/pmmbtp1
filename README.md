# Sistem Web Presensi Event Bulanan

Sistem Web Presensi Event Bulanan adalah aplikasi web sederhana yang dirancang untuk membantu pengelolaan kehadiran peserta dalam acara bulanan. Aplikasi ini dilengkapi dengan panel admin untuk manajemen data peserta dan event, serta fitur pemindaian barcode/QR code untuk pencatatan kehadiran yang efisien.

## Fitur Utama

- Login Admin: Akses aman ke panel administrasi.
- Manajemen Peserta (CRUD):
  - Menambah, melihat, mengedit, dan menonaktifkan (soft delete) data peserta.
  - Kolom `kelompok` dan `kategori_usia` dengan dropdown untuk klasifikasi peserta.
  - `barcode_data` dihasilkan otomatis oleh sistem untuk setiap peserta.
  - Fitur impor peserta dari file CSV dengan format template yang spesifik.
  - Tombol untuk melihat dan mengunduh QR code peserta secara individual.
- Manajemen Event (CRUD):
  - Menambah, melihat, mengedit, dan menghapus data event bulanan.
  - Ketika event baru ditambahkan, sistem secara otomatis membuat entri presensi "Tidak Hadir" untuk semua peserta aktif yang ada.
- Scan Presensi:
  - Halaman khusus untuk memindai barcode/QR code menggunakan kamera perangkat.
  - Secara otomatis mengupdate status kehadiran menjadi "Hadir" saat barcode dipindai.
  - Daftar kehadiran real-time untuk event yang sedang berlangsung.
  - Fitur untuk mengubah status kehadiran peserta secara manual (`Hadir`, `Izin`, `Tidak Hadir`).
  - Tombol untuk kembali ke halaman sebelumnya.
  - Fitur fullscreen untuk pengalaman pemindaian yang lebih fokus.
- Laporan Presensi:
  - Menampilkan ringkasan kehadiran (Total Peserta, Hadir, Izin, Tidak Hadir) untuk event yang dipilih.
  - Daftar detail kehadiran peserta dengan status masing-masing.
  - Filter laporan berdasarkan `kelompok`.
  - Opsi untuk mengunduh laporan dalam format CSV.
  - Opsi untuk mencetak laporan ke PDF (menggunakan fitur cetak browser) dengan kop surat resmi.
- Manajemen Admin (Super Admin Only):
  - Halaman khusus yang hanya dapat diakses oleh admin dengan `ID = 1`.
  - Memungkinkan super admin untuk menambah, mengedit, dan menghapus akun admin lain.
- Tampilan Responsif: Desain yang menyesuaikan dengan berbagai ukuran layar (desktop, tablet, mobile).
- URL Bersih (Friendly URLs): Menggunakan `.htaccess` untuk menghilangkan ekstensi `.php` dari URL.
- Favicon: Ikon kecil di tab browser untuk identifikasi website.

## Teknologi yang Digunakan

- Backend: PHP (Native)
- Database: MySQL
- Frontend: HTML, CSS (Tailwind CSS), JavaScript
- Library JavaScript:
  - `jsQR`: Untuk pemindaian QR code.
  - `QRious`: Untuk pembuatan QR code di sisi klien.
- Server Web: Apache (melalui XAMPP)

## Persyaratan Sistem

- Web server (Apache, Nginx) dengan dukungan PHP.
- PHP versi 7.4 atau lebih tinggi (direkomendasikan PHP 8.x).
- Ekstensi PHP `mysqli` (untuk koneksi MySQL).
- Ekstensi PHP `gd` (untuk pemrosesan gambar, diperlukan jika Anda ingin mengaktifkan kembali fitur logo pada QR code atau jika ada fitur lain yang membutuhkannya).
- Database MySQL.
- Modul Apache `mod_rewrite` harus diaktifkan.

## Instalasi dan Setup

Ikuti langkah-langkah di bawah ini untuk menginstal dan menjalankan proyek ini di lingkungan lokal Anda (misalnya dengan XAMPP):

### 1. Clone Repositori

Jika Anda mengelola proyek ini dengan Git, clone repositori ke folder `htdocs` XAMPP Anda (atau root server web Anda):

```
git clone <URL_REPO_ANDA> C:\xampp\htdocs\mumibtp1
```

Jika tidak menggunakan Git, cukup unduh file proyek dan ekstrak ke `C:\xampp\htdocs\mumibtp1`.

### 2. Konfigurasi Database MySQL

1. Buka `phpMyAdmin` (biasanya di `http://localhost/phpmyadmin/`).
2. Buat database baru dengan nama: `mumibtp1`.
3. Jalankan query SQL berikut untuk membuat tabel yang diperlukan:

```
-- Tabel users (untuk admin)
CREATE TABLE users (
id INT AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(255) NOT NULL UNIQUE,
password VARCHAR(255) NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabel participants
CREATE TABLE participants (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(255) NOT NULL,
jenis_kelamin VARCHAR(20) NULL,
barcode_data VARCHAR(255) NOT NULL UNIQUE,
kelompok VARCHAR(50),
kategori_usia VARCHAR(50),
is_active BOOLEAN DEFAULT TRUE,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabel events
CREATE TABLE events (
id INT AUTO_INCREMENT PRIMARY KEY,
event_name VARCHAR(255) NOT NULL,
event_date DATE NOT NULL,
description TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabel attendances
CREATE TABLE attendances (
id INT AUTO_INCREMENT PRIMARY KEY,
participant_id INT NOT NULL,
event_id INT NOT NULL,
attendance_time DATETIME DEFAULT CURRENT_TIMESTAMP,
status VARCHAR(20) DEFAULT 'Tidak Hadir',
FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
UNIQUE (participant_id, event_id)
);
```

4. Tambahkan Admin Awal:Untuk menambahkan akun admin pertama (super admin dengan ID = 1), Anda perlu memasukkan data secara manual ke tabel users. Pastikan Anda menghash password sebelum memasukkannya ke database.
   Contoh cara menghash password di PHP: password_hash('password_anda', PASSWORD_DEFAULT);

### 3. Konfigurasi Apache (`.htaccess`)

Buat file bernama `.htaccess` di direktori root proyek Anda (`C:\xampp\htdocs\mumibtp1\`) dan tambahkan kode berikut:

```
# Aktifkan Rewrite Engine

RewriteEngine On

# --- Aturan untuk MENGHILANGKAN .php dari URL dan melakukan REDIRECT ---
# Jika permintaan adalah untuk file .php yang ada (dan bukan direktori)
# Dan URL di bilah alamat browser masih mengandung .php
# Maka lakukan redirect 301 (Permanent Redirect) ke URL tanpa .php
# Sesuaikan '/mumibtp1/' dengan nama folder proyek Anda di htdocs
# Jika proyek Anda langsung di htdocs (misal: http://localhost/admin_dashboard), ganti /mumibtp1/ dengan /
RewriteCond %{THE_REQUEST} ^[A-Z]{3,9}\ /([^.]+)\.php([?\ ].*)?\ HTTP/
RewriteRule ^([^.]+)\.php$ http://%{HTTP_HOST}/mumibtp1/$1 [R=301,L]

# --- Aturan untuk Menulis Ulang URL secara INTERNAL ---
# Jika permintaan BUKAN direktori yang ada
RewriteCond %{REQUEST_FILENAME} !-d
# DAN ada file .php yang sesuai dengan nama permintaan
RewriteCond %{REQUEST_FILENAME}.php -f
# Maka secara internal, layani file .php tersebut
RewriteRule ^(.*)$ $1.php [L]
```

Penting:

- Pastikan modul Apache `mod_rewrite` diaktifkan di `httpd.conf` Anda.
- Ubah `AllowOverride None` menjadi `AllowOverride All` di blok `<Directory "C:/xampp/htdocs/mumibtp1">` (atau direktori proyek Anda) di `httpd.conf`.
- Setelah mengubah `httpd.conf` atau `.htaccess`, restart Apache melalui XAMPP Control Panel.

### 4. Siapkan Folder `images` dan Favicon

1. Buat folder bernama `images` di direktori root proyek Anda (`C:\xampp\htdocs\mumibtp1\images`).
2. Tempatkan file logo Anda (`logo1.png`, `logo2.png`) dan favicon (`favicon.png` atau `favicon.ico`) di dalam folder ini.
   - `logo1.png` dan `logo2.png` digunakan untuk kop surat PDF.
   - `favicon.png` (atau `.ico`) digunakan sebagai ikon di tab browser.

## Penggunaan

1. Akses sistem melalui browser Anda: `http://localhost/mumibtp1/login` (atau sesuaikan path jika folder proyek Anda berbeda).
2. Login menggunakan kredensial admin yang Anda buat.
   - Username default: `admin`
   - Password: `admin123` (atau password yang Anda setel dan hash)
3. Navigasi melalui sidebar untuk mengelola peserta, event, melakukan scan presensi, atau melihat laporan.

## Kontribusi

Jika Anda ingin berkontribusi pada proyek ini, silakan fork repositori dan kirimkan pull request.

## Lisensi

Proyek ini gratis.
