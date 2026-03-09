<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'AdminC') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Resolve admin ID ──────────────────────────────────────
$adminId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userId'] ?? 0);
if ($adminId === 0 && !empty($_SESSION['username'])) {
    $uStmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $uStmt->bind_param("s", $_SESSION['username']);
    $uStmt->execute();
    $uRow = $uStmt->get_result()->fetch_assoc();
    $adminId = (int)($uRow['id'] ?? 0);
    $uStmt->close();
    if ($adminId > 0) $_SESSION['user_id'] = $adminId;
}

// ── Cabang milik admin ────────────────────────────────────
$cabangSaya = null;
if ($adminId > 0) {
    $cStmt = $conn->prepare("SELECT id, nama_cabang, kode_cabang FROM cabang WHERE admin_id = ? LIMIT 1");
    $cStmt->bind_param("i", $adminId);
    $cStmt->execute();
    $cabangSaya = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();
}
$cabangId = (int)($cabangSaya['id'] ?? 0);

// ── Filter Parameters ─────────────────────────────────────
$filterTglDari   = $_GET['tgl_dari']   ?? '';
$filterTglSampai = $_GET['tgl_sampai'] ?? '';
$filterJenis     = $_GET['jenis']      ?? ''; // Pemasukan / Pengeluaran / ''

// ── Pagination ────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// =========================================================
// DATA SOURCES:
//   Pemasukan  → transaksi (jenis_transaksi = 'Penjualan', status = 'Selesai')
//   Pengeluaran → penggajian (cabang tidak ada di penggajian, pakai cabang_id via users)
//                 + setoran (sebagai referensi)
//
// Untuk laporan keuangan sederhana kita tampilkan:
//   1. Total Penjualan (Pemasukan)
//   2. Total Penggajian (Pengeluaran) — filter by karyawan cabang ini
//   3. Setoran ke pusat
//   4. Saldo = Pemasukan - Pengeluaran
// =========================================================

// ── Build tanggal WHERE helper ────────────────────────────
function buildDateWhere($field, $dari, $sampai, &$params, &$types) {
    $conds = [];
    if ($dari !== '') {
        $conds[] = "$field >= ?";
        $params[] = $dari;
        $types .= "s";
    }
    if ($sampai !== '') {
        $conds[] = "$field <= ?";
        $params[] = $sampai;
        $types .= "s";
    }
    return $conds;
}

// ── 1. Total Penjualan (Pemasukan) ───────────────────────
$pemasukanParams = [$cabangId];
$pemasukanTypes  = "i";
$pemasukanConds  = ["t.cabang_id = ?", "t.jenis_transaksi = 'Penjualan'", "t.status = 'Selesai'"];
$pemasukanConds  = array_merge($pemasukanConds, buildDateWhere("t.tanggal", $filterTglDari, $filterTglSampai, $pemasukanParams, $pemasukanTypes));
$pemasukanWhere  = implode(" AND ", $pemasukanConds);

$pStmt = $conn->prepare("SELECT COALESCE(SUM(t.total),0) AS total FROM transaksi t WHERE $pemasukanWhere");
$pStmt->bind_param($pemasukanTypes, ...$pemasukanParams);
$pStmt->execute();
$totalPemasukan = (int)$pStmt->get_result()->fetch_assoc()['total'];
$pStmt->close();

// ── 2. Total Penggajian (Pengeluaran) ────────────────────
// penggajian tidak punya cabang_id langsung, kita filter by users.cabang_id
// Join via nik (penggajian.nik = users.nik) jika ada, atau semua penggajian cabang ini
// Karena relasi tidak langsung, kita ambil penggajian yang NIK-nya cocok dengan karyawan cabang ini
$gajiParams = [$cabangId];
$gajiTypes  = "i";
$gajiConds  = ["u.cabang_id = ?"];

// Filter bulan/tahun berdasarkan tanggal (approximate: jika filterTglDari ada, ambil tahun/bulannya)
// Untuk simplisitas kita tidak filter periode penggajian jika tidak ada filter tanggal
// Kita konversi bulan enum ke angka untuk perbandingan tanggal
$gajiWhere = "p.nik = u.nik AND u.cabang_id = ?";
if ($filterTglDari !== '' || $filterTglSampai !== '') {
    // Kita filter by tahun penggajian saja (simpel)
    if ($filterTglDari !== '') {
        $gajiWhere .= " AND p.tahun >= ?";
        $gajiParams[] = date('Y', strtotime($filterTglDari));
        $gajiTypes  .= "s";
    }
    if ($filterTglSampai !== '') {
        $gajiWhere .= " AND p.tahun <= ?";
        $gajiParams[] = date('Y', strtotime($filterTglSampai));
        $gajiTypes  .= "s";
    }
}

$gStmt = $conn->prepare("
    SELECT COALESCE(SUM(p.total_gaji),0) AS total
    FROM penggajian p
    JOIN users u ON u.nik = p.nik
    WHERE $gajiWhere
");
$gStmt->bind_param($gajiTypes, ...$gajiParams);
$gStmt->execute();
$totalPenggajian = (int)$gStmt->get_result()->fetch_assoc()['total'];
$gStmt->close();

// ── 3. Total Setoran ke Pusat ─────────────────────────────
$setParams = [$cabangId];
$setTypes  = "i";
$setConds  = ["s.cabang_id = ?"];
$setConds  = array_merge($setConds, buildDateWhere("s.tanggal", $filterTglDari, $filterTglSampai, $setParams, $setTypes));
$setWhere  = implode(" AND ", $setConds);

$sStmt = $conn->prepare("SELECT COALESCE(SUM(s.jumlah_setoran),0) AS total FROM setoran s WHERE $setWhere");
$sStmt->bind_param($setTypes, ...$setParams);
$sStmt->execute();
$totalSetoran = (int)$sStmt->get_result()->fetch_assoc()['total'];
$sStmt->close();

// ── 4. Saldo ──────────────────────────────────────────────
$totalPengeluaran = $totalPenggajian + $totalSetoran;
$saldo = $totalPemasukan - $totalPengeluaran;

// ── Detail Transaksi Keuangan (gabungan untuk tabel) ─────
// Kita tampilkan dua tabel terpisah: Pemasukan & Pengeluaran

// ── Pemasukan (penjualan grouped by no_transaksi) ─────────
if ($filterJenis === '' || $filterJenis === 'Pemasukan') {
    $cntPema = $conn->prepare("SELECT COUNT(DISTINCT t.no_transaksi) total FROM transaksi t WHERE $pemasukanWhere");
    $cntPema->bind_param($pemasukanTypes, ...$pemasukanParams);
    $cntPema->execute();
    $totalPemaRows = (int)$cntPema->get_result()->fetch_assoc()['total'];
    $cntPema->close();

    $pemaParamsLimit = array_merge($pemasukanParams, [$perPage, $offset]);
    $pemaTypesLimit  = $pemasukanTypes . "ii";
    $pemaStmt = $conn->prepare("
        SELECT t.no_transaksi, MIN(t.tanggal) AS tanggal, MIN(t.nama_pelanggan) AS nama_pelanggan,
               SUM(t.total) AS grand_total
        FROM transaksi t
        WHERE $pemasukanWhere
        GROUP BY t.no_transaksi
        ORDER BY MIN(t.tanggal) DESC, MIN(t.created_at) DESC
        LIMIT ? OFFSET ?
    ");
    $pemaStmt->bind_param($pemaTypesLimit, ...$pemaParamsLimit);
    $pemaStmt->execute();
    $pemaRows = $pemaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pemaStmt->close();
} else {
    $totalPemaRows = 0;
    $pemaRows = [];
}

// ── Pengeluaran: Penggajian ───────────────────────────────
if ($filterJenis === '' || $filterJenis === 'Pengeluaran') {
    $gajiParamsLimit = array_merge($gajiParams, [$perPage, $offset]);
    $gajiTypesLimit  = $gajiTypes . "ii";
    $gajiListStmt = $conn->prepare("
        SELECT p.id, p.nama, p.jabatan, p.bulan, p.tahun, p.total_gaji, p.status
        FROM penggajian p
        JOIN users u ON u.nik = p.nik
        WHERE $gajiWhere
        ORDER BY p.tahun DESC, FIELD(p.bulan,'Desember','November','Oktober','September','Agustus','Juli','Juni','Mei','April','Maret','Februari','Januari') ASC
        LIMIT ? OFFSET ?
    ");
    $gajiListStmt->bind_param($gajiTypesLimit, ...$gajiParamsLimit);
    $gajiListStmt->execute();
    $gajiRows = $gajiListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $gajiListStmt->close();

    // Setoran rows
    $setParamsLimit = array_merge($setParams, [$perPage, $offset]);
    $setTypesLimit  = $setTypes . "ii";
    $setListStmt = $conn->prepare("
        SELECT s.id, s.no_setoran, s.tanggal, s.jumlah_setoran, s.status, s.keterangan
        FROM setoran s
        WHERE $setWhere
        ORDER BY s.tanggal DESC, s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $setListStmt->bind_param($setTypesLimit, ...$setParamsLimit);
    $setListStmt->execute();
    $setoranRows = $setListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $setListStmt->close();
} else {
    $gajiRows    = [];
    $setoranRows = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan - Sanjai Zivanes</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend+Deca:wght@100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"
        onload="window.lucideLoaded=true; if(window.initLucide) window.initLucide();"></script>
    <script>
        window.initLucide = function() { if (window.lucide) lucide.createIcons(); };
        document.addEventListener('DOMContentLoaded', function() { if (window.lucideLoaded) window.initLucide(); });
    </script>
    <style type="text/tailwindcss">
        :root {
            --primary: #165DFF; --primary-hover: #0E4BD9; --foreground: #080C1A;
            --secondary: #6A7686; --muted: #EFF2F7; --border: #F3F4F3;
            --card-grey: #F1F3F6; --success: #30B22D; --success-light: #DCFCE7;
            --error: #ED6B60; --error-light: #FEE2E2; --warning: #FED71F;
            --warning-light: #FEF9C3; --font-sans: 'Lexend Deca', sans-serif;
        }
        @theme inline {
            --color-primary: var(--primary); --color-primary-hover: var(--primary-hover);
            --color-foreground: var(--foreground); --color-secondary: var(--secondary);
            --color-muted: var(--muted); --color-border: var(--border);
            --color-card-grey: var(--card-grey); --color-success: var(--success);
            --color-success-light: var(--success-light); --color-error: var(--error);
            --color-error-light: var(--error-light); --color-warning: var(--warning);
            --color-warning-light: var(--warning-light); --font-sans: var(--font-sans);
        }
        select { @apply appearance-none bg-no-repeat cursor-pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-position: right 10px center; padding-right: 40px; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .table-row-hover:hover { background-color: #F8FAFF; }

        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .print-header { display: block !important; }
            table { width: 100%; border-collapse: collapse; font-size: 8pt; }
            thead tr { background: #1a1a2e !important; color: #fff !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead th { padding: 6px 8px; border: 1px solid #0d0d1a; font-size: 7.5pt; }
            tbody td { padding: 5px 8px; border: 1px solid #ddd; font-size: 7.5pt; color: #222; }
            tbody tr:nth-child(even) td { background: #f9f9f9 !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tfoot td { padding: 6px 8px; border: 1px solid #ddd; font-size: 8pt; }
            .print-footer { display: block !important; }
            .summary-card { border: 1px solid #ddd !important; }
        }
        .print-header { display: none; }
        .print-footer { display: none; }
    </style>
</head>
<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">

        <!-- Header -->
        <div class="sticky top-0 z-30 flex items-center w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8 no-print">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all cursor-pointer">
                    <i data-lucide="menu" class="size-6 text-foreground"></i>
                </button>
                <div>
                    <h2 class="font-bold text-2xl text-foreground">Laporan Keuangan</h2>
                    <p class="hidden sm:block text-sm text-secondary">
                        <?php if ($cabangSaya): ?>
                            <span class="font-semibold text-primary"><?= htmlspecialchars($cabangSaya['nama_cabang']) ?></span>
                            &mdash; <?= htmlspecialchars($cabangSaya['kode_cabang']) ?>
                        <?php else: ?>Anda belum ditugaskan ke cabang manapun<?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <?php if (!$cabangSaya): ?>
            <div class="mb-6 flex items-center gap-4 p-5 rounded-2xl bg-warning/20 border border-warning/40 no-print">
                <div class="size-10 rounded-xl bg-warning/30 flex items-center justify-center shrink-0">
                    <i data-lucide="alert-triangle" class="size-5 text-yellow-700"></i>
                </div>
                <div>
                    <p class="font-semibold text-yellow-800">Belum terhubung ke cabang</p>
                    <p class="text-sm text-yellow-700">Hubungi Admin Pusat untuk menugaskan Anda ke cabang.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="bg-white rounded-2xl border border-border p-6 mb-6 no-print">
                <div class="flex items-center gap-3 mb-4">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="filter" class="size-5 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-lg text-foreground">Filter Laporan</h3>
                </div>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Tanggal Dari</label>
                        <input type="date" name="tgl_dari" value="<?= htmlspecialchars($filterTglDari) ?>"
                            class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Tanggal Sampai</label>
                        <input type="date" name="tgl_sampai" value="<?= htmlspecialchars($filterTglSampai) ?>"
                            class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Jenis</label>
                        <select name="jenis" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                            <option value="">Semua</option>
                            <option value="Pemasukan"  <?= $filterJenis === 'Pemasukan'  ? 'selected' : '' ?>>Pemasukan</option>
                            <option value="Pengeluaran" <?= $filterJenis === 'Pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="flex-1 h-12 bg-primary hover:bg-primary-hover text-white rounded-xl font-semibold flex items-center justify-center gap-2 transition-all">
                            <i data-lucide="search" class="size-4"></i>
                            <span>Filter</span>
                        </button>
                        <a href="laporan_keuangan.php" class="h-12 px-4 bg-muted hover:bg-secondary/10 text-secondary rounded-xl font-semibold flex items-center justify-center transition-all">
                            <i data-lucide="x" class="size-4"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-end gap-3 mb-6 no-print">
                <button onclick="window.print()" class="px-5 h-11 bg-white border border-border hover:bg-muted text-foreground rounded-xl font-semibold flex items-center gap-2 transition-all">
                    <i data-lucide="printer" class="size-4"></i>
                    <span>Cetak</span>
                </button>
            </div>

            <!-- ── Print Header ── -->
            <div class="print-header mb-4 pb-3 border-b-2 border-gray-800 text-center">
                <h1 style="font-size:16pt;font-weight:700;margin:0 0 4px;">LAPORAN KEUANGAN</h1>
                <p style="font-size:10pt;font-weight:600;margin:2px 0;">
                    <?= htmlspecialchars($cabangSaya['nama_cabang'] ?? '') ?>
                    <?php if (!empty($cabangSaya['kode_cabang'])): ?>
                        (<?= htmlspecialchars($cabangSaya['kode_cabang']) ?>)
                    <?php endif; ?>
                </p>
                <p style="font-size:8.5pt;color:#555;margin:2px 0;">
                    <?php
                    $parts = [];
                    if ($filterTglDari && $filterTglSampai)
                        $parts[] = 'Periode: ' . date('d/m/Y', strtotime($filterTglDari)) . ' s/d ' . date('d/m/Y', strtotime($filterTglSampai));
                    elseif ($filterTglDari)
                        $parts[] = 'Dari: ' . date('d/m/Y', strtotime($filterTglDari));
                    elseif ($filterTglSampai)
                        $parts[] = 's/d: ' . date('d/m/Y', strtotime($filterTglSampai));
                    echo $parts ? implode(' | ', $parts) : 'Semua Periode';
                    ?>
                    &nbsp;|&nbsp; Dicetak: <?= date('d/m/Y H:i') ?>
                </p>
            </div>

            <!-- ── Summary Cards ── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <!-- Pemasukan -->
                <div class="summary-card bg-white rounded-2xl border border-border p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl bg-success/10 flex items-center justify-center">
                            <i data-lucide="trending-up" class="size-5 text-success"></i>
                        </div>
                        <span class="text-xs font-bold text-success bg-success/10 px-2 py-1 rounded-full">Pemasukan</span>
                    </div>
                    <p class="text-sm text-secondary mb-1">Total Penjualan</p>
                    <p class="font-bold text-xl text-foreground font-mono">Rp <?= number_format($totalPemasukan, 0, ',', '.') ?></p>
                </div>

                <!-- Penggajian -->
                <div class="summary-card bg-white rounded-2xl border border-border p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl bg-error/10 flex items-center justify-center">
                            <i data-lucide="users" class="size-5 text-error"></i>
                        </div>
                        <span class="text-xs font-bold text-error bg-error/10 px-2 py-1 rounded-full">Pengeluaran</span>
                    </div>
                    <p class="text-sm text-secondary mb-1">Total Penggajian</p>
                    <p class="font-bold text-xl text-foreground font-mono">Rp <?= number_format($totalPenggajian, 0, ',', '.') ?></p>
                </div>

                <!-- Setoran -->
                <div class="summary-card bg-white rounded-2xl border border-border p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                            <i data-lucide="send" class="size-5 text-primary"></i>
                        </div>
                        <span class="text-xs font-bold text-primary bg-primary/10 px-2 py-1 rounded-full">Setoran</span>
                    </div>
                    <p class="text-sm text-secondary mb-1">Total Setoran ke Pusat</p>
                    <p class="font-bold text-xl text-foreground font-mono">Rp <?= number_format($totalSetoran, 0, ',', '.') ?></p>
                </div>

                <!-- Saldo -->
                <div class="summary-card rounded-2xl p-5 <?= $saldo >= 0 ? 'bg-success/10 border border-success/20' : 'bg-error/10 border border-error/20' ?>">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl <?= $saldo >= 0 ? 'bg-success/20' : 'bg-error/20' ?> flex items-center justify-center">
                            <i data-lucide="<?= $saldo >= 0 ? 'wallet' : 'alert-circle' ?>" class="size-5 <?= $saldo >= 0 ? 'text-success' : 'text-error' ?>"></i>
                        </div>
                        <span class="text-xs font-bold <?= $saldo >= 0 ? 'text-success bg-success/20' : 'text-error bg-error/20' ?> px-2 py-1 rounded-full">Saldo</span>
                    </div>
                    <p class="text-sm <?= $saldo >= 0 ? 'text-green-700' : 'text-red-700' ?> mb-1">Saldo Bersih</p>
                    <p class="font-bold text-xl <?= $saldo >= 0 ? 'text-success' : 'text-error' ?> font-mono">
                        <?= $saldo >= 0 ? '' : '-' ?>Rp <?= number_format(abs($saldo), 0, ',', '.') ?>
                    </p>
                    <p class="text-xs <?= $saldo >= 0 ? 'text-green-600' : 'text-red-600' ?> mt-1">Pemasukan - (Penggajian + Setoran)</p>
                </div>
            </div>

            <!-- ── Tabel Pemasukan ── -->
            <?php if ($filterJenis === '' || $filterJenis === 'Pemasukan'): ?>
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-9 rounded-xl bg-success/10 flex items-center justify-center">
                        <i data-lucide="trending-up" class="size-4 text-success"></i>
                    </div>
                    <h3 class="font-bold text-foreground">Detail Pemasukan (Penjualan)</h3>
                    <span class="ml-auto text-sm text-secondary font-semibold"><?= $totalPemaRows ?> transaksi</span>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[700px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No. Transaksi</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Pelanggan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pemaRows)): ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="py-12 flex flex-col items-center gap-3 text-center">
                                            <div class="size-14 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="inbox" class="size-7 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Tidak ada data pemasukan</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($pemaRows as $r): ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                            <?= htmlspecialchars($r['no_transaksi']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_pelanggan']) ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-medium text-secondary"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-success text-sm font-mono">+ Rp <?= number_format($r['grand_total'], 0, ',', '.') ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($pemaRows)): ?>
                        <tfoot>
                            <tr class="bg-success/5 border-t-2 border-success/30">
                                <td colspan="3" class="px-5 py-4 text-right font-bold text-foreground">Total Pemasukan:</td>
                                <td class="px-5 py-4 text-right">
                                    <p class="font-bold text-success text-lg font-mono">Rp <?= number_format($totalPemasukan, 0, ',', '.') ?></p>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Tabel Penggajian ── -->
            <?php if (($filterJenis === '' || $filterJenis === 'Pengeluaran') && !empty($gajiRows)): ?>
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-9 rounded-xl bg-error/10 flex items-center justify-center">
                        <i data-lucide="users" class="size-4 text-error"></i>
                    </div>
                    <h3 class="font-bold text-foreground">Pengeluaran — Penggajian Karyawan</h3>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[700px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nama</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jabatan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Periode</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total Gaji</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gajiRows as $g): ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($g['nama']) ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm text-secondary"><?= htmlspecialchars($g['jabatan']) ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm text-secondary"><?= $g['bulan'] ?> <?= $g['tahun'] ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php
                                        $statusGaji = $g['status'];
                                        if ($statusGaji === 'Sudah Dibayar') {
                                            $badgeClass = 'bg-success/10 text-success';
                                        } elseif ($statusGaji === 'Menunggu') {
                                            $badgeClass = 'bg-warning/20 text-yellow-700';
                                        } else {
                                            $badgeClass = 'bg-error/10 text-error';
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $badgeClass ?> text-xs font-bold">
                                            <?= htmlspecialchars($statusGaji) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-error text-sm font-mono">- Rp <?= number_format($g['total_gaji'], 0, ',', '.') ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-error/5 border-t-2 border-error/20">
                                <td colspan="4" class="px-5 py-4 text-right font-bold text-foreground">Total Penggajian:</td>
                                <td class="px-5 py-4 text-right">
                                    <p class="font-bold text-error text-lg font-mono">Rp <?= number_format($totalPenggajian, 0, ',', '.') ?></p>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Tabel Setoran ── -->
            <?php if (($filterJenis === '' || $filterJenis === 'Pengeluaran') && !empty($setoranRows)): ?>
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i data-lucide="send" class="size-4 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-foreground">Pengeluaran — Setoran ke Pusat</h3>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[700px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No. Setoran</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keterangan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($setoranRows as $s): ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                            <?= htmlspecialchars($s['no_setoran']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-medium text-secondary"><?= date('d/m/Y', strtotime($s['tanggal'])) ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm text-secondary"><?= htmlspecialchars($s['keterangan'] ?: '—') ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php
                                        $statusSet = $s['status'];
                                        if ($statusSet === 'Diterima') {
                                            $setBadge = 'bg-success/10 text-success';
                                        } elseif ($statusSet === 'Menunggu') {
                                            $setBadge = 'bg-warning/20 text-yellow-700';
                                        } elseif ($statusSet === 'Ditolak') {
                                            $setBadge = 'bg-error/10 text-error';
                                        } else {
                                            $setBadge = 'bg-muted text-secondary';
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $setBadge ?> text-xs font-bold">
                                            <?= htmlspecialchars($statusSet) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-primary text-sm font-mono">- Rp <?= number_format($s['jumlah_setoran'], 0, ',', '.') ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-primary/5 border-t-2 border-primary/20">
                                <td colspan="4" class="px-5 py-4 text-right font-bold text-foreground">Total Setoran:</td>
                                <td class="px-5 py-4 text-right">
                                    <p class="font-bold text-primary text-lg font-mono">Rp <?= number_format($totalSetoran, 0, ',', '.') ?></p>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Rekap Akhir ── -->
            <div class="bg-white rounded-2xl border border-border p-6 mb-8">
                <div class="flex items-center gap-3 mb-5">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i data-lucide="bar-chart-2" class="size-5 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-lg text-foreground">Rekap Keuangan</h3>
                </div>
                <div class="space-y-3">
                    <div class="flex items-center justify-between py-3 border-b border-border">
                        <div class="flex items-center gap-3">
                            <div class="size-2 rounded-full bg-success"></div>
                            <span class="text-sm font-medium text-foreground">Total Pemasukan (Penjualan)</span>
                        </div>
                        <span class="font-bold text-success font-mono">+ Rp <?= number_format($totalPemasukan, 0, ',', '.') ?></span>
                    </div>
                    <div class="flex items-center justify-between py-3 border-b border-border">
                        <div class="flex items-center gap-3">
                            <div class="size-2 rounded-full bg-error"></div>
                            <span class="text-sm font-medium text-foreground">Total Pengeluaran Penggajian</span>
                        </div>
                        <span class="font-bold text-error font-mono">- Rp <?= number_format($totalPenggajian, 0, ',', '.') ?></span>
                    </div>
                    <div class="flex items-center justify-between py-3 border-b border-border">
                        <div class="flex items-center gap-3">
                            <div class="size-2 rounded-full bg-primary"></div>
                            <span class="text-sm font-medium text-foreground">Total Setoran ke Pusat</span>
                        </div>
                        <span class="font-bold text-primary font-mono">- Rp <?= number_format($totalSetoran, 0, ',', '.') ?></span>
                    </div>
                    <div class="flex items-center justify-between py-4 rounded-xl <?= $saldo >= 0 ? 'bg-success/10' : 'bg-error/10' ?> px-4 mt-2">
                        <span class="font-bold text-foreground">Saldo Bersih</span>
                        <span class="font-bold text-xl <?= $saldo >= 0 ? 'text-success' : 'text-error' ?> font-mono">
                            <?= $saldo >= 0 ? '+' : '-' ?> Rp <?= number_format(abs($saldo), 0, ',', '.') ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- ── Print Footer ── -->
            <div class="print-footer" style="margin-top:40px; font-size:8pt; color:#555;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div>
                        <p>Dokumen ini dicetak secara otomatis oleh sistem.</p>
                        <p style="margin-top:4px;">Pemasukan: Penjualan selesai | Pengeluaran: Penggajian + Setoran ke Pusat</p>
                    </div>
                    <div style="text-align:center; min-width:160px;">
                        <p><?= htmlspecialchars($cabangSaya['nama_cabang'] ?? '') ?>, <?= date('d/m/Y') ?></p>
                        <div style="margin-top:50px; border-top:1px solid #333; padding-top:4px;">
                            Admin / Penanggung Jawab
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => lucide.createIcons());

        function toggleSidebar() {
            const s = document.getElementById('sidebar'), o = document.getElementById('sidebar-overlay');
            if (!s) return;
            s.classList.contains('-translate-x-full')
                ? (s.classList.remove('-translate-x-full'), o?.classList.remove('hidden'), document.body.style.overflow='hidden')
                : (s.classList.add('-translate-x-full'), o?.classList.add('hidden'), document.body.style.overflow='');
        }
    </script>
    <script src="../layout/index.js"></script>
</body>
</html>