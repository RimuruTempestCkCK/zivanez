<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'Pemilik') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Fetch semua cabang untuk dropdown filter ──────────────
$allCabang = $conn->query("SELECT id, kode_cabang, nama_cabang FROM cabang ORDER BY kode_cabang ASC")
    ->fetch_all(MYSQLI_ASSOC);

// ── Filter Parameters ─────────────────────────────────────
$filterSearch   = trim($_GET['search']      ?? '');
$filterCabang   = (int)($_GET['cabang_id']  ?? 0);
$filterStok     = trim($_GET['stok_status'] ?? '');
$filterKategori = trim($_GET['kategori']    ?? '');

// ── Build WHERE ───────────────────────────────────────────
$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($filterCabang > 0) {
    $where   .= " AND s.cabang_id = ?";
    $params[] = $filterCabang;
    $types   .= "i";
}
if ($filterSearch !== '') {
    $like     = "%{$filterSearch}%";
    $where   .= " AND (s.kode_stok LIKE ? OR s.nama_produk LIKE ? OR s.kategori LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($filterKategori !== '') {
    $where   .= " AND s.kategori = ?";
    $params[] = $filterKategori;
    $types   .= "s";
}
if ($filterStok === 'habis') {
    $where .= " AND s.stok_tersedia <= 0";
} elseif ($filterStok === 'menipis') {
    $where .= " AND s.stok_tersedia > 0 AND s.stok_tersedia <= 10";
} elseif ($filterStok === 'aman') {
    $where .= " AND s.stok_tersedia > 10";
}

// ── Pagination ────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Count
$cntStmt = $conn->prepare("SELECT COUNT(*) FROM stok s JOIN cabang c ON c.id = s.cabang_id $where");
if ($types) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch rows
$paramsLimit = array_merge($params, [$perPage, $offset]);
$typesLimit  = $types . "ii";

$dStmt = $conn->prepare("
    SELECT s.*, c.nama_cabang, c.kode_cabang
    FROM stok s
    JOIN cabang c ON c.id = s.cabang_id
    $where
    ORDER BY c.kode_cabang ASC, s.nama_produk ASC
    LIMIT ? OFFSET ?
");
$dStmt->bind_param($typesLimit, ...$paramsLimit);
$dStmt->execute();
$rows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dStmt->close();

// ── Stats ─────────────────────────────────────────────────
$statTotal   = (int)$conn->query("SELECT COUNT(*) FROM stok")->fetch_row()[0];
$statHabis   = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia <= 0")->fetch_row()[0];
$statMenipis = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia > 0 AND stok_tersedia <= 10")->fetch_row()[0];
$statNilai   = (int)$conn->query("SELECT COALESCE(SUM(stok_tersedia * harga_jual),0) FROM stok")->fetch_row()[0];

// ── Summary per Cabang (untuk tabel ringkasan cetak) ──────
$summaryRows = $conn->query("
    SELECT c.kode_cabang, c.nama_cabang,
           COUNT(s.id) AS total_produk,
           COALESCE(SUM(s.stok_tersedia), 0) AS total_tersedia,
           COALESCE(SUM(s.stok_tersedia * s.harga_jual), 0) AS nilai_stok
    FROM stok s
    JOIN cabang c ON c.id = s.cabang_id
    GROUP BY c.id
    ORDER BY c.kode_cabang ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Daftar kategori unik untuk filter ────────────────────
$kategoriList = $conn->query("SELECT DISTINCT kategori FROM stok WHERE kategori != '' ORDER BY kategori ASC")
    ->fetch_all(MYSQLI_ASSOC);

// ── URL builder ───────────────────────────────────────────
function buildUrl($p, $search = '', $cabang = 0, $stok = '', $kat = '') {
    $url = "?page=$p";
    if ($search) $url .= "&search="      . urlencode($search);
    if ($cabang) $url .= "&cabang_id="   . (int)$cabang;
    if ($stok)   $url .= "&stok_status=" . urlencode($stok);
    if ($kat)    $url .= "&kategori="    . urlencode($kat);
    return $url;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Stok - Sanjai Zivanes</title>
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
            .print-summary { display: block !important; }
            .print-footer { display: block !important; }
            table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
            thead tr { background: #1a1a2e !important; color: #fff !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead th { padding: 5px 6px; border: 1px solid #0d0d1a; font-size: 7pt; }
            tbody td { padding: 4px 6px; border: 1px solid #ddd; font-size: 7pt; color: #222; }
            tbody tr:nth-child(even) td { background: #f9f9f9 !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tfoot td { padding: 5px 6px; border: 1px solid #ddd; font-size: 7.5pt; }
            .lg\:ml-\[280px\] { margin-left: 0 !important; }
        }
        .print-header  { display: none; }
        .print-summary { display: none; }
        .print-footer  { display: none; }
    </style>
</head>
<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">

        <!-- ── Page Header ── -->
        <div class="sticky top-0 z-30 flex items-center w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8 no-print">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all cursor-pointer">
                    <i data-lucide="menu" class="size-6 text-foreground"></i>
                </button>
                <div>
                    <h2 class="font-bold text-2xl text-foreground">Laporan Stok</h2>
                    <p class="hidden sm:block text-sm text-secondary">Laporan stok barang seluruh cabang</p>
                </div>
            </div>
        </div>

        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <!-- ── Filter ── -->
            <div class="bg-white rounded-2xl border border-border p-6 mb-6 no-print">
                <div class="flex items-center gap-3 mb-4">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="filter" class="size-5 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-lg text-foreground">Filter Laporan</h3>
                </div>

                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4">
                    <!-- Search -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-secondary mb-2">Kode / Nama Produk</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>"
                            placeholder="Cari kode atau nama produk..."
                            class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>

                    <!-- Filter Cabang -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Cabang</label>
                        <select name="cabang_id" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary transition-all text-sm">
                            <option value="0">Semua Cabang</option>
                            <?php foreach ($allCabang as $cb): ?>
                                <option value="<?= $cb['id'] ?>" <?= $filterCabang == $cb['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cb['kode_cabang'] . ' - ' . $cb['nama_cabang']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filter Kategori -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Kategori</label>
                        <select name="kategori" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary transition-all text-sm">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($kategoriList as $kat): ?>
                                <option value="<?= htmlspecialchars($kat['kategori']) ?>"
                                    <?= $filterKategori === $kat['kategori'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kat['kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filter Status Stok -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Status Stok</label>
                        <select name="stok_status" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary transition-all text-sm">
                            <option value="">Semua Status</option>
                            <option value="aman"    <?= $filterStok === 'aman'    ? 'selected' : '' ?>>Stok Aman</option>
                            <option value="menipis" <?= $filterStok === 'menipis' ? 'selected' : '' ?>>Menipis (≤10)</option>
                            <option value="habis"   <?= $filterStok === 'habis'   ? 'selected' : '' ?>>Habis</option>
                        </select>
                    </div>

                    <!-- Tombol -->
                    <div class="md:col-span-5 flex items-center gap-2">
                        <button type="submit" class="h-12 px-6 bg-primary hover:bg-primary-hover text-white rounded-xl font-semibold flex items-center justify-center gap-2 transition-all">
                            <i data-lucide="search" class="size-4"></i>
                            <span>Cari</span>
                        </button>
                        <a href="laporan_stok.php" class="h-12 px-4 bg-muted hover:bg-secondary/10 text-secondary rounded-xl font-semibold flex items-center justify-center transition-all">
                            <i data-lucide="x" class="size-4"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- ── Tombol Cetak ── -->
            <div class="flex justify-end gap-3 mb-6 no-print">
                <button onclick="window.print()" class="px-5 h-11 bg-white border border-border hover:bg-muted text-foreground rounded-xl font-semibold flex items-center gap-2 transition-all">
                    <i data-lucide="printer" class="size-4"></i>
                    <span>Cetak</span>
                </button>
            </div>

            <!-- ── Print Header ── -->
            <div class="print-header mb-4 pb-3 border-b-2 border-gray-800 text-center">
                <h1 style="font-size:16pt;font-weight:700;margin:0 0 4px;">LAPORAN DATA STOK BARANG</h1>
                <p style="font-size:10pt;font-weight:600;margin:2px 0;">Sanjai Zivanes</p>
                <p style="font-size:8.5pt;color:#555;margin:2px 0;">
                    <?php
                    $filterInfo = [];
                    if ($filterSearch) $filterInfo[] = 'Cari: ' . htmlspecialchars($filterSearch);
                    if ($filterCabang) {
                        foreach ($allCabang as $cb) {
                            if ($cb['id'] == $filterCabang) {
                                $filterInfo[] = 'Cabang: ' . htmlspecialchars($cb['nama_cabang']); break;
                            }
                        }
                    }
                    if ($filterKategori) $filterInfo[] = 'Kategori: ' . htmlspecialchars($filterKategori);
                    if ($filterStok)     $filterInfo[] = 'Status: ' . ucfirst($filterStok);
                    if ($filterInfo) echo implode(' &nbsp;|&nbsp; ', $filterInfo) . ' &nbsp;|&nbsp; ';
                    ?>
                    Dicetak: <?= date('d/m/Y H:i') ?>
                    &nbsp;|&nbsp; Total: <?= number_format($totalRows) ?> produk
                    &nbsp;|&nbsp; Nilai Stok: Rp <?= number_format($statNilai, 0, ',', '.') ?>
                </p>
            </div>

            <!-- ── Tabel ── -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1050px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kode Stok</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nama Produk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kategori</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Masuk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keluar</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Harga Jual</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nilai Stok</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="py-16 flex flex-col items-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="inbox" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Tidak ada data stok</p>
                                            <p class="text-sm text-secondary">Silakan sesuaikan filter untuk melihat data.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                $no = $offset + 1;
                                $grandNilai = 0;
                                foreach ($rows as $r):
                                    $tersedia  = (int)$r['stok_tersedia'];
                                    $nilaiStok = $tersedia * (int)$r['harga_jual'];
                                    $grandNilai += $nilaiStok;
                                    if ($tersedia <= 0) {
                                        $sBg = 'bg-error/10';   $sTx = 'text-error';       $sLabel = 'Habis';
                                    } elseif ($tersedia <= 10) {
                                        $sBg = 'bg-warning/20'; $sTx = 'text-yellow-700';  $sLabel = 'Menipis';
                                    } else {
                                        $sBg = 'bg-success/10'; $sTx = 'text-success';     $sLabel = 'Aman';
                                    }
                            ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4 text-sm text-secondary"><?= $no++ ?></td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                            <?= htmlspecialchars($r['kode_stok']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-secondary/10 text-secondary text-xs font-bold font-mono">
                                                <?= htmlspecialchars($r['kode_cabang']) ?>
                                            </span>
                                            <span class="text-sm text-foreground font-medium"><?= htmlspecialchars($r['nama_cabang']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_produk']) ?></p>
                                        <p class="text-xs text-secondary"><?= htmlspecialchars($r['satuan']) ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-secondary">
                                        <?= htmlspecialchars($r['kategori'] ?: '-') ?>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-mono text-foreground font-medium">
                                        <?= number_format($r['stok_masuk'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-mono text-error font-medium">
                                        <?= number_format($r['stok_keluar'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-sm font-bold font-mono <?= $sTx ?>">
                                            <?= number_format($tersedia, 0, ',', '.') ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-mono text-foreground">
                                        Rp <?= number_format($r['harga_jual'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-mono text-foreground font-medium">
                                        Rp <?= number_format($nilaiStok, 0, ',', '.') ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $sBg ?> <?= $sTx ?> text-xs font-bold">
                                            <?= $sLabel ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr class="bg-muted/60 border-t-2 border-primary">
                                <td colspan="9" class="px-5 py-4 text-sm text-secondary">
                                    Total produk: <strong class="text-foreground"><?= number_format($totalRows) ?></strong>
                                    &nbsp;|&nbsp; Habis: <strong class="text-error"><?= $statHabis ?></strong>
                                    &nbsp;|&nbsp; Menipis: <strong class="text-yellow-600"><?= $statMenipis ?></strong>
                                </td>
                                <td colspan="2" class="px-5 py-4 text-sm font-bold text-foreground">
                                    Rp <?= number_format($grandNilai, 0, ',', '.') ?>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- ── Pagination ── -->
                <?php if ($totalPages > 1): ?>
                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3 no-print">
                    <p class="text-sm text-secondary">
                        Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                        dari <span class="font-semibold text-foreground"><?= number_format($totalRows) ?></span> data
                    </p>
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildUrl($page-1, $filterSearch, $filterCabang, $filterStok, $filterKategori) ?>"
                               class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++):
                            $isAct = $i === $page;
                        ?>
                            <a href="<?= buildUrl($i, $filterSearch, $filterCabang, $filterStok, $filterKategori) ?>"
                               class="size-9 flex items-center justify-center rounded-lg border text-sm font-semibold transition-all cursor-pointer
                               <?= $isAct ? 'bg-primary/10 border-primary/20 text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildUrl($page+1, $filterSearch, $filterCabang, $filterStok, $filterKategori) ?>"
                               class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Print: Ringkasan per Cabang ── -->
            <div class="print-summary" style="margin-top:24px;page-break-inside:avoid;">
                <h2 style="font-size:11pt;font-weight:700;margin-bottom:8px;border-bottom:1px solid #333;padding-bottom:4px;">
                    Ringkasan Stok per Cabang
                </h2>
                <table style="width:100%;border-collapse:collapse;font-size:8pt;">
                    <thead>
                        <tr style="background:#1a1a2e;color:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;">
                            <th style="padding:5px 8px;border:1px solid #0d0d1a;text-align:left;">Kode</th>
                            <th style="padding:5px 8px;border:1px solid #0d0d1a;text-align:left;">Nama Cabang</th>
                            <th style="padding:5px 8px;border:1px solid #0d0d1a;text-align:center;">Total Produk</th>
                            <th style="padding:5px 8px;border:1px solid #0d0d1a;text-align:right;">Total Tersedia</th>
                            <th style="padding:5px 8px;border:1px solid #0d0d1a;text-align:right;">Nilai Stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaryRows as $sr): ?>
                        <tr>
                            <td style="padding:4px 8px;border:1px solid #ddd;font-family:monospace;"><?= htmlspecialchars($sr['kode_cabang']) ?></td>
                            <td style="padding:4px 8px;border:1px solid #ddd;"><?= htmlspecialchars($sr['nama_cabang']) ?></td>
                            <td style="padding:4px 8px;border:1px solid #ddd;text-align:center;"><?= number_format($sr['total_produk']) ?></td>
                            <td style="padding:4px 8px;border:1px solid #ddd;text-align:right;font-family:monospace;"><?= number_format($sr['total_tersedia'], 0, ',', '.') ?></td>
                            <td style="padding:4px 8px;border:1px solid #ddd;text-align:right;font-family:monospace;">Rp <?= number_format($sr['nilai_stok'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#efefef;font-weight:700;-webkit-print-color-adjust:exact;print-color-adjust:exact;">
                            <td colspan="2" style="padding:5px 8px;border:1px solid #ddd;">Total Keseluruhan</td>
                            <td style="padding:5px 8px;border:1px solid #ddd;text-align:center;"><?= number_format($statTotal) ?></td>
                            <td style="padding:5px 8px;border:1px solid #ddd;text-align:right;font-family:monospace;">—</td>
                            <td style="padding:5px 8px;border:1px solid #ddd;text-align:right;font-family:monospace;">Rp <?= number_format($statNilai, 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- ── Print Footer ── -->
            <div class="print-footer" style="margin-top:40px;font-size:8pt;color:#555;">
                <div style="display:flex;justify-content:space-between;align-items:flex-end;">
                    <div>
                        <p>Dokumen ini dicetak secara otomatis oleh sistem.</p>
                        <p style="margin-top:4px;">
                            Total Produk: <?= $statTotal ?>
                            &nbsp;|&nbsp; Stok Habis: <?= $statHabis ?>
                            &nbsp;|&nbsp; Menipis: <?= $statMenipis ?>
                        </p>
                        <p style="margin-top:2px;">Nilai Stok Keseluruhan: Rp <?= number_format($statNilai, 0, ',', '.') ?></p>
                    </div>
                    <div style="text-align:center;min-width:160px;">
                        <p>Gudang / BG, <?= date('d/m/Y') ?></p>
                        <div style="margin-top:50px;border-top:1px solid #333;padding-top:4px;">
                            Penanggung Jawab Stok
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.flex-1 -->
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => lucide.createIcons());

        function toggleSidebar() {
            const s = document.getElementById('sidebar'), o = document.getElementById('sidebar-overlay');
            if (!s) return;
            s.classList.contains('-translate-x-full')
                ? (s.classList.remove('-translate-x-full'), o?.classList.remove('hidden'), document.body.style.overflow = 'hidden')
                : (s.classList.add('-translate-x-full'), o?.classList.add('hidden'), document.body.style.overflow = '');
        }
    </script>
    <script src="../layout/index.js"></script>
</body>
</html>