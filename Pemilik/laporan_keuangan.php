<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'Pemilik') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Filter Parameters ─────────────────────────────────────
$filterTglDari   = $_GET['tgl_dari']   ?? '';
$filterTglSampai = $_GET['tgl_sampai'] ?? '';
$filterCabang    = (int)($_GET['cabang'] ?? 0);
$filterJenis     = $_GET['jenis']      ?? '';

// ── Daftar semua cabang ───────────────────────────────────
$cabangList = $conn->query("SELECT id, nama_cabang, kode_cabang FROM cabang ORDER BY nama_cabang ASC")->fetch_all(MYSQLI_ASSOC);

// ── Helper: build date WHERE ──────────────────────────────
function buildDateWhere($field, $dari, $sampai, &$params, &$types) {
    $conds = [];
    if ($dari !== '') {
        $conds[] = "$field >= ?";
        $params[] = $dari;
        $types   .= "s";
    }
    if ($sampai !== '') {
        $conds[] = "$field <= ?";
        $params[] = $sampai;
        $types   .= "s";
    }
    return $conds;
}

// ── Helper: get keuangan per cabang ──────────────────────
function getKeuanganCabang($conn, $cabangId, $dari, $sampai) {
    // 1. Pemasukan
    $pParams = [$cabangId];
    $pTypes  = "i";
    $pConds  = ["t.cabang_id = ?", "t.jenis_transaksi = 'Penjualan'", "t.status = 'Selesai'"];
    $pConds  = array_merge($pConds, buildDateWhere("t.tanggal", $dari, $sampai, $pParams, $pTypes));
    $pWhere  = implode(" AND ", $pConds);
    $pStmt   = $conn->prepare("SELECT COALESCE(SUM(t.total),0) AS total FROM transaksi t WHERE $pWhere");
    $pStmt->bind_param($pTypes, ...$pParams);
    $pStmt->execute();
    $pemasukan = (int)$pStmt->get_result()->fetch_assoc()['total'];
    $pStmt->close();

    // 2. Penggajian
    $gWhere  = "p.nik = u.nik AND u.cabang_id = ?";
    $gParams = [$cabangId];
    $gTypes  = "i";
    if ($dari !== '') {
        $gWhere   .= " AND p.tahun >= ?";
        $gParams[] = date('Y', strtotime($dari));
        $gTypes   .= "s";
    }
    if ($sampai !== '') {
        $gWhere   .= " AND p.tahun <= ?";
        $gParams[] = date('Y', strtotime($sampai));
        $gTypes   .= "s";
    }
    $gStmt = $conn->prepare("SELECT COALESCE(SUM(p.total_gaji),0) AS total FROM penggajian p JOIN users u ON u.nik = p.nik WHERE $gWhere");
    $gStmt->bind_param($gTypes, ...$gParams);
    $gStmt->execute();
    $penggajian = (int)$gStmt->get_result()->fetch_assoc()['total'];
    $gStmt->close();

    // 3. Setoran
    $sParams = [$cabangId];
    $sTypes  = "i";
    $sConds  = ["s.cabang_id = ?"];
    $sConds  = array_merge($sConds, buildDateWhere("s.tanggal", $dari, $sampai, $sParams, $sTypes));
    $sWhere  = implode(" AND ", $sConds);
    $sStmt   = $conn->prepare("SELECT COALESCE(SUM(s.jumlah_setoran),0) AS total FROM setoran s WHERE $sWhere");
    $sStmt->bind_param($sTypes, ...$sParams);
    $sStmt->execute();
    $setoran = (int)$sStmt->get_result()->fetch_assoc()['total'];
    $sStmt->close();

    return [
        'pemasukan'   => $pemasukan,
        'penggajian'  => $penggajian,
        'setoran'     => $setoran,
        'pengeluaran' => $penggajian + $setoran,
        'saldo'       => $pemasukan - $penggajian - $setoran,
    ];
}

// ── Hitung keuangan per cabang ────────────────────────────
$cabangKeuangan = [];
foreach ($cabangList as $cb) {
    if ($filterCabang > 0 && $cb['id'] != $filterCabang) continue;
    $cabangKeuangan[$cb['id']] = array_merge($cb, getKeuanganCabang($conn, $cb['id'], $filterTglDari, $filterTglSampai));
}

// ── Grand total semua cabang ──────────────────────────────
$grandPemasukan   = array_sum(array_column($cabangKeuangan, 'pemasukan'));
$grandPenggajian  = array_sum(array_column($cabangKeuangan, 'penggajian'));
$grandSetoran     = array_sum(array_column($cabangKeuangan, 'setoran'));
$grandPengeluaran = array_sum(array_column($cabangKeuangan, 'pengeluaran'));
$grandSaldo       = array_sum(array_column($cabangKeuangan, 'saldo'));

// ── Detail rows untuk cabang yang dipilih ────────────────
$detailCabangId = $filterCabang > 0 ? $filterCabang : 0;
$pemaRows    = [];
$gajiRows    = [];
$setoranRows = [];

if ($detailCabangId > 0 && ($filterJenis === '' || $filterJenis === 'Pemasukan')) {
    $pParams2 = [$detailCabangId];
    $pTypes2  = "i";
    $pConds2  = ["t.cabang_id = ?", "t.jenis_transaksi = 'Penjualan'", "t.status = 'Selesai'"];
    $pConds2  = array_merge($pConds2, buildDateWhere("t.tanggal", $filterTglDari, $filterTglSampai, $pParams2, $pTypes2));
    $pWhere2  = implode(" AND ", $pConds2);
    $pStmt2   = $conn->prepare("
        SELECT t.no_transaksi, MIN(t.tanggal) AS tanggal, MIN(t.nama_pelanggan) AS nama_pelanggan,
               SUM(t.total) AS grand_total
        FROM transaksi t WHERE $pWhere2
        GROUP BY t.no_transaksi
        ORDER BY MIN(t.tanggal) DESC, MIN(t.created_at) DESC
        LIMIT 50
    ");
    $pStmt2->bind_param($pTypes2, ...$pParams2);
    $pStmt2->execute();
    $pemaRows = $pStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $pStmt2->close();
}

if ($detailCabangId > 0 && ($filterJenis === '' || $filterJenis === 'Pengeluaran')) {
    $gWhere2  = "p.nik = u.nik AND u.cabang_id = ?";
    $gParams2 = [$detailCabangId];
    $gTypes2  = "i";
    if ($filterTglDari   !== '') { $gWhere2 .= " AND p.tahun >= ?"; $gParams2[] = date('Y', strtotime($filterTglDari));   $gTypes2 .= "s"; }
    if ($filterTglSampai !== '') { $gWhere2 .= " AND p.tahun <= ?"; $gParams2[] = date('Y', strtotime($filterTglSampai)); $gTypes2 .= "s"; }
    $gStmt2 = $conn->prepare("
        SELECT p.id, p.nama, p.jabatan, p.bulan, p.tahun, p.total_gaji, p.status
        FROM penggajian p JOIN users u ON u.nik = p.nik
        WHERE $gWhere2
        ORDER BY p.tahun DESC, FIELD(p.bulan,'Desember','November','Oktober','September','Agustus','Juli','Juni','Mei','April','Maret','Februari','Januari') ASC
        LIMIT 50
    ");
    $gStmt2->bind_param($gTypes2, ...$gParams2);
    $gStmt2->execute();
    $gajiRows = $gStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $gStmt2->close();

    $sParams2 = [$detailCabangId];
    $sTypes2  = "i";
    $sConds2  = ["s.cabang_id = ?"];
    $sConds2  = array_merge($sConds2, buildDateWhere("s.tanggal", $filterTglDari, $filterTglSampai, $sParams2, $sTypes2));
    $sWhere2  = implode(" AND ", $sConds2);
    $sStmt2   = $conn->prepare("
        SELECT s.id, s.no_setoran, s.tanggal, s.jumlah_setoran, s.status, s.keterangan
        FROM setoran s WHERE $sWhere2
        ORDER BY s.tanggal DESC, s.created_at DESC LIMIT 50
    ");
    $sStmt2->bind_param($sTypes2, ...$sParams2);
    $sStmt2->execute();
    $setoranRows = $sStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $sStmt2->close();
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
                    <p class="hidden sm:block text-sm text-secondary">Rekap keuangan seluruh cabang &mdash; Admin Pusat</p>
                </div>
            </div>
        </div>

        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <!-- Filter -->
            <div class="bg-white rounded-2xl border border-border p-6 mb-6 no-print">
                <div class="flex items-center gap-3 mb-4">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="filter" class="size-5 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-lg text-foreground">Filter Laporan</h3>
                </div>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
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
                        <label class="block text-sm font-medium text-secondary mb-2">Cabang</label>
                        <select name="cabang" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                            <option value="">Semua Cabang</option>
                            <?php foreach ($cabangList as $cb): ?>
                                <option value="<?= $cb['id'] ?>" <?= $filterCabang == $cb['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cb['nama_cabang']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Jenis</label>
                        <select name="jenis" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                            <option value="">Semua</option>
                            <option value="Pemasukan"   <?= $filterJenis === 'Pemasukan'   ? 'selected' : '' ?>>Pemasukan</option>
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

            <!-- Print Header -->
            <div class="print-header mb-4 pb-3 border-b-2 border-gray-800 text-center">
                <h1 style="font-size:16pt;font-weight:700;margin:0 0 4px;">LAPORAN KEUANGAN SELURUH CABANG</h1>
                <p style="font-size:10pt;font-weight:600;margin:2px 0;">Sanjai Zivanes &mdash; Admin Pusat</p>
                <p style="font-size:8.5pt;color:#555;margin:2px 0;">
                    <?php
                    $parts = [];
                    if ($filterTglDari && $filterTglSampai)
                        $parts[] = 'Periode: ' . date('d/m/Y', strtotime($filterTglDari)) . ' s/d ' . date('d/m/Y', strtotime($filterTglSampai));
                    elseif ($filterTglDari)
                        $parts[] = 'Dari: ' . date('d/m/Y', strtotime($filterTglDari));
                    elseif ($filterTglSampai)
                        $parts[] = 's/d: ' . date('d/m/Y', strtotime($filterTglSampai));
                    if ($filterCabang > 0) {
                        foreach ($cabangList as $cb) {
                            if ($cb['id'] == $filterCabang) { $parts[] = 'Cabang: ' . htmlspecialchars($cb['nama_cabang']); break; }
                        }
                    }
                    echo $parts ? implode(' | ', $parts) : 'Semua Periode &amp; Semua Cabang';
                    ?>
                    &nbsp;|&nbsp; Dicetak: <?= date('d/m/Y H:i') ?>
                </p>
            </div>

            <!-- Grand Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="bg-white rounded-2xl border border-border p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl bg-success/10 flex items-center justify-center">
                            <i data-lucide="trending-up" class="size-5 text-success"></i>
                        </div>
                        <span class="text-xs font-bold text-success bg-success/10 px-2 py-1 rounded-full">Pemasukan</span>
                    </div>
                    <p class="text-sm text-secondary mb-1">Total Penjualan</p>
                    <p class="font-bold text-xl text-foreground font-mono">Rp <?= number_format($grandPemasukan, 0, ',', '.') ?></p>
                    <p class="text-xs text-secondary mt-1"><?= count($cabangKeuangan) ?> cabang</p>
                </div>
                <div class="bg-white rounded-2xl border border-border p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl bg-error/10 flex items-center justify-center">
                            <i data-lucide="users" class="size-5 text-error"></i>
                        </div>
                        <span class="text-xs font-bold text-error bg-error/10 px-2 py-1 rounded-full">Pengeluaran</span>
                    </div>
                    <p class="text-sm text-secondary mb-1">Total Penggajian</p>
                    <p class="font-bold text-xl text-foreground font-mono">Rp <?= number_format($grandPenggajian, 0, ',', '.') ?></p>
                </div>
                <div class="bg-white rounded-2xl border border-border p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                            <i data-lucide="send" class="size-5 text-primary"></i>
                        </div>
                        <span class="text-xs font-bold text-primary bg-primary/10 px-2 py-1 rounded-full">Setoran</span>
                    </div>
                    <p class="text-sm text-secondary mb-1">Total Setoran Masuk</p>
                    <p class="font-bold text-xl text-foreground font-mono">Rp <?= number_format($grandSetoran, 0, ',', '.') ?></p>
                </div>
                <div class="rounded-2xl p-5 <?= $grandSaldo >= 0 ? 'bg-success/10 border border-success/20' : 'bg-error/10 border border-error/20' ?>">
                    <div class="flex items-center justify-between mb-3">
                        <div class="size-10 rounded-xl <?= $grandSaldo >= 0 ? 'bg-success/20' : 'bg-error/20' ?> flex items-center justify-center">
                            <i data-lucide="<?= $grandSaldo >= 0 ? 'wallet' : 'alert-circle' ?>" class="size-5 <?= $grandSaldo >= 0 ? 'text-success' : 'text-error' ?>"></i>
                        </div>
                        <span class="text-xs font-bold <?= $grandSaldo >= 0 ? 'text-success bg-success/20' : 'text-error bg-error/20' ?> px-2 py-1 rounded-full">Saldo</span>
                    </div>
                    <p class="text-sm <?= $grandSaldo >= 0 ? 'text-green-700' : 'text-red-700' ?> mb-1">Saldo Bersih</p>
                    <p class="font-bold text-xl <?= $grandSaldo >= 0 ? 'text-success' : 'text-error' ?> font-mono">
                        <?= $grandSaldo >= 0 ? '' : '-' ?>Rp <?= number_format(abs($grandSaldo), 0, ',', '.') ?>
                    </p>
                    <p class="text-xs <?= $grandSaldo >= 0 ? 'text-green-600' : 'text-red-600' ?> mt-1">Pemasukan - Penggajian - Setoran</p>
                </div>
            </div>

            <!-- Tabel Rekap Per Cabang -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i data-lucide="building-2" class="size-4 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-foreground">Rekap Keuangan Per Cabang</h3>
                    <span class="ml-auto text-sm text-secondary font-semibold"><?= count($cabangKeuangan) ?> cabang</span>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[900px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Pemasukan</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Penggajian</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Setoran</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total Pengeluaran</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Saldo Bersih</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider no-print">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cabangKeuangan)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="py-16 flex flex-col items-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="file-text" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Tidak ada data cabang</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($cabangKeuangan as $ck): ?>
                                <tr class="table-row-hover border-b border-border transition-colors <?= $detailCabangId == $ck['id'] ? 'bg-primary/5' : '' ?>">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($ck['nama_cabang']) ?></p>
                                        <p class="text-xs text-secondary font-mono"><?= htmlspecialchars($ck['kode_cabang']) ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-success text-sm font-mono">Rp <?= number_format($ck['pemasukan'], 0, ',', '.') ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="text-sm font-semibold text-error font-mono">Rp <?= number_format($ck['penggajian'], 0, ',', '.') ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="text-sm font-semibold text-primary font-mono">Rp <?= number_format($ck['setoran'], 0, ',', '.') ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="text-sm font-semibold text-foreground font-mono">Rp <?= number_format($ck['pengeluaran'], 0, ',', '.') ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-sm font-mono <?= $ck['saldo'] >= 0 ? 'text-success' : 'text-error' ?>">
                                            <?= $ck['saldo'] >= 0 ? '+' : '-' ?>Rp <?= number_format(abs($ck['saldo']), 0, ',', '.') ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 text-center no-print">
                                        <?php
                                        $dUrl = '?cabang=' . $ck['id'];
                                        if ($filterTglDari)   $dUrl .= '&tgl_dari='   . urlencode($filterTglDari);
                                        if ($filterTglSampai) $dUrl .= '&tgl_sampai=' . urlencode($filterTglSampai);
                                        if ($filterJenis)     $dUrl .= '&jenis='      . urlencode($filterJenis);
                                        $isActive = ($detailCabangId == $ck['id']);
                                        ?>
                                        <a href="<?= $isActive ? 'laporan_keuangan.php' . ($filterTglDari || $filterTglSampai || $filterJenis ? '?' . http_build_query(['tgl_dari'=>$filterTglDari,'tgl_sampai'=>$filterTglSampai,'jenis'=>$filterJenis]) : '') : $dUrl ?>"
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg <?= $isActive ? 'bg-primary text-white' : 'bg-primary/10 hover:bg-primary/20 text-primary' ?> text-xs font-bold transition-all">
                                            <i data-lucide="<?= $isActive ? 'eye-off' : 'eye' ?>" class="size-3.5"></i>
                                            <?= $isActive ? 'Tutup' : 'Detail' ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($cabangKeuangan)): ?>
                        <tfoot>
                            <tr class="bg-muted/60 border-t-2 border-primary">
                                <td class="px-5 py-4 font-bold text-foreground uppercase text-sm">Total Keseluruhan</td>
                                <td class="px-5 py-4 text-right"><p class="font-bold text-success font-mono">Rp <?= number_format($grandPemasukan, 0, ',', '.') ?></p></td>
                                <td class="px-5 py-4 text-right"><p class="font-bold text-error font-mono">Rp <?= number_format($grandPenggajian, 0, ',', '.') ?></p></td>
                                <td class="px-5 py-4 text-right"><p class="font-bold text-primary font-mono">Rp <?= number_format($grandSetoran, 0, ',', '.') ?></p></td>
                                <td class="px-5 py-4 text-right"><p class="font-bold text-foreground font-mono">Rp <?= number_format($grandPengeluaran, 0, ',', '.') ?></p></td>
                                <td class="px-5 py-4 text-right">
                                    <p class="font-bold text-lg font-mono <?= $grandSaldo >= 0 ? 'text-success' : 'text-error' ?>">
                                        <?= $grandSaldo >= 0 ? '+' : '-' ?>Rp <?= number_format(abs($grandSaldo), 0, ',', '.') ?>
                                    </p>
                                </td>
                                <td class="no-print"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- ── Detail Cabang Terpilih ── -->
            <?php if ($detailCabangId > 0):
                $selectedCabang = null;
                foreach ($cabangList as $cb) { if ($cb['id'] == $detailCabangId) { $selectedCabang = $cb; break; } }
            ?>
            <div class="mb-5 flex items-center gap-3 pt-2">
                <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center">
                    <i data-lucide="building" class="size-4 text-primary"></i>
                </div>
                <div>
                    <h3 class="font-bold text-lg text-foreground">Detail: <?= htmlspecialchars($selectedCabang['nama_cabang'] ?? '') ?></h3>
                    <p class="text-xs text-secondary"><?= htmlspecialchars($selectedCabang['kode_cabang'] ?? '') ?></p>
                </div>
            </div>

            <!-- Detail Pemasukan -->
            <?php if ($filterJenis === '' || $filterJenis === 'Pemasukan'): ?>
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-6">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-9 rounded-xl bg-success/10 flex items-center justify-center">
                        <i data-lucide="trending-up" class="size-4 text-success"></i>
                    </div>
                    <h3 class="font-bold text-foreground">Detail Pemasukan (Penjualan)</h3>
                    <span class="ml-auto text-sm text-secondary font-semibold"><?= count($pemaRows) ?> transaksi</span>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[600px]">
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
                                <tr><td colspan="4"><div class="py-10 text-center text-secondary text-sm">Tidak ada data pemasukan</div></td></tr>
                            <?php else: foreach ($pemaRows as $r): ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono"><?= htmlspecialchars($r['no_transaksi']) ?></span>
                                    </td>
                                    <td class="px-5 py-4"><p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_pelanggan']) ?></p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-medium text-secondary"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></p></td>
                                    <td class="px-5 py-4 text-right"><p class="font-bold text-success text-sm font-mono">+ Rp <?= number_format($r['grand_total'], 0, ',', '.') ?></p></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($pemaRows) && isset($cabangKeuangan[$detailCabangId])): ?>
                        <tfoot>
                            <tr class="bg-success/5 border-t-2 border-success/30">
                                <td colspan="3" class="px-5 py-4 text-right font-bold text-foreground">Total Pemasukan:</td>
                                <td class="px-5 py-4 text-right"><p class="font-bold text-success text-lg font-mono">Rp <?= number_format($cabangKeuangan[$detailCabangId]['pemasukan'], 0, ',', '.') ?></p></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detail Penggajian -->
            <?php if (($filterJenis === '' || $filterJenis === 'Pengeluaran') && !empty($gajiRows)): ?>
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-6">
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
                                    <td class="px-5 py-4"><p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($g['nama']) ?></p></td>
                                    <td class="px-5 py-4"><p class="text-sm text-secondary"><?= htmlspecialchars($g['jabatan']) ?></p></td>
                                    <td class="px-5 py-4"><p class="text-sm text-secondary"><?= $g['bulan'] ?> <?= $g['tahun'] ?></p></td>
                                    <td class="px-5 py-4">
                                        <?php
                                        $sGaji = $g['status'];
                                        if ($sGaji === 'Sudah Dibayar')      { $bc = 'bg-success/10 text-success'; }
                                        elseif ($sGaji === 'Menunggu')       { $bc = 'bg-warning/20 text-yellow-700'; }
                                        else                                  { $bc = 'bg-error/10 text-error'; }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $bc ?> text-xs font-bold"><?= htmlspecialchars($sGaji) ?></span>
                                    </td>
                                    <td class="px-5 py-4 text-right"><p class="font-bold text-error text-sm font-mono">- Rp <?= number_format($g['total_gaji'], 0, ',', '.') ?></p></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (isset($cabangKeuangan[$detailCabangId])): ?>
                        <tfoot>
                            <tr class="bg-error/5 border-t-2 border-error/20">
                                <td colspan="4" class="px-5 py-4 text-right font-bold text-foreground">Total Penggajian:</td>
                                <td class="px-5 py-4 text-right"><p class="font-bold text-error text-lg font-mono">Rp <?= number_format($cabangKeuangan[$detailCabangId]['penggajian'], 0, ',', '.') ?></p></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detail Setoran -->
            <?php if (($filterJenis === '' || $filterJenis === 'Pengeluaran') && !empty($setoranRows)): ?>
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-6">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i data-lucide="send" class="size-4 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-foreground">Setoran ke Pusat</h3>
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
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono"><?= htmlspecialchars($s['no_setoran']) ?></span>
                                    </td>
                                    <td class="px-5 py-4"><p class="text-sm font-medium text-secondary"><?= date('d/m/Y', strtotime($s['tanggal'])) ?></p></td>
                                    <td class="px-5 py-4"><p class="text-sm text-secondary"><?= htmlspecialchars($s['keterangan'] ?: '—') ?></p></td>
                                    <td class="px-5 py-4">
                                        <?php
                                        $sSet = $s['status'];
                                        if ($sSet === 'Diterima')      { $sb = 'bg-success/10 text-success'; }
                                        elseif ($sSet === 'Menunggu')  { $sb = 'bg-warning/20 text-yellow-700'; }
                                        elseif ($sSet === 'Ditolak')   { $sb = 'bg-error/10 text-error'; }
                                        else                            { $sb = 'bg-muted text-secondary'; }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $sb ?> text-xs font-bold"><?= htmlspecialchars($sSet) ?></span>
                                    </td>
                                    <td class="px-5 py-4 text-right"><p class="font-bold text-primary text-sm font-mono">Rp <?= number_format($s['jumlah_setoran'], 0, ',', '.') ?></p></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (isset($cabangKeuangan[$detailCabangId])): ?>
                        <tfoot>
                            <tr class="bg-primary/5 border-t-2 border-primary/20">
                                <td colspan="4" class="px-5 py-4 text-right font-bold text-foreground">Total Setoran:</td>
                                <td class="px-5 py-4 text-right"><p class="font-bold text-primary text-lg font-mono">Rp <?= number_format($cabangKeuangan[$detailCabangId]['setoran'], 0, ',', '.') ?></p></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Rekap Cabang Terpilih -->
            <?php if (isset($cabangKeuangan[$detailCabangId])): $ck = $cabangKeuangan[$detailCabangId]; ?>
            <div class="bg-white rounded-2xl border border-border p-6 mb-8">
                <div class="flex items-center gap-3 mb-5">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i data-lucide="bar-chart-2" class="size-5 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-lg text-foreground">Rekap — <?= htmlspecialchars($ck['nama_cabang']) ?></h3>
                </div>
                <div class="space-y-3">
                    <div class="flex items-center justify-between py-3 border-b border-border">
                        <div class="flex items-center gap-3"><div class="size-2 rounded-full bg-success"></div><span class="text-sm font-medium text-foreground">Total Pemasukan (Penjualan)</span></div>
                        <span class="font-bold text-success font-mono">+ Rp <?= number_format($ck['pemasukan'], 0, ',', '.') ?></span>
                    </div>
                    <div class="flex items-center justify-between py-3 border-b border-border">
                        <div class="flex items-center gap-3"><div class="size-2 rounded-full bg-error"></div><span class="text-sm font-medium text-foreground">Total Pengeluaran Penggajian</span></div>
                        <span class="font-bold text-error font-mono">- Rp <?= number_format($ck['penggajian'], 0, ',', '.') ?></span>
                    </div>
                    <div class="flex items-center justify-between py-3 border-b border-border">
                        <div class="flex items-center gap-3"><div class="size-2 rounded-full bg-primary"></div><span class="text-sm font-medium text-foreground">Total Setoran ke Pusat</span></div>
                        <span class="font-bold text-primary font-mono">- Rp <?= number_format($ck['setoran'], 0, ',', '.') ?></span>
                    </div>
                    <div class="flex items-center justify-between py-4 rounded-xl <?= $ck['saldo'] >= 0 ? 'bg-success/10' : 'bg-error/10' ?> px-4 mt-2">
                        <span class="font-bold text-foreground">Saldo Bersih</span>
                        <span class="font-bold text-xl <?= $ck['saldo'] >= 0 ? 'text-success' : 'text-error' ?> font-mono">
                            <?= $ck['saldo'] >= 0 ? '+' : '-' ?> Rp <?= number_format(abs($ck['saldo']), 0, ',', '.') ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; // end detailCabangId ?>

            <!-- Print Footer -->
            <div class="print-footer" style="margin-top:40px; font-size:8pt; color:#555;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div>
                        <p>Dokumen ini dicetak secara otomatis oleh sistem Sanjai Zivanes.</p>
                        <p style="margin-top:4px;">Pemasukan: Penjualan selesai | Pengeluaran: Penggajian + Setoran ke Pusat</p>
                    </div>
                    <div style="text-align:center; min-width:160px;">
                        <p>Admin Pusat, <?= date('d/m/Y') ?></p>
                        <div style="margin-top:50px; border-top:1px solid #333; padding-top:4px;">Admin Pusat / Pimpinan</div>
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