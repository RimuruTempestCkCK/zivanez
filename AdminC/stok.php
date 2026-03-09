<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'AdminC') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Resolve admin ID ──────────────────────────────────────
$adminId = (int)(
    $_SESSION['user_id'] ??
    $_SESSION['id']      ??
    $_SESSION['userId']  ??
    0
);

if ($adminId === 0 && !empty($_SESSION['username'])) {
    $uStmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $uStmt->bind_param("s", $_SESSION['username']);
    $uStmt->execute();
    $uRow    = $uStmt->get_result()->fetch_assoc();
    $adminId = (int)($uRow['id'] ?? 0);
    $uStmt->close();
    if ($adminId > 0) $_SESSION['user_id'] = $adminId;
}

// ── Cabang milik admin ini ────────────────────────────────
$cabangSaya = null;
if ($adminId > 0) {
    $cStmt = $conn->prepare("SELECT id, kode_cabang, nama_cabang, alamat FROM cabang WHERE admin_id = ? LIMIT 1");
    $cStmt->bind_param("i", $adminId);
    $cStmt->execute();
    $cabangSaya = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();
}
$cabangId = $cabangSaya['id'] ?? 0;

/* ===================== FETCH DATA ===================== */
$search      = trim($_GET['search']      ?? '');
$filterStok  = trim($_GET['stok_status'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 10;
$offset      = ($page - 1) * $perPage;

$where  = "WHERE s.cabang_id = ?";
$params = [$cabangId];
$types  = "i";

if ($search !== '') {
    $where   .= " AND (s.kode_stok LIKE ? OR s.nama_produk LIKE ? OR s.kategori LIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($filterStok === 'habis') {
    $where .= " AND s.stok_tersedia <= 0";
} elseif ($filterStok === 'menipis') {
    $where .= " AND s.stok_tersedia > 0 AND s.stok_tersedia <= 10";
} elseif ($filterStok === 'aman') {
    $where .= " AND s.stok_tersedia > 10";
}

// total rows
$cntStmt = $conn->prepare("SELECT COUNT(*) FROM stok s $where");
if ($params) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

// data rows
$sql = "SELECT s.* FROM stok s $where ORDER BY s.nama_produk ASC LIMIT ? OFFSET ?";
$dataStmt = $conn->prepare($sql);
$params[] = $perPage; $params[] = $offset;
$types   .= "ii";
$dataStmt->bind_param($types, ...$params);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// ── Stats hanya untuk cabang ini ─────────────────────────
$statTotal   = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM stok WHERE cabang_id=$cabangId")->fetch_row()[0] : 0;
$statAman    = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM stok WHERE cabang_id=$cabangId AND stok_tersedia > 10")->fetch_row()[0] : 0;
$statMenipis = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM stok WHERE cabang_id=$cabangId AND stok_tersedia > 0 AND stok_tersedia <= 10")->fetch_row()[0] : 0;
$statHabis   = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM stok WHERE cabang_id=$cabangId AND stok_tersedia <= 0")->fetch_row()[0] : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Cabang - Sanjai Zivanes</title>
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
            --radius-card: 24px; --radius-button: 50px;
        }
        select { @apply appearance-none bg-no-repeat cursor-pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-position: right 10px center; padding-right: 40px; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .table-row-hover:hover { background-color: #F8FAFF; }
    </style>
</head>

<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">

        <!-- Top Header -->
        <div class="sticky top-0 z-30 flex items-center justify-between w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" aria-label="Open menu"
                    class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all duration-300 cursor-pointer">
                    <i data-lucide="menu" class="size-6 text-foreground"></i>
                </button>
                <div>
                    <h2 class="font-bold text-2xl text-foreground">Stok Barang</h2>
                    <p class="hidden sm:block text-sm text-secondary">
                        <?php if ($cabangSaya): ?>
                            <span class="font-semibold text-primary"><?= htmlspecialchars($cabangSaya['nama_cabang']) ?></span>
                            &mdash; <?= htmlspecialchars($cabangSaya['kode_cabang']) ?>
                        <?php else: ?>
                            Anda belum ditugaskan ke cabang manapun
                        <?php endif; ?>
                    </p>
                </div>
            </div>

        </div>

        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <?php if (!$cabangSaya): ?>
            <!-- No Branch Warning -->
            <div class="mb-6 flex items-center gap-4 p-5 rounded-2xl bg-warning/20 border border-warning/40">
                <div class="size-10 rounded-xl bg-warning/30 flex items-center justify-center shrink-0">
                    <i data-lucide="alert-triangle" class="size-5 text-yellow-700"></i>
                </div>
                <div>
                    <p class="font-semibold text-yellow-800">Belum terhubung ke cabang</p>
                    <p class="text-sm text-yellow-700">Hubungi Admin Pusat untuk menugaskan Anda ke cabang.</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($cabangSaya && $statHabis > 0): ?>
            <!-- Stok Habis Alert -->
            <div class="mb-6 flex items-center gap-4 p-5 rounded-2xl bg-error/10 border border-error/30">
                <div class="size-10 rounded-xl bg-error/20 flex items-center justify-center shrink-0">
                    <i data-lucide="package-x" class="size-5 text-error"></i>
                </div>
                <div class="flex-1">
                    <p class="font-semibold text-error">Perhatian: <?= $statHabis ?> produk stoknya habis!</p>
                    <p class="text-sm text-error/80">Segera hubungi Bagian Gudang untuk pengisian stok.</p>
                </div>
                <a href="?stok_status=habis"
                    class="shrink-0 px-4 py-2 rounded-full bg-error text-white text-xs font-bold hover:bg-red-500 transition-colors cursor-pointer">
                    Lihat
                </a>
            </div>
            <?php endif; ?>

            <!-- Toolbar -->
            <form method="GET" id="filterForm">
                <div class="flex flex-col sm:flex-row gap-3 items-center mb-6">
                    <!-- Search -->
                    <div class="relative flex-1 group w-full">
                        <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary group-focus-within:text-primary transition-colors"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="Cari kode / nama produk / kategori..."
                            class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300">
                    </div>
                    <!-- Filter Kondisi -->
                    <select name="stok_status" id="stokStatusSelect" onchange="this.form.submit()"
                        class="h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none min-w-[150px]">
                        <option value="">Semua Kondisi</option>
                        <option value="aman"    <?= $filterStok==='aman'    ? 'selected':'' ?>>Stok Aman</option>
                        <option value="menipis" <?= $filterStok==='menipis' ? 'selected':'' ?>>Menipis (≤10)</option>
                        <option value="habis"   <?= $filterStok==='habis'   ? 'selected':'' ?>>Habis</option>
                    </select>
                    <!-- Search Button -->
                    <button type="submit"
                        class="h-12 px-6 bg-primary text-white rounded-xl font-semibold text-sm hover:bg-primary-hover transition-colors cursor-pointer">
                        Cari
                    </button>
                    <?php if ($search !== '' || $filterStok !== ''): ?>
                    <a href="stok.php"
                        class="h-12 px-5 rounded-xl border border-border text-secondary font-semibold text-sm flex items-center gap-2 hover:bg-muted transition-colors">
                        <i data-lucide="x" class="size-4"></i> Reset
                    </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Table -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[900px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kode Stok</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nama Produk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kategori</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Satuan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Masuk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keluar</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Harga Jual</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kondisi</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Diperbarui</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="py-16 flex flex-col items-center justify-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="package-search" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">
                                                <?= $cabangId === 0 ? 'Anda belum terhubung ke cabang' : 'Belum ada data stok' ?>
                                            </p>
                                            <p class="text-sm text-secondary">
                                                <?= $cabangId === 0 ? 'Hubungi Admin Pusat.' : 'Stok akan muncul setelah Bagian Gudang menginputkan data.' ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r):
                                    $tersedia = (int)$r['stok_tersedia'];
                                    if ($tersedia <= 0) {
                                        $sBg = 'bg-error/10';   $sTx = 'text-error';      $sLabel = 'Habis';
                                        $barW= 'w-0';           $barC = 'bg-error';
                                    } elseif ($tersedia <= 10) {
                                        $sBg = 'bg-warning/20'; $sTx = 'text-yellow-700'; $sLabel = 'Menipis';
                                        $barW= 'w-1/4';         $barC = 'bg-warning';
                                    } else {
                                        $sBg = 'bg-success/10'; $sTx = 'text-success';    $sLabel = 'Aman';
                                        $barPct = min(100, (int)(($tersedia / max(1,(int)$r['stok_masuk'])) * 100));
                                        $barW = 'w-3/4'; $barC = 'bg-success';
                                        if ($barPct >= 75) $barW = 'w-3/4';
                                        elseif ($barPct >= 40) $barW = 'w-1/2';
                                        else $barW = 'w-1/4';
                                    }
                                ?>
                                <tr class="table-row-hover border-b border-border transition-colors duration-150">
                                    <!-- Kode -->
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                            <?= htmlspecialchars($r['kode_stok']) ?>
                                        </span>
                                    </td>
                                    <!-- Nama Produk -->
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_produk']) ?></p>
                                        <?php if ($r['keterangan']): ?>
                                            <p class="text-xs text-secondary truncate max-w-[160px]" title="<?= htmlspecialchars($r['keterangan']) ?>">
                                                <?= htmlspecialchars($r['keterangan']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Kategori -->
                                    <td class="px-5 py-4">
                                        <?php if ($r['kategori']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-muted text-secondary text-xs font-medium">
                                                <?= htmlspecialchars($r['kategori']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-secondary text-sm">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Satuan -->
                                    <td class="px-5 py-4 text-secondary text-sm font-medium">
                                        <?= htmlspecialchars($r['satuan']) ?>
                                    </td>
                                    <!-- Masuk -->
                                    <td class="px-5 py-4 text-sm font-mono font-medium text-foreground">
                                        <?= number_format($r['stok_masuk'], 0, ',', '.') ?>
                                    </td>
                                    <!-- Keluar -->
                                    <td class="px-5 py-4 text-sm font-mono font-medium text-error">
                                        <?= number_format($r['stok_keluar'], 0, ',', '.') ?>
                                    </td>
                                    <!-- Tersedia + mini bar -->
                                    <td class="px-5 py-4">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-sm font-bold font-mono <?= $sTx ?>">
                                                <?= number_format($tersedia, 0, ',', '.') ?>
                                                <span class="text-xs font-normal text-secondary"><?= htmlspecialchars($r['satuan']) ?></span>
                                            </span>
                                            <div class="w-16 h-1.5 rounded-full bg-muted overflow-hidden">
                                                <div class="h-full rounded-full <?= $barW ?> <?= $barC ?> transition-all"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- Harga Jual -->
                                    <td class="px-5 py-4 text-sm font-mono text-foreground">
                                        Rp <?= number_format($r['harga_jual'], 0, ',', '.') ?>
                                    </td>
                                    <!-- Kondisi -->
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full <?= $sBg ?> <?= $sTx ?> text-xs font-bold">
                                            <?php if ($tersedia <= 0): ?>
                                                <i data-lucide="x-circle" class="size-3"></i>
                                            <?php elseif ($tersedia <= 10): ?>
                                                <i data-lucide="alert-triangle" class="size-3"></i>
                                            <?php else: ?>
                                                <i data-lucide="check-circle" class="size-3"></i>
                                            <?php endif; ?>
                                            <?= $sLabel ?>
                                        </span>
                                    </td>
                                    <!-- Diperbarui -->
                                    <td class="px-5 py-4">
                                        <p class="text-xs text-secondary font-medium">
                                            <?= date('d/m/Y', strtotime($r['updated_at'])) ?>
                                        </p>
                                        <p class="text-xs text-secondary/70">
                                            <?= date('H:i', strtotime($r['updated_at'])) ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer -->
                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3">
                    <div class="flex items-center gap-3">
                        <p class="text-sm text-secondary">
                            Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                            dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> produk
                        </p>
                        <!-- Read-only notice -->
                        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-semibold">
                            <i data-lucide="lock" class="size-3"></i>
                            Dikelola oleh Bagian Gudang
                        </span>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&stok_status=<?= urlencode($filterStok) ?>"
                                    class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&stok_status=<?= urlencode($filterStok) ?>"
                                    class="size-9 flex items-center justify-center rounded-lg border <?= $i==$page ? 'bg-primary/10 border-primary/20 font-semibold text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?> text-sm transition-all cursor-pointer">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&stok_status=<?= urlencode($filterStok) ?>"
                                    class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() { lucide.createIcons(); });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (!sidebar) return;
            sidebar.classList.contains('-translate-x-full')
                ? (sidebar.classList.remove('-translate-x-full'), overlay?.classList.remove('hidden'), document.body.style.overflow = 'hidden')
                : (sidebar.classList.add('-translate-x-full'),    overlay?.classList.add('hidden'),    document.body.style.overflow = '');
        }

        // Klik stat card langsung filter
        function filterByStatus(val) {
            document.getElementById('stokStatusSelect').value = val;
            document.getElementById('filterForm').submit();
        }
    </script>
    <script src="../layout/index.js"></script>
</body>
</html>