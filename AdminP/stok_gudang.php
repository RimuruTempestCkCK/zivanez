<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'AdminP') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Data cabang ──────────────────────────────────────────
$listCabang = $conn->query("SELECT id, kode_cabang, nama_cabang FROM cabang ORDER BY kode_cabang ASC")
                   ->fetch_all(MYSQLI_ASSOC);

// ── Filter ───────────────────────────────────────────────
$search       = trim($_GET['search']      ?? '');
$filterCabang = (int)($_GET['cabang_id']  ?? 0);
$filterStok   = trim($_GET['stok_status'] ?? '');
$activeTab    = trim($_GET['tab']         ?? 'semua');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($filterCabang > 0) {
    $where .= " AND s.cabang_id = ?";
    $params[] = $filterCabang; $types .= "i";
}
if ($search !== '') {
    $where .= " AND (s.kode_stok LIKE ? OR s.nama_produk LIKE ? OR s.kategori LIKE ? OR c.nama_cabang LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "ssss";
}
if ($filterStok === 'habis')   { $where .= " AND s.stok_tersedia <= 0"; }
elseif ($filterStok === 'menipis') { $where .= " AND s.stok_tersedia > 0 AND s.stok_tersedia <= 10"; }
elseif ($filterStok === 'aman')    { $where .= " AND s.stok_tersedia > 10"; }

$cntStmt = $conn->prepare("SELECT COUNT(*) total FROM stok s JOIN cabang c ON c.id = s.cabang_id $where");
if ($params) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = $cntStmt->get_result()->fetch_assoc()['total'];
$cntStmt->close();

$pData = $params; $tData = $types;
$pData[] = $perPage; $pData[] = $offset; $tData .= "ii";
$dataStmt = $conn->prepare("
    SELECT s.*, c.nama_cabang, c.kode_cabang
    FROM stok s JOIN cabang c ON c.id = s.cabang_id
    $where ORDER BY c.kode_cabang ASC, s.created_at DESC LIMIT ? OFFSET ?
");
$dataStmt->bind_param($tData, ...$pData);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// ── Stats global ─────────────────────────────────────────
$statTotal   = (int)$conn->query("SELECT COUNT(*) FROM stok")->fetch_row()[0];
$statHabis   = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia <= 0")->fetch_row()[0];
$statMenipis = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia > 0 AND stok_tersedia <= 10")->fetch_row()[0];
$statNilai   = (int)$conn->query("SELECT COALESCE(SUM(stok_tersedia * harga_jual),0) FROM stok")->fetch_row()[0];

// ── Stats per cabang ──────────────────────────────────────
$cabangStats = $conn->query("
    SELECT c.id, c.kode_cabang, c.nama_cabang,
           COUNT(s.id) AS total_produk,
           COALESCE(SUM(s.stok_masuk),0)    AS total_masuk,
           COALESCE(SUM(s.stok_keluar),0)   AS total_keluar,
           COALESCE(SUM(s.stok_tersedia),0) AS total_tersedia,
           COALESCE(SUM(s.stok_tersedia * s.harga_jual),0) AS nilai_stok,
           SUM(CASE WHEN s.stok_tersedia <= 0 THEN 1 ELSE 0 END) AS habis,
           SUM(CASE WHEN s.stok_tersedia > 0 AND s.stok_tersedia <= 10 THEN 1 ELSE 0 END) AS menipis
    FROM cabang c LEFT JOIN stok s ON s.cabang_id = c.id
    GROUP BY c.id, c.kode_cabang, c.nama_cabang ORDER BY c.kode_cabang ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Produk per cabang ─────────────────────────────────────
$produkPerCabang = [];
foreach ($listCabang as $cab) {
    $cid  = $cab['id'];
    $stmt = $conn->prepare("SELECT s.*, c.nama_cabang, c.kode_cabang FROM stok s JOIN cabang c ON c.id = s.cabang_id WHERE s.cabang_id = ? ORDER BY s.nama_produk ASC");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $produkPerCabang[$cid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function rupiah($n) { return 'Rp ' . number_format($n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Gudang - Sanjai Zivanes</title>
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
            --primary:#165DFF; --primary-hover:#0E4BD9; --foreground:#080C1A;
            --secondary:#6A7686; --muted:#EFF2F7; --border:#F3F4F3;
            --success:#30B22D; --error:#ED6B60; --warning:#FED71F;
            --font-sans:'Lexend Deca',sans-serif;
        }
        @theme inline {
            --color-primary:var(--primary); --color-primary-hover:var(--primary-hover);
            --color-foreground:var(--foreground); --color-secondary:var(--secondary);
            --color-muted:var(--muted); --color-border:var(--border);
            --color-success:var(--success); --color-error:var(--error);
            --color-warning:var(--warning); --font-sans:var(--font-sans);
        }
        select { @apply appearance-none bg-no-repeat cursor-pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-position:right 10px center; padding-right:40px; }
        .scrollbar-hide::-webkit-scrollbar { display:none; }
        .scrollbar-hide { -ms-overflow-style:none; scrollbar-width:none; }
        .row-hover:hover { background-color:#F8FAFF; }
    </style>
</head>
<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

<?php include __DIR__ . '/../layout/sidebar.php'; ?>

<main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">

    <!-- ── HEADER ─────────────────────────────────────────── -->
    <div class="sticky top-0 z-30 flex items-center h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8 gap-4">
        <button onclick="toggleSidebar()" class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all cursor-pointer">
            <i data-lucide="menu" class="size-6"></i>
        </button>
        <div>
            <h2 class="font-bold text-2xl">Stok Gudang</h2>
            <p class="hidden sm:block text-sm text-secondary">Pantau stok produk gudang dan seluruh cabang</p>
        </div>
        <div class="ml-auto flex items-center gap-3">
            <span class="hidden md:block text-xs text-secondary">Update: <?= date('d M Y, H:i') ?></span>
            <a href="stok_gudang.php" class="size-10 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all" title="Refresh">
                <i data-lucide="refresh-cw" class="size-5 text-secondary"></i>
            </a>
        </div>
    </div>

    <div class="flex-1 p-5 md:p-8">

        <!-- ── RINGKASAN PER CABANG ────────────────────────── -->
        <div class="bg-white rounded-2xl border border-border overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center">
                    <i data-lucide="store" class="size-4 text-primary"></i>
                </div>
                <h3 class="font-bold text-base">Ringkasan Per Cabang</h3>
            </div>
            <div class="overflow-x-auto scrollbar-hide">
                <table class="w-full min-w-[820px]">
                    <thead>
                        <tr class="border-b border-border bg-muted/60">
                            <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                            <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Produk</th>
                            <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Masuk</th>
                            <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keluar</th>
                            <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                            <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Habis</th>
                            <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Menipis</th>
                            <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nilai Stok</th>
                            <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cabangStats as $cs): ?>
                        <tr class="row-hover border-b border-border transition-colors">
                            <td class="px-5 py-4">
                                <p class="font-semibold text-sm"><?= htmlspecialchars($cs['nama_cabang']) ?></p>
                                <p class="text-xs text-secondary font-mono"><?= htmlspecialchars($cs['kode_cabang']) ?></p>
                            </td>
                            <td class="px-5 py-4 text-center">
                                <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary/10 text-primary text-sm font-bold">
                                    <?= (int)$cs['total_produk'] ?>
                                </span>
                            </td>
                            <td class="px-5 py-4 text-center font-semibold text-sm text-success"><?= number_format($cs['total_masuk']) ?></td>
                            <td class="px-5 py-4 text-center font-semibold text-sm text-error"><?= number_format($cs['total_keluar']) ?></td>
                            <td class="px-5 py-4 text-center font-bold text-primary"><?= number_format($cs['total_tersedia']) ?></td>
                            <td class="px-5 py-4 text-center">
                                <?php if ($cs['habis'] > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-error/10 text-error text-xs font-bold">
                                        <span class="size-1.5 rounded-full bg-error mr-1.5"></span><?= $cs['habis'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary text-sm">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 text-center">
                                <?php if ($cs['menipis'] > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-warning/20 text-yellow-700 text-xs font-bold">
                                        <span class="size-1.5 rounded-full bg-warning mr-1.5"></span><?= $cs['menipis'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary text-sm">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 text-right font-bold text-sm text-success"><?= rupiah($cs['nilai_stok']) ?></td>
                            <td class="px-5 py-4 text-center">
                                <a href="?cabang_id=<?= $cs['id'] ?>&tab=semua"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border hover:ring-1 hover:ring-primary text-xs font-semibold text-secondary hover:text-primary transition-all">
                                    <i data-lucide="eye" class="size-3.5"></i> Detail
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── TABS ───────────────────────────────────────── -->
        <div class="bg-white rounded-2xl border border-border overflow-hidden">

            <!-- Tab Navigation -->
            <div class="flex border-b border-border bg-muted/60 px-5 pt-1 gap-1">
                <button onclick="switchTab('semua')" id="tab-btn-semua"
                    class="px-4 py-3 text-sm font-semibold transition-all cursor-pointer flex items-center gap-2 border-b-2
                    <?= $activeTab !== 'percabang' ? 'border-primary text-primary' : 'border-transparent text-secondary hover:text-foreground' ?>">
                    <i data-lucide="layout-list" class="size-4"></i> Semua Stok
                </button>
                <button onclick="switchTab('percabang')" id="tab-btn-percabang"
                    class="px-4 py-3 text-sm font-semibold transition-all cursor-pointer flex items-center gap-2 border-b-2
                    <?= $activeTab === 'percabang' ? 'border-primary text-primary' : 'border-transparent text-secondary hover:text-foreground' ?>">
                    <i data-lucide="building-2" class="size-4"></i> Per Cabang
                </button>
            </div>

            <!-- ══ TAB: SEMUA STOK ══════════════════════════ -->
            <div id="panel-semua" class="<?= $activeTab === 'percabang' ? 'hidden' : '' ?>">

                <!-- Filter -->
                <form method="GET" class="flex flex-col md:flex-row gap-3 p-5 border-b border-border">
                    <input type="hidden" name="tab" value="semua">
                    <div class="relative flex-1">
                        <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="Cari kode, produk, kategori, cabang..."
                            class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none transition-all">
                    </div>
                    <select name="cabang_id" onchange="this.form.submit()" class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                        <option value="0">Semua Cabang</option>
                        <?php foreach ($listCabang as $cab): ?>
                            <option value="<?= $cab['id'] ?>" <?= $filterCabang == $cab['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cab['nama_cabang']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="stok_status" onchange="this.form.submit()" class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                        <option value="">Semua Status</option>
                        <option value="aman"    <?= $filterStok==='aman'    ?'selected':'' ?>>Stok Aman</option>
                        <option value="menipis" <?= $filterStok==='menipis' ?'selected':'' ?>>Menipis (≤10)</option>
                        <option value="habis"   <?= $filterStok==='habis'   ?'selected':'' ?>>Habis</option>
                    </select>
                    <button type="submit" class="px-5 h-12 bg-primary hover:bg-primary-hover text-white rounded-xl font-semibold flex items-center gap-2 transition-all cursor-pointer whitespace-nowrap">
                        <i data-lucide="filter" class="size-4"></i> Filter
                    </button>
                    <?php if ($search || $filterCabang || $filterStok): ?>
                        <a href="?tab=semua" class="px-5 h-12 rounded-xl border border-border font-semibold text-secondary hover:bg-muted flex items-center gap-2 transition-all whitespace-nowrap">
                            <i data-lucide="x" class="size-4"></i> Reset
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Tabel -->
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1000px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kode Stok</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nama Produk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kategori</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Masuk</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keluar</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Harga Jual</th>
                                <th class="text-right px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nilai</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="10">
                                    <div class="py-16 flex flex-col items-center gap-3 text-center">
                                        <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                            <i data-lucide="package-search" class="size-8 text-secondary"></i>
                                        </div>
                                        <p class="font-semibold">Data tidak ditemukan</p>
                                        <p class="text-sm text-secondary">Coba ubah filter pencarian</p>
                                    </div>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r):
                                    $tersedia = (int)$r['stok_tersedia'];
                                    $pct = $r['stok_masuk'] > 0 ? min(100, round(($tersedia / $r['stok_masuk']) * 100)) : 0;
                                    $barColor = $pct <= 0 ? 'bg-error' : ($pct <= 20 ? 'bg-warning' : 'bg-success');
                                ?>
                                <tr class="row-hover border-b border-border transition-colors">
                                    <td class="px-5 py-4">
                                        <span class="inline-block bg-muted text-secondary text-xs font-mono px-2 py-1 rounded-lg"><?= htmlspecialchars($r['kode_stok']) ?></span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold"><?= htmlspecialchars($r['kode_cabang']) ?></span>
                                        <p class="text-xs text-secondary mt-1"><?= htmlspecialchars($r['nama_cabang']) ?></p>
                                    </td>
                                    <td class="px-5 py-4 font-semibold text-sm"><?= htmlspecialchars($r['nama_produk']) ?></td>
                                    <td class="px-5 py-4 text-secondary text-sm"><?= htmlspecialchars($r['kategori'] ?: '-') ?></td>
                                    <td class="px-5 py-4 text-center font-semibold text-sm text-success"><?= number_format($r['stok_masuk']) ?></td>
                                    <td class="px-5 py-4 text-center font-semibold text-sm text-error"><?= number_format($r['stok_keluar']) ?></td>
                                    <td class="px-5 py-4 text-center">
                                        <p class="font-bold text-sm"><?= number_format($tersedia) ?> <span class="text-xs text-secondary font-normal"><?= htmlspecialchars($r['satuan']) ?></span></p>
                                        <div class="w-20 mx-auto h-1.5 rounded-full bg-muted mt-1.5">
                                            <div class="h-1.5 rounded-full <?= $barColor ?>" style="width:<?= $pct ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-right font-mono text-sm text-secondary"><?= rupiah($r['harga_jual']) ?></td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="font-bold text-primary font-mono text-sm"><?= rupiah($tersedia * $r['harga_jual']) ?></span>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($tersedia <= 0): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-error/10 text-error text-xs font-bold">
                                                <span class="size-1.5 rounded-full bg-error mr-1.5"></span>Habis
                                            </span>
                                        <?php elseif ($tersedia <= 10): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-warning/20 text-yellow-700 text-xs font-bold">
                                                <span class="size-1.5 rounded-full bg-warning mr-1.5"></span>Menipis
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-success/10 text-success text-xs font-bold">
                                                <span class="size-1.5 rounded-full bg-success mr-1.5"></span>Aman
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination -->
                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3">
                    <p class="text-sm text-secondary">
                        Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                        dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> data
                    </p>
                    <?php if ($totalPages > 1):
                        $baseQ = http_build_query(array_filter(['search'=>$search,'cabang_id'=>$filterCabang,'stok_status'=>$filterStok,'tab'=>'semua']));
                    ?>
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?= $baseQ ?>&page=<?= $page-1 ?>" class="p-2 rounded-lg border border-border hover:ring-1 hover:ring-primary transition-all">
                                <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>
                        <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                            <a href="?<?= $baseQ ?>&page=<?= $i ?>" class="size-9 flex items-center justify-center rounded-lg border text-sm font-semibold transition-all
                                <?= $i==$page ? 'bg-primary/10 border-primary/20 text-primary' : 'border-border hover:bg-primary/10 hover:text-primary' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= $baseQ ?>&page=<?= $page+1 ?>" class="p-2 rounded-lg border border-border hover:ring-1 hover:ring-primary transition-all">
                                <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /panel-semua -->

            <!-- ══ TAB: PER CABANG (ACCORDION) ══════════════ -->
            <div id="panel-percabang" class="<?= $activeTab === 'percabang' ? '' : 'hidden' ?> p-5">

                <p class="text-sm text-secondary mb-4">Klik nama cabang untuk melihat detail stok produk.</p>

                <?php foreach ($cabangStats as $cs):
                    $produk = $produkPerCabang[$cs['id']] ?? [];
                ?>
                <div class="border border-border rounded-2xl overflow-hidden mb-3">

                    <!-- Accordion Header -->
                    <button onclick="toggleAccordion(<?= $cs['id'] ?>)"
                        class="w-full flex items-center justify-between px-5 py-4 hover:bg-muted/60 transition-colors cursor-pointer text-left">
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold">
                                <?= htmlspecialchars($cs['kode_cabang']) ?>
                            </span>
                            <span class="font-bold text-sm"><?= htmlspecialchars($cs['nama_cabang']) ?></span>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs bg-muted text-secondary px-2 py-1 rounded-lg">Produk: <b><?= $cs['total_produk'] ?></b></span>
                                <span class="text-xs bg-muted text-secondary px-2 py-1 rounded-lg">Tersedia: <b class="text-primary"><?= number_format($cs['total_tersedia']) ?></b></span>
                                <?php if ($cs['habis'] > 0): ?>
                                    <span class="text-xs bg-error/10 text-error px-2 py-1 rounded-lg font-semibold">Habis: <?= $cs['habis'] ?></span>
                                <?php endif; ?>
                                <?php if ($cs['menipis'] > 0): ?>
                                    <span class="text-xs bg-warning/20 text-yellow-700 px-2 py-1 rounded-lg font-semibold">Menipis: <?= $cs['menipis'] ?></span>
                                <?php endif; ?>
                                <span class="text-xs bg-success/10 text-success px-2 py-1 rounded-lg font-semibold"><?= rupiah($cs['nilai_stok']) ?></span>
                            </div>
                        </div>
                        <i data-lucide="chevron-down" class="size-5 text-secondary shrink-0 transition-transform duration-300" id="chv-<?= $cs['id'] ?>"></i>
                    </button>

                    <!-- Accordion Body -->
                    <div id="acc-<?= $cs['id'] ?>" class="hidden border-t border-border">
                        <?php if (empty($produk)): ?>
                            <div class="py-10 flex flex-col items-center gap-2 text-center">
                                <i data-lucide="package-search" class="size-8 text-secondary"></i>
                                <p class="text-sm text-secondary">Belum ada stok untuk cabang ini</p>
                            </div>
                        <?php else: ?>
                        <div class="overflow-x-auto scrollbar-hide">
                            <table class="w-full min-w-[800px]">
                                <thead>
                                    <tr class="border-b border-border bg-muted/40">
                                        <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Kode Stok</th>
                                        <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Nama Produk</th>
                                        <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Kategori</th>
                                        <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Masuk</th>
                                        <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Keluar</th>
                                        <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                                        <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Harga Jual</th>
                                        <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Nilai Stok</th>
                                        <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($produk as $p):
                                        $t = (int)$p['stok_tersedia'];
                                    ?>
                                    <tr class="row-hover border-b border-border transition-colors">
                                        <td class="px-5 py-3">
                                            <span class="inline-block bg-muted text-secondary text-xs font-mono px-2 py-1 rounded-lg"><?= htmlspecialchars($p['kode_stok']) ?></span>
                                        </td>
                                        <td class="px-5 py-3 font-semibold text-sm"><?= htmlspecialchars($p['nama_produk']) ?></td>
                                        <td class="px-5 py-3 text-secondary text-sm"><?= htmlspecialchars($p['kategori'] ?: '-') ?></td>
                                        <td class="px-5 py-3 text-center font-semibold text-sm text-success"><?= number_format($p['stok_masuk']) ?></td>
                                        <td class="px-5 py-3 text-center font-semibold text-sm text-error"><?= number_format($p['stok_keluar']) ?></td>
                                        <td class="px-5 py-3 text-center font-bold text-sm"><?= number_format($t) ?> <span class="text-xs text-secondary font-normal"><?= htmlspecialchars($p['satuan']) ?></span></td>
                                        <td class="px-5 py-3 text-right font-mono text-sm text-secondary"><?= rupiah($p['harga_jual']) ?></td>
                                        <td class="px-5 py-3 text-right">
                                            <span class="font-bold text-primary font-mono text-sm"><?= rupiah($t * $p['harga_jual']) ?></span>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <?php if ($t <= 0): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-error/10 text-error text-xs font-bold">
                                                    <span class="size-1.5 rounded-full bg-error mr-1.5"></span>Habis
                                                </span>
                                            <?php elseif ($t <= 10): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-warning/20 text-yellow-700 text-xs font-bold">
                                                    <span class="size-1.5 rounded-full bg-warning mr-1.5"></span>Menipis
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-success/10 text-success text-xs font-bold">
                                                    <span class="size-1.5 rounded-full bg-success mr-1.5"></span>Aman
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-muted/40 border-t-2 border-border">
                                        <td colspan="7" class="px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wide">Total Nilai Cabang</td>
                                        <td class="px-5 py-3 text-right font-bold text-primary"><?= rupiah($cs['nilai_stok']) ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
                <?php endforeach; ?>

            </div><!-- /panel-percabang -->

        </div><!-- /.tabs -->

    </div>
</main>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (!sidebar) return;
    if (sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        overlay?.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    } else {
        sidebar.classList.add('-translate-x-full');
        overlay?.classList.add('hidden');
        document.body.style.overflow = '';
    }
}

function switchTab(tab) {
    ['semua','percabang'].forEach(t => {
        const btn   = document.getElementById('tab-btn-' + t);
        const panel = document.getElementById('panel-' + t);
        const active = t === tab;

        panel.classList.toggle('hidden', !active);

        // reset classes
        btn.className = btn.className
            .replace(/border-primary\s*/g,'')
            .replace(/text-primary\s*/g,'')
            .replace(/border-transparent\s*/g,'')
            .replace(/text-secondary\s*/g,'')
            .replace(/hover:text-foreground\s*/g,'');

        if (active) {
            btn.classList.add('border-primary','text-primary');
        } else {
            btn.classList.add('border-transparent','text-secondary','hover:text-foreground');
        }
    });

    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

function toggleAccordion(id) {
    const body = document.getElementById('acc-' + id);
    const chv  = document.getElementById('chv-' + id);
    const isOpen = !body.classList.contains('hidden');
    body.classList.toggle('hidden', isOpen);
    chv.style.transform = isOpen ? '' : 'rotate(180deg)';
}

document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
});
</script>
<script src="../layout/index.js"></script>
</body>
</html>