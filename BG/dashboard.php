<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] != 'BG') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Stats Stok (semua cabang) ─────────────────────────────
$totalProduk  = (int)$conn->query("SELECT COUNT(*) FROM stok")->fetch_row()[0];
$totalCabang  = (int)$conn->query("SELECT COUNT(*) FROM cabang WHERE status = 'Aktif'")->fetch_row()[0];
$stokHabis    = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia <= 0")->fetch_row()[0];
$stokMenipis  = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia > 0 AND stok_tersedia <= 10")->fetch_row()[0];
$stokAman     = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia > 10")->fetch_row()[0];
$nilaiStok    = (int)$conn->query("SELECT COALESCE(SUM(stok_tersedia * harga_jual),0) FROM stok")->fetch_row()[0];
$nilaiModal   = (int)$conn->query("SELECT COALESCE(SUM(stok_tersedia * harga_beli),0) FROM stok")->fetch_row()[0];
$totalMasuk   = (int)$conn->query("SELECT COALESCE(SUM(stok_masuk),0) FROM stok")->fetch_row()[0];
$totalKeluar  = (int)$conn->query("SELECT COALESCE(SUM(stok_keluar),0) FROM stok")->fetch_row()[0];

// ── Stok kritis (habis & menipis) ────────────────────────
$stokKritis = $conn->query("
    SELECT s.*, c.nama_cabang, c.kode_cabang
    FROM stok s JOIN cabang c ON c.id = s.cabang_id
    WHERE s.stok_tersedia <= 10
    ORDER BY s.stok_tersedia ASC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// ── Stok terbaru diupdate ────────────────────────────────
$stokTerbaru = $conn->query("
    SELECT s.*, c.nama_cabang, c.kode_cabang
    FROM stok s JOIN cabang c ON c.id = s.cabang_id
    ORDER BY s.updated_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Ringkasan stok per cabang ────────────────────────────
$stokPerCabang = $conn->query("
    SELECT c.id, c.kode_cabang, c.nama_cabang,
           COUNT(s.id) AS total_produk,
           COALESCE(SUM(s.stok_masuk),0)    AS total_masuk,
           COALESCE(SUM(s.stok_keluar),0)   AS total_keluar,
           COALESCE(SUM(s.stok_tersedia),0) AS total_tersedia,
           COALESCE(SUM(s.stok_tersedia * s.harga_jual),0) AS nilai_stok,
           SUM(CASE WHEN s.stok_tersedia <= 0 THEN 1 ELSE 0 END) AS habis,
           SUM(CASE WHEN s.stok_tersedia > 0 AND s.stok_tersedia <= 10 THEN 1 ELSE 0 END) AS menipis
    FROM cabang c LEFT JOIN stok s ON s.cabang_id = c.id
    GROUP BY c.id, c.kode_cabang, c.nama_cabang
    ORDER BY c.kode_cabang ASC
")->fetch_all(MYSQLI_ASSOC);

function rupiah($n) { return 'Rp ' . number_format($n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sanjai Zivanes - Dashboard BG</title>
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
            --card-grey:#F1F3F6; --success:#30B22D; --success-light:#DCFCE7;
            --error:#ED6B60; --error-light:#FEE2E2;
            --warning:#FED71F; --warning-light:#FEF9C3;
            --font-sans:'Lexend Deca',sans-serif;
        }
        @theme inline {
            --color-primary:var(--primary); --color-primary-hover:var(--primary-hover);
            --color-foreground:var(--foreground); --color-secondary:var(--secondary);
            --color-muted:var(--muted); --color-border:var(--border);
            --color-card-grey:var(--card-grey); --color-success:var(--success);
            --color-success-light:var(--success-light); --color-error:var(--error);
            --color-error-light:var(--error-light); --color-warning:var(--warning);
            --color-warning-light:var(--warning-light); --font-sans:var(--font-sans);
            --radius-card:24px; --radius-button:50px;
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

    <!-- Header -->
    <div class="sticky top-0 z-30 flex items-center justify-between w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8">
        <div class="flex items-center gap-4">
            <button onclick="toggleSidebar()" class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all cursor-pointer">
                <i data-lucide="menu" class="size-6"></i>
            </button>
            <div>
                <h2 class="font-bold text-2xl">Dashboard</h2>
                <p class="hidden sm:block text-sm text-secondary">Selamat datang, Bagian Gudang — <?= date('d M Y') ?></p>
            </div>
        </div>
    </div>

    <div class="flex-1 p-5 md:p-8">

        <!-- ── ROW 1: Ringkasan Stok ─────────────────────────── -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">

            <!-- Total Produk -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                    <i data-lucide="package" class="size-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Produk</p>
                    <p class="text-2xl font-bold text-foreground"><?= $totalProduk ?></p>
                    <p class="text-xs text-secondary"><?= $totalCabang ?> cabang aktif</p>
                </div>
            </div>

            <!-- Nilai Stok -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                    <i data-lucide="trending-up" class="size-6 text-success"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Nilai Stok</p>
                    <p class="text-lg font-bold text-foreground truncate"><?= rupiah($nilaiStok) ?></p>
                    <p class="text-xs text-secondary">estimasi harga jual</p>
                </div>
            </div>

            <!-- Stok Kritis -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl <?= $stokHabis > 0 ? 'bg-error/10' : 'bg-warning/20' ?> flex items-center justify-center shrink-0">
                    <i data-lucide="package-x" class="size-6 <?= $stokHabis > 0 ? 'text-error' : 'text-yellow-600' ?>"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Stok Kritis</p>
                    <p class="text-2xl font-bold <?= $stokHabis > 0 ? 'text-error' : 'text-yellow-600' ?>"><?= $stokHabis + $stokMenipis ?></p>
                    <p class="text-xs text-secondary"><?= $stokHabis ?> habis · <?= $stokMenipis ?> menipis</p>
                </div>
            </div>

            <!-- Stok Aman -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                    <i data-lucide="package-check" class="size-6 text-success"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Stok Aman</p>
                    <p class="text-2xl font-bold text-success"><?= $stokAman ?></p>
                    <p class="text-xs text-secondary">produk tersedia cukup</p>
                </div>
            </div>

        </div>

        <!-- ── ROW 2: Pergerakan Stok ────────────────────────── -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">

            <!-- Total Masuk -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                    <i data-lucide="package-plus" class="size-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Masuk</p>
                    <p class="text-2xl font-bold text-foreground"><?= number_format($totalMasuk) ?></p>
                    <p class="text-xs text-secondary">unit seluruh produk</p>
                </div>
            </div>

            <!-- Total Keluar -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-error/10 flex items-center justify-center shrink-0">
                    <i data-lucide="package-minus" class="size-6 text-error"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Keluar</p>
                    <p class="text-2xl font-bold text-error"><?= number_format($totalKeluar) ?></p>
                    <p class="text-xs text-secondary">unit terjual/terpakai</p>
                </div>
            </div>

            <!-- Sisa Tersedia -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                    <i data-lucide="boxes" class="size-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Sisa Tersedia</p>
                    <p class="text-2xl font-bold text-foreground"><?= number_format($totalMasuk - $totalKeluar) ?></p>
                    <p class="text-xs text-secondary">unit di semua cabang</p>
                </div>
            </div>

            <!-- Nilai Modal -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-warning/20 flex items-center justify-center shrink-0">
                    <i data-lucide="wallet" class="size-6 text-yellow-600"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Nilai Modal</p>
                    <p class="text-lg font-bold text-foreground truncate"><?= rupiah($nilaiModal) ?></p>
                    <p class="text-xs text-secondary">estimasi harga beli</p>
                </div>
            </div>

        </div>

        <!-- ── ROW 3: Tabel ──────────────────────────────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">

            <!-- Stok Kritis -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden">
                <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="size-8 rounded-lg bg-error/10 flex items-center justify-center">
                            <i data-lucide="package-x" class="size-4 text-error"></i>
                        </div>
                        <h3 class="font-bold text-sm">Stok Kritis</h3>
                        <?php if ($stokHabis + $stokMenipis > 0): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-error/10 text-error text-xs font-bold">
                            <?= $stokHabis + $stokMenipis ?> produk
                        </span>
                        <?php endif; ?>
                    </div>
                    <a href="kelola_stok.php" class="text-xs font-semibold text-primary hover:underline">Kelola Stok</a>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[420px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Produk</th>
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stokKritis)): ?>
                                <tr><td colspan="4">
                                    <div class="py-10 flex flex-col items-center gap-2 text-center">
                                        <i data-lucide="package-check" class="size-8 text-success"></i>
                                        <p class="text-sm font-semibold text-success">Semua stok aman</p>
                                        <p class="text-xs text-secondary">Tidak ada produk yang kritis</p>
                                    </div>
                                </td></tr>
                            <?php else: ?>
                            <?php foreach ($stokKritis as $sk): $t = (int)$sk['stok_tersedia']; ?>
                            <tr class="row-hover border-b border-border transition-colors">
                                <td class="px-5 py-3">
                                    <p class="text-sm font-semibold"><?= htmlspecialchars($sk['nama_produk']) ?></p>
                                    <p class="text-xs text-secondary font-mono"><?= htmlspecialchars($sk['kode_stok']) ?></p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-primary/10 text-primary text-xs font-bold">
                                        <?= htmlspecialchars($sk['kode_cabang']) ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-center font-bold <?= $t <= 0 ? 'text-error' : 'text-yellow-600' ?>">
                                    <?= $t ?> <span class="text-xs font-normal text-secondary"><?= htmlspecialchars($sk['satuan']) ?></span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <?php if ($t <= 0): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-error/10 text-error text-xs font-bold">
                                            <span class="size-1.5 rounded-full bg-error mr-1.5"></span>Habis
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-warning/20 text-yellow-700 text-xs font-bold">
                                            <span class="size-1.5 rounded-full bg-warning mr-1.5"></span>Menipis
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Stok Terbaru Diupdate -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden">
                <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center">
                            <i data-lucide="clock" class="size-4 text-primary"></i>
                        </div>
                        <h3 class="font-bold text-sm">Update Stok Terbaru</h3>
                    </div>
                    <a href="kelola_stok.php" class="text-xs font-semibold text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[420px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Produk</th>
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Masuk</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stokTerbaru)): ?>
                                <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-secondary">Belum ada data stok</td></tr>
                            <?php else: ?>
                            <?php foreach ($stokTerbaru as $st): $t = (int)$st['stok_tersedia']; ?>
                            <tr class="row-hover border-b border-border transition-colors">
                                <td class="px-5 py-3">
                                    <p class="text-sm font-semibold"><?= htmlspecialchars($st['nama_produk']) ?></p>
                                    <p class="text-xs text-secondary"><?= htmlspecialchars($st['kategori'] ?: '-') ?></p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-primary/10 text-primary text-xs font-bold">
                                        <?= htmlspecialchars($st['kode_cabang']) ?>
                                    </span>
                                    <p class="text-xs text-secondary mt-0.5"><?= htmlspecialchars($st['nama_cabang']) ?></p>
                                </td>
                                <td class="px-5 py-3 text-center font-semibold text-sm text-success"><?= number_format($st['stok_masuk']) ?></td>
                                <td class="px-5 py-3 text-center font-bold text-sm <?= $t <= 0 ? 'text-error' : ($t <= 10 ? 'text-yellow-600' : 'text-foreground') ?>">
                                    <?= number_format($t) ?> <span class="text-xs font-normal text-secondary"><?= htmlspecialchars($st['satuan']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- ── ROW 4: Ringkasan Per Cabang ───────────────────── -->
        <div class="bg-white rounded-2xl border border-border overflow-hidden">
            <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center">
                    <i data-lucide="store" class="size-4 text-primary"></i>
                </div>
                <h3 class="font-bold text-sm">Stok Per Cabang</h3>
            </div>
            <div class="overflow-x-auto scrollbar-hide">
                <table class="w-full min-w-[700px]">
                    <thead>
                        <tr class="border-b border-border bg-muted/60">
                            <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Produk</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Total Masuk</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Total Keluar</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Habis</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Menipis</th>
                            <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Nilai Stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stokPerCabang as $sc): ?>
                        <tr class="row-hover border-b border-border transition-colors">
                            <td class="px-5 py-4">
                                <p class="font-semibold text-sm"><?= htmlspecialchars($sc['nama_cabang']) ?></p>
                                <p class="text-xs text-secondary font-mono"><?= htmlspecialchars($sc['kode_cabang']) ?></p>
                            </td>
                            <td class="px-5 py-4 text-center">
                                <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary/10 text-primary text-sm font-bold">
                                    <?= (int)$sc['total_produk'] ?>
                                </span>
                            </td>
                            <td class="px-5 py-4 text-center font-semibold text-sm text-success"><?= number_format($sc['total_masuk']) ?></td>
                            <td class="px-5 py-4 text-center font-semibold text-sm text-error"><?= number_format($sc['total_keluar']) ?></td>
                            <td class="px-5 py-4 text-center font-bold text-primary"><?= number_format($sc['total_tersedia']) ?></td>
                            <td class="px-5 py-4 text-center">
                                <?php if ($sc['habis'] > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-error/10 text-error text-xs font-bold">
                                        <span class="size-1.5 rounded-full bg-error mr-1.5"></span><?= $sc['habis'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary text-sm">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 text-center">
                                <?php if ($sc['menipis'] > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-warning/20 text-yellow-700 text-xs font-bold">
                                        <span class="size-1.5 rounded-full bg-warning mr-1.5"></span><?= $sc['menipis'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary text-sm">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 text-right font-bold text-sm text-success"><?= rupiah($sc['nilai_stok']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /p-5 md:p-8 -->
</main>

<script src="../layout/index.js"></script>
</body>
</html>