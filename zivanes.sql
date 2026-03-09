-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 09 Mar 2026 pada 18.56
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `zivanes`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi`
--

CREATE TABLE `absensi` (
  `id` int(11) NOT NULL,
  `cabang_id` int(11) NOT NULL COMMENT 'FK ke cabang.id',
  `karyawan_id` int(11) NOT NULL COMMENT 'FK ke users.id (role Karyawan)',
  `tanggal` date NOT NULL COMMENT 'Tanggal absensi',
  `status` enum('Hadir','Izin','Sakit','Alpha') NOT NULL DEFAULT 'Hadir' COMMENT 'Status kehadiran',
  `keterangan` text DEFAULT NULL COMMENT 'Catatan tambahan (misal: nama dokter, alasan izin)',
  `dicatat_oleh` int(11) DEFAULT NULL COMMENT 'FK ke users.id (AdminC yang mencatat)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Data absensi karyawan per cabang, dicatat oleh AdminC';

-- --------------------------------------------------------

--
-- Struktur dari tabel `cabang`
--

CREATE TABLE `cabang` (
  `id` int(11) NOT NULL,
  `kode_cabang` varchar(20) NOT NULL COMMENT 'Kode unik cabang, misal: CBG-001',
  `nama_cabang` varchar(150) NOT NULL COMMENT 'Nama lengkap cabang',
  `alamat` text DEFAULT NULL COMMENT 'Alamat lengkap cabang',
  `telepon` varchar(20) DEFAULT NULL COMMENT 'Nomor telepon cabang',
  `admin_id` int(11) DEFAULT NULL COMMENT 'FK ke users.id dengan role AdminC',
  `status` enum('Aktif','Nonaktif') NOT NULL DEFAULT 'Aktif' COMMENT 'Status operasional cabang',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Data cabang perusahaan Sanjai Zivanes';

--
-- Dumping data untuk tabel `cabang`
--

INSERT INTO `cabang` (`id`, `kode_cabang`, `nama_cabang`, `alamat`, `telepon`, `admin_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'CBG-001', 'Cabang 1', 'Jl. Merdeka No. 1, Bandung', '022-1234567', 9, 'Aktif', '2026-02-17 14:06:24', '2026-02-17 14:08:58'),
(2, 'CBG-002', 'Cabang 2', 'Jl. Sudirman No. 88, Jakarta Pusat', '021-9876543', 10, 'Aktif', '2026-02-17 14:06:24', '2026-02-17 14:09:03'),
(3, 'CBG-003', 'Cabang 3', 'Jl. Pemuda No. 45, Surabaya', '031-5556789', 11, 'Aktif', '2026-02-17 14:06:24', '2026-02-17 14:09:08'),
(4, 'CBG004', 'Cabang 4', 'sadfsdf', '081234567890', 2, 'Aktif', '2026-02-19 18:06:45', '2026-02-19 18:06:45');

-- --------------------------------------------------------

--
-- Struktur dari tabel `penggajian`
--

CREATE TABLE `penggajian` (
  `id` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `nik` varchar(16) NOT NULL,
  `jabatan` varchar(100) NOT NULL,
  `jml_anggota` int(11) NOT NULL DEFAULT 1,
  `gaji_pokok` bigint(20) NOT NULL DEFAULT 0,
  `tunjangan` bigint(20) NOT NULL DEFAULT 0,
  `lembur` bigint(20) NOT NULL DEFAULT 0,
  `potongan` bigint(20) NOT NULL DEFAULT 0,
  `hari_tidak_hadir` int(11) NOT NULL DEFAULT 0,
  `total_gaji` bigint(20) NOT NULL DEFAULT 0,
  `bulan` enum('Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember') NOT NULL,
  `tahun` year(4) NOT NULL DEFAULT year(curdate()),
  `status` enum('Sudah Dibayar','Menunggu','Belum Dibayar') NOT NULL DEFAULT 'Belum Dibayar',
  `alamat` text DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `penggajian`
--

INSERT INTO `penggajian` (`id`, `nama`, `nik`, `jabatan`, `jml_anggota`, `gaji_pokok`, `tunjangan`, `lembur`, `potongan`, `hari_tidak_hadir`, `total_gaji`, `bulan`, `tahun`, `status`, `alamat`, `foto`, `created_at`, `updated_at`) VALUES
(6, 'Siti Rahayu', '567463456456', 'Karyawan', 1, 1500000, 0, 0, 50000, 1, 1450000, 'Februari', '2026', 'Menunggu', '', NULL, '2026-02-26 14:15:27', '2026-02-26 14:15:27'),
(7, 'ade', '2113001', 'Karyawan', 1, 1500000, 0, 50000, 0, 0, 1550000, 'Maret', '2026', 'Belum Dibayar', 'asdasda', NULL, '2026-03-08 15:36:01', '2026-03-08 15:36:01');

-- --------------------------------------------------------

--
-- Struktur dari tabel `setoran`
--

CREATE TABLE `setoran` (
  `id` int(11) NOT NULL,
  `no_setoran` varchar(50) NOT NULL COMMENT 'Nomor unik setoran, misal: SET-20260217-0001',
  `cabang_id` int(11) NOT NULL COMMENT 'FK ke cabang.id',
  `admin_id` int(11) NOT NULL COMMENT 'FK ke users.id (AdminC yang menyetor)',
  `tanggal` date NOT NULL COMMENT 'Tanggal setoran harian',
  `jumlah_setoran` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Nominal uang yang disetor (Rp)',
  `total_transaksi` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Total omset hari itu (referensi)',
  `keterangan` text DEFAULT NULL COMMENT 'Catatan tambahan',
  `bukti_foto` varchar(255) DEFAULT NULL COMMENT 'Path file foto bukti setoran',
  `status` enum('Menunggu','Diterima','Ditolak') NOT NULL DEFAULT 'Menunggu' COMMENT 'Status verifikasi setoran',
  `catatan_penolakan` text DEFAULT NULL COMMENT 'Diisi Admin Pusat jika ditolak',
  `verified_by` int(11) DEFAULT NULL COMMENT 'FK ke users.id (Admin Pusat yang verifikasi)',
  `verified_at` timestamp NULL DEFAULT NULL COMMENT 'Waktu verifikasi',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Setoran harian AdminC beserta bukti foto';

--
-- Dumping data untuk tabel `setoran`
--

INSERT INTO `setoran` (`id`, `no_setoran`, `cabang_id`, `admin_id`, `tanggal`, `jumlah_setoran`, `total_transaksi`, `keterangan`, `bukti_foto`, `status`, `catatan_penolakan`, `verified_by`, `verified_at`, `created_at`, `updated_at`) VALUES
(8, 'SET-20260226-0001', 1, 9, '2026-02-27', 103000, 103000, 'Testing', 'SET-20260226185555-69a0973b13c85.jpg', 'Menunggu', NULL, NULL, NULL, '2026-02-26 18:55:55', '2026-02-26 18:55:55'),
(9, 'SET-20260226-0002', 1, 9, '2026-02-27', 103000, 103000, 'Testing', 'SET-20260226185649-69a0977127122.jpg', 'Menunggu', NULL, NULL, NULL, '2026-02-26 18:56:49', '2026-02-26 18:56:49');

-- --------------------------------------------------------

--
-- Struktur dari tabel `stok`
--

CREATE TABLE `stok` (
  `id` int(11) NOT NULL,
  `kode_stok` varchar(50) NOT NULL COMMENT 'Kode unik stok, misal: STK-20260217-0001',
  `cabang_id` int(11) NOT NULL COMMENT 'FK ke cabang.id',
  `nama_produk` varchar(150) NOT NULL COMMENT 'Nama produk / barang',
  `kategori` varchar(100) DEFAULT NULL COMMENT 'Kategori produk',
  `satuan` varchar(30) NOT NULL COMMENT 'Satuan: pcs, kg, lusin, dll',
  `stok_masuk` int(11) NOT NULL DEFAULT 0,
  `stok_keluar` int(11) NOT NULL DEFAULT 0,
  `stok_tersedia` int(11) GENERATED ALWAYS AS (`stok_masuk` - `stok_keluar`) STORED COMMENT 'Otomatis: stok_masuk - stok_keluar',
  `harga_beli` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Harga beli per satuan (Rp)',
  `harga_jual` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Harga jual per satuan (Rp)',
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stok barang per cabang, dikelola BG (Bagian Gudang)';

--
-- Dumping data untuk tabel `stok`
--

INSERT INTO `stok` (`id`, `kode_stok`, `cabang_id`, `nama_produk`, `kategori`, `satuan`, `stok_masuk`, `stok_keluar`, `harga_beli`, `harga_jual`, `keterangan`, `created_at`, `updated_at`) VALUES
(9, 'STK-20260226-0001', 1, 'Sanjai Balado', 'Snack', 'pcs', 150, 9, 5000, 7000, 'Stok Awal', '2026-02-26 13:38:15', '2026-02-26 14:27:44'),
(10, 'STK-20260226-0002', 1, 'Sanjai Original', 'Snack', 'pcs', 100, 5, 5500, 8000, 'Stok', '2026-02-26 14:18:09', '2026-02-26 14:27:44'),
(11, 'STK-20260301-0001', 1, 'Sanjai Asin', 'Snack', 'pcs', 50, 3, 500000, 10000, '', '2026-03-01 16:03:18', '2026-03-01 20:20:05'),
(12, 'STK-20260301-0002', 1, 'Sanjai Polos', 'Snack', 'pcs', 60, 0, 600000, 10000, '', '2026-03-01 16:05:46', '2026-03-01 16:05:46'),
(13, 'STK-20260301-0003', 1, 'Karupuak Jangek', 'Snack', 'pcs', 57, 0, 800000, 20000, '', '2026-03-01 16:06:57', '2026-03-01 16:06:57');

-- --------------------------------------------------------

--
-- Struktur dari tabel `transaksi`
--

CREATE TABLE `transaksi` (
  `id` int(11) NOT NULL,
  `no_transaksi` varchar(50) NOT NULL COMMENT 'Nomor unik transaksi, misal: TRX-20260217-0001',
  `cabang_id` int(11) NOT NULL COMMENT 'FK ke cabang.id',
  `stok_id` int(11) DEFAULT NULL,
  `nama_pelanggan` varchar(150) NOT NULL COMMENT 'Nama pelanggan / customer',
  `jenis_transaksi` enum('Penjualan','Pembelian','Retur','Lainnya') NOT NULL DEFAULT 'Penjualan',
  `jumlah` int(11) NOT NULL DEFAULT 1 COMMENT 'Kuantitas item',
  `harga_satuan` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Harga per satuan (Rp)',
  `total` bigint(20) NOT NULL DEFAULT 0 COMMENT 'jumlah × harga_satuan (dihitung di PHP)',
  `keterangan` text DEFAULT NULL COMMENT 'Catatan / keterangan tambahan',
  `tanggal` date NOT NULL COMMENT 'Tanggal transaksi',
  `status` enum('Pending','Proses','Selesai','Dibatalkan') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Data transaksi per cabang Sanjai Zivanes';

--
-- Dumping data untuk tabel `transaksi`
--

INSERT INTO `transaksi` (`id`, `no_transaksi`, `cabang_id`, `stok_id`, `nama_pelanggan`, `jenis_transaksi`, `jumlah`, `harga_satuan`, `total`, `keterangan`, `tanggal`, `status`, `created_at`, `updated_at`) VALUES
(38, 'TRX-20260301-0001', 1, 11, 'roy', 'Penjualan', 3, 10000, 30000, 'weee', '2026-03-01', 'Selesai', '2026-03-01 20:20:05', '2026-03-01 20:20:05');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `nama_lengkap` varchar(100) DEFAULT NULL,
  `nik` varchar(20) DEFAULT NULL,
  `jabatan` varchar(100) DEFAULT NULL,
  `cabang_id` int(11) DEFAULT NULL,
  `password` varchar(50) NOT NULL,
  `role` enum('AdminP','AdminC','BG','Karyawan','Pemilik') NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `username`, `nama_lengkap`, `nik`, `jabatan`, `cabang_id`, `password`, `role`, `created_at`) VALUES
(2, 'adminc', NULL, NULL, NULL, NULL, 'adminc', 'AdminC', '2026-02-17 12:08:54'),
(3, 'bg', NULL, NULL, NULL, NULL, '123', 'BG', '2026-02-17 12:08:54'),
(5, 'pemilik', NULL, NULL, NULL, NULL, 'pemilik', 'Pemilik', '2026-02-17 12:08:54'),
(7, 'adminp', NULL, NULL, NULL, NULL, 'adminp', 'AdminP', '2026-02-17 13:08:52'),
(9, 'adminCabang1', NULL, NULL, NULL, NULL, 'adminCabang1', 'AdminC', '2026-02-17 14:07:46'),
(10, 'adminCabang2', NULL, NULL, NULL, NULL, 'adminCabang2', 'AdminC', '2026-02-17 14:08:00'),
(11, 'adminCabang3', NULL, NULL, NULL, NULL, 'adminCabang3', 'AdminC', '2026-02-17 14:08:09'),
(14, 'ode', 'ade', '2113001', 'Karyawan', 1, 'ode', 'Karyawan', '2026-03-01 15:52:30'),
(15, 'alif', 'alif', '2113002', 'Kasir', 1, 'alif', 'Karyawan', '2026-03-01 15:53:11'),
(16, 'kei', 'kei', '2113003', 'Karyawan', 1, 'kei', 'Karyawan', '2026-03-01 15:53:42'),
(17, 'farhan', 'farhan', '2113004', 'Karyawan', 1, 'farhan', 'Karyawan', '2026-03-01 15:54:49'),
(18, 'danial', 'danial', '2113005', 'Karyawan', 1, 'danial', 'Karyawan', '2026-03-01 15:56:04'),
(19, 'wili', 'wili', '2113006', 'Karyawan', 2, 'wili', 'Karyawan', '2026-03-01 15:56:54'),
(20, 'rehan', 'rehan', '2113007', 'Karyawan', 2, 'rehan', 'Karyawan', '2026-03-01 15:57:25'),
(21, 'zik', 'zik', '2113008', 'Karyawan', 2, 'zik', 'Karyawan', '2026-03-01 15:58:56'),
(22, 'hilmi', 'hilmi', '2113009', 'kasir', 2, 'hilmi', 'Karyawan', '2026-03-01 15:59:58'),
(23, 'budi', 'budi', '21130010', 'Karyawan', 2, 'budi', 'Karyawan', '2026-03-01 16:00:59');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `absensi`
--
ALTER TABLE `absensi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_absensi_karyawan_tanggal` (`karyawan_id`,`tanggal`),
  ADD KEY `idx_cabang_id` (`cabang_id`),
  ADD KEY `idx_tanggal` (`tanggal`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_absensi_dicatat` (`dicatat_oleh`);

--
-- Indeks untuk tabel `cabang`
--
ALTER TABLE `cabang`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kode_cabang` (`kode_cabang`),
  ADD KEY `fk_cabang_admin` (`admin_id`);

--
-- Indeks untuk tabel `penggajian`
--
ALTER TABLE `penggajian`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `setoran`
--
ALTER TABLE `setoran`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_no_setoran` (`no_setoran`),
  ADD KEY `idx_cabang_id` (`cabang_id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_tanggal` (`tanggal`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `stok`
--
ALTER TABLE `stok`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kode_stok` (`kode_stok`),
  ADD KEY `idx_cabang_id` (`cabang_id`),
  ADD KEY `idx_nama_produk` (`nama_produk`),
  ADD KEY `idx_stok_tersedia` (`stok_tersedia`);

--
-- Indeks untuk tabel `transaksi`
--
ALTER TABLE `transaksi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cabang_id` (`cabang_id`),
  ADD KEY `idx_tanggal` (`tanggal`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_users_cabang` (`cabang_id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `absensi`
--
ALTER TABLE `absensi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT untuk tabel `cabang`
--
ALTER TABLE `cabang`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `penggajian`
--
ALTER TABLE `penggajian`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `setoran`
--
ALTER TABLE `setoran`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `stok`
--
ALTER TABLE `stok`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT untuk tabel `transaksi`
--
ALTER TABLE `transaksi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `absensi`
--
ALTER TABLE `absensi`
  ADD CONSTRAINT `fk_absensi_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_absensi_dicatat` FOREIGN KEY (`dicatat_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_absensi_karyawan` FOREIGN KEY (`karyawan_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `cabang`
--
ALTER TABLE `cabang`
  ADD CONSTRAINT `fk_cabang_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `setoran`
--
ALTER TABLE `setoran`
  ADD CONSTRAINT `fk_setoran_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_setoran_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stok`
--
ALTER TABLE `stok`
  ADD CONSTRAINT `fk_stok_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `transaksi`
--
ALTER TABLE `transaksi`
  ADD CONSTRAINT `fk_transaksi_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
