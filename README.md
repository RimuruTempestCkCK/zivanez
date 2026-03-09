# Zivanes - Sistem Manajemen Bisnis

Zivanes adalah aplikasi web berbasis PHP untuk manajemen bisnis ritel dengan sistem multi-cabang. Aplikasi ini dirancang untuk mengelola stok, transaksi, karyawan, penggajian, setoran, dan laporan keuangan dengan peran pengguna yang berbeda-beda.

## Fitur Utama

### Peran Pengguna
- **Admin Cabang (AdminC)**: Mengelola transaksi, stok, setoran, dan laporan untuk cabang tertentu
- **Admin Pusat (AdminP)**: Mengelola karyawan, cabang, penggajian, validasi setoran, dan laporan global
- **Bagian Gudang (BG)**: Mengelola produk dan stok di seluruh cabang
- **Karyawan**: Melihat gaji pribadi dan informasi dasar
- **Pemilik**: Melihat laporan keseluruhan bisnis

### Fitur Berdasarkan Modul
- **Dashboard**: Statistik real-time untuk setiap peran
- **Kelola Cabang**: CRUD cabang (AdminP)
- **Kelola Karyawan**: CRUD karyawan dengan assign ke cabang (AdminP)
- **Kelola Produk/Stok**: CRUD produk dan pengelolaan stok (BG)
- **Transaksi**: Pencatatan penjualan dan pengeluaran (AdminC)
- **Setoran**: Pengelolaan setoran harian cabang (AdminC, validasi AdminP)
- **Penggajian**: Perhitungan gaji karyawan dengan potongan absensi (AdminP)
- **Laporan**:
  - Laporan Keuangan (Pemasukan/Pengeluaran)
  - Laporan Penjualan
  - Laporan Stok
  - Laporan Karyawan
  - Laporan Penggajian
  - Laporan Setoran

## Teknologi yang Digunakan

- **Backend**: PHP 7.4+
- **Database**: MySQL/MariaDB
- **Frontend**: HTML5, CSS3, JavaScript (Tailwind CSS, Lucide Icons)
- **Server**: Apache (XAMPP)
- **Authentication**: Session-based

## Struktur Folder

```
zivanes/
├── config.php              # Konfigurasi database
├── dashboard.php           # Dashboard utama
├── index.php               # Halaman login
├── login.php               # Proses login
├── logout.php              # Proses logout
├── proses_login.php        # Handler login
├── AdminC/                 # Admin Cabang
│   ├── dashboard.php
│   ├── kelola_transaksi.php
│   ├── laporan_keuangan.php
│   ├── laporan_penjualan.php
│   ├── laporan_stok.php
│   ├── setoran.php
│   └── stok.php
├── AdminP/                 # Admin Pusat
│   ├── dashboard.php
│   ├── kelola_cabang.php
│   ├── kelola_karyawan.php
│   ├── kelola_user.php
│   ├── laporan_karyawan.php
│   ├── laporan_keuangan.php
│   ├── laporan_penggajian.php
│   ├── laporan_setoran.php
│   ├── laporan_stok.php
│   ├── penggajian.php
│   ├── stok_gudang.php
│   └── validasi_setoran.php
├── BG/                     # Bagian Gudang
│   ├── dashboard.php
│   ├── kelola_produk.php
│   ├── kelola_stok.php
│   └── laporan_stok.php
├── Karyawan/               # Karyawan
│   ├── dashboard.php
│   └── gaji.php
├── Pemilik/                # Pemilik
│   ├── dashboard.php
│   ├── laporan_karyawan.php
│   ├── laporan_keuangan.php
│   ├── laporan_penggajian.php
│   ├── laporan_setoran.php
│   └── laporan_stok.php
├── layout/                 # Komponen UI bersama
│   ├── index.js
│   ├── sidebar.php
│   └── wireframe.css
└── uploads/                # Upload files
    └── setoran/
```

## Persyaratan Sistem

- PHP 7.4 atau lebih tinggi
- MySQL/MariaDB 5.7+
- Apache Server (XAMPP/WAMP)
- Browser modern (Chrome, Firefox, Edge)

## Instalasi dan Setup

### 1. Clone atau Download Proyek
```bash
# Jika menggunakan Git
git clone <repository-url>
cd zivanes
```

### 2. Setup Database
1. Buat database baru di MySQL dengan nama `zivanes`
2. Import file SQL database (jika tersedia) atau buat tabel sesuai kebutuhan aplikasi

### 3. Konfigurasi Database
Edit file `config.php` jika diperlukan:
```php
<?php
$host = "localhost";
$user = "root";      // Ganti dengan username MySQL Anda
$pass = "";          // Ganti dengan password MySQL Anda
$db   = "zivanes";   // Nama database
$conn = mysqli_connect($host, $user, $pass, $db);
?>
```

### 4. Setup Web Server
1. Pastikan XAMPP terinstall dan Apache + MySQL aktif
2. Copy folder `zivanes` ke `C:\xampp\htdocs\`
3. Akses aplikasi melalui: `http://localhost/zivanes`

### 5. User Default
- **Admin Cabang**: Username sesuai data di database
- **Admin Pusat**: Username sesuai data di database
- **BG**: Username sesuai data di database
- **Karyawan**: Username sesuai data di database
- **Pemilik**: Username sesuai data di database

## Cara Menjalankan

1. Jalankan XAMPP Control Panel
2. Start Apache dan MySQL
3. Buka browser dan akses `http://localhost/zivanes`
4. Login dengan kredensial yang sesuai peran

## Database Schema

### Tabel Utama
- `users`: Data pengguna dengan role
- `cabang`: Data cabang
- `stok`: Data produk dan stok per cabang
- `transaksi`: Data transaksi (penjualan/pengeluaran)
- `penggajian`: Data gaji karyawan
- `setoran`: Data setoran cabang

## Pengembangan

### Menambah Fitur Baru
1. Identifikasi peran yang akan menggunakan fitur
2. Buat file PHP di folder peran yang sesuai
3. Implementasikan logika CRUD jika diperlukan
4. Update sidebar navigation jika perlu

### Styling
- Menggunakan Tailwind CSS via CDN
- Ikon dari Lucide Icons
- Responsive design

## Troubleshooting

### Error Koneksi Database
- Pastikan MySQL service aktif
- Periksa kredensial di `config.php`
- Pastikan database `zivanes` ada

### Permission Upload
- Pastikan folder `uploads/setoran/` memiliki permission write
- Di Windows/XAMPP biasanya sudah OK

### Session Error
- Pastikan PHP session dapat write ke folder temp
- Clear browser cache jika perlu

## Kontribusi

1. Fork repository
2. Buat branch fitur baru (`git checkout -b feature/nama-fitur`)
3. Commit perubahan (`git commit -am 'Tambah fitur baru'`)
4. Push ke branch (`git push origin feature/nama-fitur`)
5. Buat Pull Request

## Lisensi

Proyek ini menggunakan lisensi MIT. Lihat file `LICENSE` untuk detail lebih lanjut.

## Kontak

Untuk pertanyaan atau dukungan, silakan hubungi tim pengembang.

---

**Catatan**: Pastikan untuk backup database secara berkala dan jaga keamanan kredensial database.