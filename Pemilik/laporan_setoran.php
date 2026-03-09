<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'Pemilik') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Filter Parameters ─────────────────────────────────────
$filterSearch = trim($_GET['search']  ?? '');
$filterStatus = trim($_GET['status']  ?? '');
$filterBulan  = trim($_GET['bulan']   ?? '');
$filterCabang = (int)($_GET['cabang'] ?? 0);

// ── Cabang list for filter ────────────────────────────────
$cabangList = $conn->query("SELECT id, kode_cabang, nama_cabang FROM cabang ORDER BY nama_cabang")->fetch_all(MYSQLI_ASSOC);

// ── Build WHERE ───────────────────────────────────────────
$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($filterSearch !== '') {
    $like     = "%{$filterSearch}%";
    $where   .= " AND (s.no_setoran LIKE ? OR s.keterangan LIKE ? OR c.nama_cabang LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($filterStatus !== '') {
    $where   .= " AND s.status = ?";
    $params[] = $filterStatus;
    $types   .= "s";
}
if ($filterBulan !== '') {
    $where   .= " AND DATE_FORMAT(s.tanggal, '%Y-%m') = ?";
    $params[] = $filterBulan;
    $types   .= "s";
}
if ($filterCabang > 0) {
    $where   .= " AND s.cabang_id = ?";
    $params[] = $filterCabang;
    $types   .= "i";
}

// ── Pagination ────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Count
$cntStmt = $conn->prepare("SELECT COUNT(*) FROM setoran s JOIN cabang c ON c.id = s.cabang_id $where");
if ($types) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

// Fetch
$paramsLimit = array_merge($params, [$perPage, $offset]);
$typesLimit  = $types . "ii";

$dStmt = $conn->prepare("
    SELECT s.*,
           c.nama_cabang, c.kode_cabang,
           u.username AS admin_username,
           v.username AS verifier_username
    FROM setoran s
    JOIN cabang c  ON c.id = s.cabang_id
    JOIN users  u  ON u.id = s.admin_id
    LEFT JOIN users v ON v.id = s.verified_by
    $where
    ORDER BY FIELD(s.status,'Menunggu','Ditolak','Diterima'), s.tanggal DESC, s.created_at DESC
    LIMIT ? OFFSET ?
");
$dStmt->bind_param($typesLimit, ...$paramsLimit);
$dStmt->execute();
$rows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dStmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// ── Grand Total setoran diterima ──────────────────────────
$totBase   = $where;   // reuse same filter
$totParams = $params;
$totTypes  = $types;

// Tambah filter status=Diterima untuk grand total nominal
$totWhere = $where . " AND s.status = 'Diterima'";
$totStmt  = $conn->prepare("SELECT SUM(s.jumlah_setoran) as grand FROM setoran s JOIN cabang c ON c.id = s.cabang_id $totWhere");
if ($types) $totStmt->bind_param($types, ...$params);
$totStmt->execute();
$grandTotalDiterima = $totStmt->get_result()->fetch_assoc()['grand'] ?? 0;
$totStmt->close();

// Grand total semua (sesuai filter)
$allTotStmt = $conn->prepare("SELECT SUM(s.jumlah_setoran) as grand FROM setoran s JOIN cabang c ON c.id = s.cabang_id $where");
if ($types) $allTotStmt->bind_param($types, ...$params);
$allTotStmt->execute();
$grandTotalAll = $allTotStmt->get_result()->fetch_assoc()['grand'] ?? 0;
$allTotStmt->close();

// ── URL builder ───────────────────────────────────────────
function buildUrl($p, $search='', $status='', $bulan='', $cabang=0) {
    $url = "?page=$p";
    if ($search)  $url .= "&search="  . urlencode($search);
    if ($status)  $url .= "&status="  . urlencode($status);
    if ($bulan)   $url .= "&bulan="   . urlencode($bulan);
    if ($cabang)  $url .= "&cabang="  . $cabang;
    return $url;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Setoran - Sanjai Zivanes</title>
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

        /* ── Print — identik dengan laporan_stok.php ── */
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

        <!-- ── Page Header ── -->
        <div class="sticky top-0 z-30 flex items-center w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8 no-print">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all cursor-pointer">
                    <i data-lucide="menu" class="size-6 text-foreground"></i>
                </button>
                <div>
                    <h2 class="font-bold text-2xl text-foreground">Laporan Setoran</h2>
                    <p class="hidden sm:block text-sm text-secondary">Rekap setoran harian seluruh cabang</p>
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

                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <!-- Search -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-secondary mb-2">No. Setoran / Cabang</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>"
                            placeholder="Cari no. setoran atau nama cabang..."
                            class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>

                    <!-- Cabang -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Cabang</label>
                        <select name="cabang" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                            <option value="">Semua Cabang</option>
                            <?php foreach ($cabangList as $cb): ?>
                                <option value="<?= $cb['id'] ?>" <?= $filterCabang === (int)$cb['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cb['nama_cabang']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Status</label>
                        <select name="status" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                            <option value="">Semua Status</option>
                            <option value="Menunggu" <?= $filterStatus === 'Menunggu' ? 'selected' : '' ?>>Menunggu</option>
                            <option value="Diterima" <?= $filterStatus === 'Diterima' ? 'selected' : '' ?>>Diterima</option>
                            <option value="Ditolak"  <?= $filterStatus === 'Ditolak'  ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                    </div>

                    <!-- Bulan -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Bulan</label>
                        <input type="month" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>"
                            class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>

                    <!-- Tombol -->
                    <div class="flex items-end gap-2 md:col-span-5">
                        <button type="submit" class="h-12 px-6 bg-primary hover:bg-primary-hover text-white rounded-xl font-semibold flex items-center justify-center gap-2 transition-all">
                            <i data-lucide="search" class="size-4"></i>
                            <span>Filter</span>
                        </button>
                        <a href="laporan_setoran.php" class="h-12 px-4 bg-muted hover:bg-secondary/10 text-secondary rounded-xl font-semibold flex items-center justify-center transition-all">
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

            <!-- ── Print Header (identik dengan laporan_stok.php) ── -->
            <div class="print-header mb-4 pb-3 border-b-2 border-gray-800 text-center">
                <h1 style="font-size:16pt;font-weight:700;margin:0 0 4px;">LAPORAN SETORAN HARIAN</h1>
                <p style="font-size:10pt;font-weight:600;margin:2px 0;">Sanjai Zivanes — Seluruh Cabang</p>
                <p style="font-size:8.5pt;color:#555;margin:2px 0;">
                    <?php
                    $parts = [];
                    if ($filterBulan) {
                        $dt = DateTime::createFromFormat('Y-m', $filterBulan);
                        $parts[] = 'Bulan: ' . ($dt ? $dt->format('F Y') : $filterBulan);
                    }
                    if ($filterCabang > 0) {
                        foreach ($cabangList as $cb) {
                            if ((int)$cb['id'] === $filterCabang) { $parts[] = 'Cabang: ' . htmlspecialchars($cb['nama_cabang']); break; }
                        }
                    }
                    if ($filterStatus) $parts[] = 'Status: ' . htmlspecialchars($filterStatus);
                    echo $parts ? implode(' | ', $parts) : 'Semua Periode & Cabang';
                    ?>
                    &nbsp;|&nbsp; Dicetak: <?= date('d/m/Y H:i') ?>
                    &nbsp;|&nbsp; Total: <?= number_format($totalRows) ?> setoran
                </p>
            </div>

            <!-- ── Tabel Laporan ── -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1100px]" id="setoranTable">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No. Setoran</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Admin</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jumlah Setoran</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total Omset</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Verifikator</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="py-16 flex flex-col items-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="inbox" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Tidak ada data setoran</p>
                                            <p class="text-sm text-secondary">Silakan sesuaikan filter untuk melihat laporan setoran.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                $no = $offset + 1;
                                foreach ($rows as $r):
                                    $stCfg = [
                                        'Menunggu' => ['bg-warning/20',  'text-yellow-700', 'bg-warning',  'clock'],
                                        'Diterima' => ['bg-success/10',  'text-success',    'bg-success',  'check-circle'],
                                        'Ditolak'  => ['bg-error/10',    'text-error',      'bg-error',    'x-circle'],
                                    ];
                                    [$sBg, $sTx, $sDot, $sIco] = $stCfg[$r['status']] ?? ['bg-secondary/10','text-secondary','bg-secondary','circle'];
                            ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4 text-sm text-secondary"><?= $no++ ?></td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                            <?= htmlspecialchars($r['no_setoran']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_cabang']) ?></p>
                                        <p class="text-xs text-secondary font-mono"><?= htmlspecialchars($r['kode_cabang']) ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-secondary"><?= htmlspecialchars($r['admin_username']) ?></td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-foreground"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></p>
                                        <p class="text-xs text-secondary"><?= date('l', strtotime($r['tanggal'])) ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-foreground text-sm font-mono">
                                            Rp <?= number_format($r['jumlah_setoran'], 0, ',', '.') ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="text-sm text-secondary font-mono">
                                            Rp <?= number_format($r['total_transaksi'], 0, ',', '.') ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full <?= $sBg ?> <?= $sTx ?> text-xs font-bold">
                                            <span class="size-1.5 rounded-full <?= $sDot ?>"></span>
                                            <?= htmlspecialchars($r['status']) ?>
                                        </span>
                                        <?php if ($r['status'] === 'Ditolak' && $r['catatan_penolakan']): ?>
                                            <p class="text-xs text-error mt-1 max-w-[160px] truncate" title="<?= htmlspecialchars($r['catatan_penolakan']) ?>">
                                                "<?= htmlspecialchars($r['catatan_penolakan']) ?>"
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($r['verifier_username']): ?>
                                            <p class="text-sm text-foreground font-medium"><?= htmlspecialchars($r['verifier_username']) ?></p>
                                            <?php if ($r['verified_at']): ?>
                                                <p class="text-xs text-secondary"><?= date('d/m/Y H:i', strtotime($r['verified_at'])) ?></p>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-secondary text-xs italic">Belum diverifikasi</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr class="bg-muted/60 border-t-2 border-primary">
                                <td colspan="5" class="px-5 py-4 text-right font-bold text-foreground uppercase text-sm">
                                    Total Setoran Diterima:
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <p class="font-bold text-success text-base font-mono">
                                        Rp <?= number_format($grandTotalDiterima, 0, ',', '.') ?>
                                    </p>
                                </td>
                                <td colspan="3" class="px-5 py-4 text-left text-xs text-secondary">
                                    (dari semua filter aktif)
                                </td>
                            </tr>
                            <tr class="bg-muted/40 border-t border-border">
                                <td colspan="5" class="px-5 py-4 text-right font-bold text-foreground uppercase text-sm">
                                    Grand Total Semua Setoran:
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <p class="font-bold text-primary text-lg font-mono">
                                        Rp <?= number_format($grandTotalAll, 0, ',', '.') ?>
                                    </p>
                                </td>
                                <td colspan="3"></td>
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
                            <a href="<?= buildUrl($page-1, $filterSearch, $filterStatus, $filterBulan, $filterCabang) ?>"
                               class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++):
                            $isActive = $i === $page;
                        ?>
                            <a href="<?= buildUrl($i, $filterSearch, $filterStatus, $filterBulan, $filterCabang) ?>"
                               class="size-9 flex items-center justify-center rounded-lg border text-sm font-semibold transition-all cursor-pointer
                               <?= $isActive ? 'bg-primary/10 border-primary/20 text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildUrl($page+1, $filterSearch, $filterStatus, $filterBulan, $filterCabang) ?>"
                               class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Print Footer (identik dengan laporan_stok.php) ── -->
            <div class="print-footer" style="margin-top:40px; font-size:8pt; color:#555;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div>
                        <p>Dokumen ini dicetak secara otomatis oleh sistem.</p>
                        <p style="margin-top:4px;">
                            Menunggu = belum diverifikasi &nbsp;|&nbsp;
                            Diterima = setoran valid &nbsp;|&nbsp;
                            Ditolak = setoran bermasalah
                        </p>
                    </div>
                    <div style="text-align:center; min-width:160px;">
                        <p>Admin Pusat, <?= date('d/m/Y') ?></p>
                        <div style="margin-top:50px; border-top:1px solid #333; padding-top:4px;">
                            Admin / Penanggung Jawab
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