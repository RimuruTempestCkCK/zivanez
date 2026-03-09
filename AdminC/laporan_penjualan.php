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

// ── Daftar produk untuk filter ───────────────────────────
function getAllProdukList($conn, $cabangId) {
    if ($cabangId <= 0) return [];
    $s = $conn->prepare("SELECT id, nama_produk FROM stok WHERE cabang_id = ? ORDER BY nama_produk ASC");
    $s->bind_param("i", $cabangId);
    $s->execute();
    $r = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $r;
}
$produkList = getAllProdukList($conn, $cabangId);

// ── Filter Parameters ─────────────────────────────────────
$filterTglDari   = $_GET['tgl_dari']   ?? '';
$filterTglSampai = $_GET['tgl_sampai'] ?? '';
$filterProduk    = (int)($_GET['produk'] ?? 0);

// Build WHERE clause
$whereConditions = ["t.cabang_id = ?", "t.jenis_transaksi = 'Penjualan'", "t.status = 'Selesai'"];
$params     = [$cabangId];
$paramTypes = "i";

if ($filterTglDari !== '') {
    $whereConditions[] = "t.tanggal >= ?";
    $params[]    = $filterTglDari;
    $paramTypes .= "s";
}
if ($filterTglSampai !== '') {
    $whereConditions[] = "t.tanggal <= ?";
    $params[]    = $filterTglSampai;
    $paramTypes .= "s";
}
if ($filterProduk > 0) {
    $whereConditions[] = "t.stok_id = ?";
    $params[]    = $filterProduk;
    $paramTypes .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

// ── Pagination ────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Hitung jumlah no_transaksi unik
$cntS = $conn->prepare("SELECT COUNT(DISTINCT t.no_transaksi) total FROM transaksi t WHERE $whereClause");
$cntS->bind_param($paramTypes, ...$params);
$cntS->execute();
$totalRows = (int)$cntS->get_result()->fetch_assoc()['total'];
$cntS->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// ── Ambil no_transaksi unik (grouped, paginated) ──────────
$paramsWithLimit     = array_merge($params, [$perPage, $offset]);
$paramTypesWithLimit = $paramTypes . "ii";

$noStmt = $conn->prepare("
    SELECT t.no_transaksi,
           MIN(t.tanggal)        AS tanggal,
           MIN(t.nama_pelanggan) AS nama_pelanggan,
           MIN(t.status)         AS status,
           MIN(t.created_at)     AS created_at,
           SUM(t.total)          AS grand_total
    FROM transaksi t
    WHERE $whereClause
    GROUP BY t.no_transaksi
    ORDER BY MIN(t.tanggal) DESC, MIN(t.created_at) DESC
    LIMIT ? OFFSET ?
");
$noStmt->bind_param($paramTypesWithLimit, ...$paramsWithLimit);
$noStmt->execute();
$groupedRows = $noStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$noStmt->close();

// ── Ambil detail items per no_transaksi ───────────────────
$rows = [];
foreach ($groupedRows as $gr) {
    $no = $gr['no_transaksi'];
    $dStmt = $conn->prepare("
        SELECT t.id, t.stok_id, t.jumlah, t.harga_satuan, t.total, t.keterangan,
               s.nama_produk, s.satuan
        FROM transaksi t
        LEFT JOIN stok s ON s.id = t.stok_id
        WHERE t.no_transaksi = ? AND t.cabang_id = ?
        ORDER BY t.id ASC
    ");
    $dStmt->bind_param("si", $no, $cabangId);
    $dStmt->execute();
    $items = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dStmt->close();

    $rows[] = [
        'no_transaksi'   => $no,
        'tanggal'        => $gr['tanggal'],
        'nama_pelanggan' => $gr['nama_pelanggan'],
        'status'         => $gr['status'],
        'grand_total'    => $gr['grand_total'],
        'keterangan'     => $items[0]['keterangan'] ?? '',
        'items'          => $items,
    ];
}

// ── Grand Total (semua halaman, semua no_transaksi unik) ──
$totStmt = $conn->prepare("SELECT SUM(t.total) as grand_total FROM transaksi t WHERE $whereClause");
$totStmt->bind_param($paramTypes, ...$params);
$totStmt->execute();
$grandTotal = $totStmt->get_result()->fetch_assoc()['grand_total'] ?? 0;
$totStmt->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan - Sanjai Zivanes</title>
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

        /* ── Print ── */
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
                    <h2 class="font-bold text-2xl text-foreground">Laporan Penjualan</h2>
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
                        <label class="block text-sm font-medium text-secondary mb-2">Produk</label>
                        <select name="produk" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                            <option value="">Semua Produk</option>
                            <?php foreach ($produkList as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $filterProduk == $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nama_produk']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex items-end gap-2">
                        <button type="submit" class="flex-1 h-12 bg-primary hover:bg-primary-hover text-white rounded-xl font-semibold flex items-center justify-center gap-2 transition-all">
                            <i data-lucide="search" class="size-4"></i>
                            <span>Filter</span>
                        </button>
                        <a href="laporan_penjualan.php" class="h-12 px-4 bg-muted hover:bg-secondary/10 text-secondary rounded-xl font-semibold flex items-center justify-center transition-all">
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
                <h1 style="font-size:16pt;font-weight:700;margin:0 0 4px;">LAPORAN PENJUALAN</h1>
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
                    if ($filterProduk > 0) {
                        $namaP = '';
                        foreach ($produkList as $p) { if ($p['id'] == $filterProduk) { $namaP = $p['nama_produk']; break; } }
                        if ($namaP) $parts[] = 'Produk: ' . htmlspecialchars($namaP);
                    }
                    echo $parts ? implode(' | ', $parts) : 'Semua Periode &amp; Produk';
                    ?>
                    &nbsp;|&nbsp; Dicetak: <?= date('d/m/Y H:i') ?>
                    &nbsp;|&nbsp; Total: <?= number_format($totalRows) ?> transaksi
                </p>
            </div>

            <!-- Tabel Laporan -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[900px]" id="laporanTable">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No. Transaksi</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Pelanggan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Produk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Grand Total</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="py-16 flex flex-col items-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="file-text" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Tidak ada data laporan</p>
                                            <p class="text-sm text-secondary">Silakan sesuaikan filter untuk melihat laporan penjualan.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <!-- No. Transaksi -->
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                            <?= htmlspecialchars($r['no_transaksi']) ?>
                                        </span>
                                        <?php if (count($r['items']) > 1): ?>
                                        <br><span class="mt-1 inline-flex items-center px-1.5 py-0.5 rounded-md bg-secondary/10 text-secondary text-[10px] font-bold">
                                            <?= count($r['items']) ?> produk
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Pelanggan -->
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_pelanggan']) ?></p>
                                    </td>
                                    <!-- Produk (semua item) -->
                                    <td class="px-5 py-4">
                                        <div class="space-y-1.5">
                                        <?php foreach ($r['items'] as $item): ?>
                                            <div>
                                                <p class="text-sm text-foreground font-medium leading-tight">
                                                    <?= htmlspecialchars($item['nama_produk'] ?? '—') ?>
                                                </p>
                                                <p class="text-xs text-secondary font-mono">
                                                    <?= number_format($item['jumlah'], 0, ',', '.') ?>
                                                    <?= htmlspecialchars($item['satuan'] ?? '') ?>
                                                    &times; Rp <?= number_format($item['harga_satuan'], 0, ',', '.') ?>
                                                    = <span class="text-foreground font-semibold">Rp <?= number_format($item['total'], 0, ',', '.') ?></span>
                                                </p>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <!-- Grand Total -->
                                    <td class="px-5 py-4">
                                        <p class="font-bold text-foreground text-sm font-mono">Rp <?= number_format($r['grand_total'], 0, ',', '.') ?></p>
                                        <?php if (count($r['items']) > 1): ?>
                                        <p class="text-xs text-secondary"><?= count($r['items']) ?> item</p>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Tanggal -->
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-medium text-secondary"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></p>
                                    </td>
                                    <!-- Status -->
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-success/10 text-success text-xs font-bold">
                                            Selesai
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr class="bg-muted/60 border-t-2 border-primary">
                                <td colspan="3" class="px-5 py-4 text-right font-bold text-foreground uppercase">Grand Total Keseluruhan:</td>
                                <td class="px-5 py-4" colspan="3">
                                    <p class="font-bold text-primary text-lg font-mono">Rp <?= number_format($grandTotal, 0, ',', '.') ?></p>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3 no-print">
                    <p class="text-sm text-secondary">
                        Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                        dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> transaksi
                    </p>
                    <?php if ($totalPages > 1): ?>
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1):
                            $prevUrl = "?page=" . ($page-1);
                            if ($filterTglDari)   $prevUrl .= "&tgl_dari="   . urlencode($filterTglDari);
                            if ($filterTglSampai) $prevUrl .= "&tgl_sampai=" . urlencode($filterTglSampai);
                            if ($filterProduk)    $prevUrl .= "&produk="     . $filterProduk;
                        ?>
                            <a href="<?= $prevUrl ?>" class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++):
                            $pageUrl = "?page=$i";
                            if ($filterTglDari)   $pageUrl .= "&tgl_dari="   . urlencode($filterTglDari);
                            if ($filterTglSampai) $pageUrl .= "&tgl_sampai=" . urlencode($filterTglSampai);
                            if ($filterProduk)    $pageUrl .= "&produk="     . $filterProduk;
                        ?>
                            <a href="<?= $pageUrl ?>" class="size-9 flex items-center justify-center rounded-lg border <?= $i == $page ? 'bg-primary/10 border-primary/20 font-semibold text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?> text-sm transition-all cursor-pointer"><?= $i ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages):
                            $nextUrl = "?page=" . ($page+1);
                            if ($filterTglDari)   $nextUrl .= "&tgl_dari="   . urlencode($filterTglDari);
                            if ($filterTglSampai) $nextUrl .= "&tgl_sampai=" . urlencode($filterTglSampai);
                            if ($filterProduk)    $nextUrl .= "&produk="     . $filterProduk;
                        ?>
                            <a href="<?= $nextUrl ?>" class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Print Footer ── -->
            <div class="print-footer" style="margin-top:40px; font-size:8pt; color:#555;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div>
                        <p>Dokumen ini dicetak secara otomatis oleh sistem.</p>
                        <p style="margin-top:4px;">Hanya menampilkan transaksi Penjualan dengan status Selesai.</p>
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