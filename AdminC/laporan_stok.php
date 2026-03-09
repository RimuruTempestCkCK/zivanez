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
$cabangId = $cabangSaya['id'] ?? 0;

// ── Filter Parameters ─────────────────────────────────────
$filterKategori  = $_GET['kategori']  ?? '';
$filterNama      = trim($_GET['nama'] ?? '');
$filterStokMin   = $_GET['stok_min']  ?? '';   // '' = tidak difilter
$filterStokMaks  = $_GET['stok_maks'] ?? '';

// Ambil daftar kategori yang tersedia
$kategoriList = [];
if ($cabangId > 0) {
    $kStmt = $conn->prepare("SELECT DISTINCT kategori FROM stok WHERE cabang_id = ? AND kategori IS NOT NULL ORDER BY kategori ASC");
    $kStmt->bind_param("i", $cabangId);
    $kStmt->execute();
    $kRes = $kStmt->get_result();
    while ($kr = $kRes->fetch_assoc()) $kategoriList[] = $kr['kategori'];
    $kStmt->close();
}

// ── Build WHERE ───────────────────────────────────────────
$whereConditions = ["cabang_id = ?"];
$params          = [$cabangId];
$paramTypes      = "i";

if ($filterKategori !== '') {
    $whereConditions[] = "kategori = ?";
    $params[]    = $filterKategori;
    $paramTypes .= "s";
}
if ($filterNama !== '') {
    $whereConditions[] = "nama_produk LIKE ?";
    $params[]    = "%{$filterNama}%";
    $paramTypes .= "s";
}
if ($filterStokMin !== '' && is_numeric($filterStokMin)) {
    $whereConditions[] = "stok_tersedia >= ?";
    $params[]    = (int)$filterStokMin;
    $paramTypes .= "i";
}
if ($filterStokMaks !== '' && is_numeric($filterStokMaks)) {
    $whereConditions[] = "stok_tersedia <= ?";
    $params[]    = (int)$filterStokMaks;
    $paramTypes .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

// ── Pagination ────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Count
$cntS = $conn->prepare("SELECT COUNT(*) total FROM stok WHERE $whereClause");
$cntS->bind_param($paramTypes, ...$params);
$cntS->execute();
$totalRows = $cntS->get_result()->fetch_assoc()['total'];
$cntS->close();

// Fetch
$paramsWithLimit  = array_merge($params, [$perPage, $offset]);
$paramTypesLimit  = $paramTypes . "ii";

$dStmt = $conn->prepare("
    SELECT id, kode_stok, nama_produk, kategori, satuan,
           stok_masuk, stok_keluar, stok_tersedia,
           harga_beli, harga_jual, keterangan, updated_at
    FROM stok
    WHERE $whereClause
    ORDER BY nama_produk ASC
    LIMIT ? OFFSET ?
");
$dStmt->bind_param($paramTypesLimit, ...$paramsWithLimit);
$dStmt->execute();
$rows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dStmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// ── Helper URL builder ────────────────────────────────────
function buildUrl($p, $tglDari='', $tglSampai='', $kat='', $nama='', $sMin='', $sMaks='') {
    $url = "?page=$p";
    if ($kat)    $url .= "&kategori="  . urlencode($kat);
    if ($nama)   $url .= "&nama="      . urlencode($nama);
    if ($sMin !== '') $url .= "&stok_min="  . urlencode($sMin);
    if ($sMaks !== '') $url .= "&stok_maks=" . urlencode($sMaks);
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

        /* ── Badge stok ── */
        .badge-habis   { @apply inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-error-light text-error text-xs font-bold; }
        .badge-menipis { @apply inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-warning-light text-yellow-700 text-xs font-bold; }
        .badge-aman    { @apply inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-success-light text-success text-xs font-bold; }

        /* ── Print ── */
        @media print {
            .no-print { display: none !important; }
            body { background: white; }

            /* Print header */
            .print-header { display: block !important; }

            /* Tabel cetak */
            table { width: 100%; border-collapse: collapse; font-size: 8pt; }
            thead tr { background: #1a1a2e !important; color: #fff !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead th { padding: 6px 8px; border: 1px solid #0d0d1a; font-size: 7.5pt; }
            tbody td { padding: 5px 8px; border: 1px solid #ddd; font-size: 7.5pt; color: #222; }
            tbody tr:nth-child(even) td { background: #f9f9f9 !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact; }

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
                    <h2 class="font-bold text-2xl text-foreground">Laporan Stok</h2>
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

            <!-- ── Filter ── -->
            <div class="bg-white rounded-2xl border border-border p-6 mb-6 no-print">
                <div class="flex items-center gap-3 mb-4">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="filter" class="size-5 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-lg text-foreground">Filter Laporan</h3>
                </div>

                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <!-- Nama Produk -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-secondary mb-2">Nama Produk</label>
                        <input type="text" name="nama" value="<?= htmlspecialchars($filterNama) ?>"
                            placeholder="Cari nama produk..."
                            class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>

                    <!-- Kategori -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Kategori</label>
                        <select name="kategori" class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($kategoriList as $kat): ?>
                                <option value="<?= htmlspecialchars($kat) ?>" <?= $filterKategori === $kat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Stok Min -->
                    <div>
                        <label class="block text-sm font-medium text-secondary mb-2">Stok Minimal</label>
                        <input type="number" name="stok_min" min="0" value="<?= htmlspecialchars($filterStokMin) ?>"
                            placeholder="0"
                            class="w-full h-12 px-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>

                    <!-- Tombol -->
                    <div class="flex items-end gap-2">
                        <button type="submit" class="flex-1 h-12 bg-primary hover:bg-primary-hover text-white rounded-xl font-semibold flex items-center justify-center gap-2 transition-all">
                            <i data-lucide="search" class="size-4"></i>
                            <span>Filter</span>
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
                <h1 style="font-size:16pt;font-weight:700;margin:0 0 4px;">LAPORAN STOK BARANG</h1>
                <p style="font-size:10pt;font-weight:600;margin:2px 0;">
                    <?= htmlspecialchars($cabangSaya['nama_cabang'] ?? '') ?>
                    <?php if (!empty($cabangSaya['kode_cabang'])): ?>
                        (<?= htmlspecialchars($cabangSaya['kode_cabang']) ?>)
                    <?php endif; ?>
                </p>
                <p style="font-size:8.5pt;color:#555;margin:2px 0;">
                    <?php
                    $parts = [];
                    if ($filterKategori) $parts[] = "Kategori: " . htmlspecialchars($filterKategori);
                    if ($filterNama)     $parts[] = "Produk: " . htmlspecialchars($filterNama);
                    echo $parts ? implode(' | ', $parts) : 'Semua Produk';
                    ?>
                    &nbsp;|&nbsp; Dicetak: <?= date('d/m/Y H:i') ?>
                    &nbsp;|&nbsp; Total: <?= number_format($totalRows) ?> produk
                </p>
            </div>

            <!-- ── Tabel Laporan ── -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1050px]" id="stokTable">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kode Stok</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nama Produk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kategori</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Satuan</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Stok Masuk</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Stok Keluar</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Stok Tersedia</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Harga Jual</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="py-16 flex flex-col items-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="package-x" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Tidak ada data stok</p>
                                            <p class="text-sm text-secondary">Silakan sesuaikan filter untuk melihat laporan stok.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                $no = $offset + 1;
                                foreach ($rows as $r):
                                    $stokTersedia = (int)$r['stok_tersedia'];
                                    if ($stokTersedia <= 0) {
                                        $badgeClass = 'badge-habis';
                                        $badgeLabel = 'Habis';
                                        $badgeIcon  = 'x-circle';
                                    } elseif ($stokTersedia <= 10) {
                                        $badgeClass = 'badge-menipis';
                                        $badgeLabel = 'Menipis';
                                        $badgeIcon  = 'alert-circle';
                                    } else {
                                        $badgeClass = 'badge-aman';
                                        $badgeLabel = 'Aman';
                                        $badgeIcon  = 'check-circle';
                                    }
                            ?>
                                <tr class="table-row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4 text-sm text-secondary"><?= $no++ ?></td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-muted text-secondary text-xs font-bold font-mono">
                                            <?= htmlspecialchars($r['kode_stok']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_produk']) ?></p>
                                        <?php if (!empty($r['keterangan'])): ?>
                                            <p class="text-xs text-secondary mt-0.5"><?= htmlspecialchars($r['keterangan']) ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if (!empty($r['kategori'])): ?>
                                            <span class="inline-flex px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-semibold">
                                                <?= htmlspecialchars($r['kategori']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-secondary text-sm">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-secondary"><?= htmlspecialchars($r['satuan']) ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-mono text-secondary">
                                        <?= number_format($r['stok_masuk'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-5 py-4 text-right text-sm font-mono text-secondary">
                                        <?= number_format($r['stok_keluar'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-foreground text-sm font-mono <?= $stokTersedia <= 0 ? 'text-error' : ($stokTersedia <= 10 ? 'text-yellow-600' : '') ?>">
                                            <?= number_format($stokTersedia, 0, ',', '.') ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-foreground text-sm font-mono">
                                            Rp <?= number_format($r['harga_jual'], 0, ',', '.') ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="<?= $badgeClass ?>">
                                            <i data-lucide="<?= $badgeIcon ?>" class="size-3"></i>
                                            <?= $badgeLabel ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
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
                            <a href="<?= buildUrl($page-1, '', '', $filterKategori, $filterNama, $filterStokMin, $filterStokMaks) ?>"
                               class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++):
                            $isActive = $i === $page;
                        ?>
                            <a href="<?= buildUrl($i, '', '', $filterKategori, $filterNama, $filterStokMin, $filterStokMaks) ?>"
                               class="size-9 flex items-center justify-center rounded-lg border text-sm font-semibold transition-all cursor-pointer
                               <?= $isActive ? 'bg-primary/10 border-primary/20 text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildUrl($page+1, '', '', $filterKategori, $filterNama, $filterStokMin, $filterStokMaks) ?>"
                               class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Print Footer ── -->
            <div class="print-footer" style="margin-top:40px; font-size:8pt; color:#555;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div>
                        <p>Keterangan status stok:</p>
                        <p style="margin-top:4px;">Aman = &gt; 10 &nbsp;|&nbsp; Menipis = 1–10 &nbsp;|&nbsp; Habis = 0</p>
                    </div>
                    <div style="text-align:center; min-width:160px;">
                        <p><?= htmlspecialchars($cabangSaya['nama_cabang'] ?? '') ?>, <?= date('d/m/Y') ?></p>
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