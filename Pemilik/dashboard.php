<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] != 'Pemilik') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Stats dari database ───────────────────────────────────

// Cabang
$totalCabang = (int)$conn->query("SELECT COUNT(*) FROM cabang")->fetch_row()[0];
$cabangAktif = (int)$conn->query("SELECT COUNT(*) FROM cabang WHERE status = 'Aktif'")->fetch_row()[0];

// Karyawan
$totalKaryawan = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'Karyawan'")->fetch_row()[0];

// Stok
$totalProduk = (int)$conn->query("SELECT COUNT(*) FROM stok")->fetch_row()[0];
$stokHabis   = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia <= 0")->fetch_row()[0];
$stokMenipis = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia > 0 AND stok_tersedia <= 10")->fetch_row()[0];
$nilaiStok   = (int)$conn->query("SELECT COALESCE(SUM(stok_tersedia * harga_jual),0) FROM stok")->fetch_row()[0];

// Transaksi
$totalTrx      = (int)$conn->query("SELECT COUNT(*) FROM transaksi")->fetch_row()[0];
$trxHariIni    = (int)$conn->query("SELECT COUNT(*) FROM transaksi WHERE tanggal = CURDATE()")->fetch_row()[0];
$omsetBulanIni = (int)$conn->query("SELECT COALESCE(SUM(total),0) FROM transaksi WHERE MONTH(tanggal)=MONTH(NOW()) AND YEAR(tanggal)=YEAR(NOW()) AND status='Selesai'")->fetch_row()[0];
$omsetHariIni  = (int)$conn->query("SELECT COALESCE(SUM(total),0) FROM transaksi WHERE tanggal=CURDATE() AND status='Selesai'")->fetch_row()[0];

// Setoran
$setoranMenunggu = (int)$conn->query("SELECT COUNT(*) FROM setoran WHERE status = 'Menunggu'")->fetch_row()[0];
$totalSetoran    = (int)$conn->query("SELECT COALESCE(SUM(jumlah_setoran),0) FROM setoran WHERE status='Diterima' AND MONTH(tanggal)=MONTH(NOW()) AND YEAR(tanggal)=YEAR(NOW())")->fetch_row()[0];

// Penggajian
$gajiMenunggu   = (int)$conn->query("SELECT COUNT(*) FROM penggajian WHERE status != 'Sudah Dibayar'")->fetch_row()[0];
$totalGajiBulan = (int)$conn->query("SELECT COALESCE(SUM(total_gaji),0) FROM penggajian WHERE bulan = DATE_FORMAT(NOW(),'%M') AND tahun = YEAR(NOW())")->fetch_row()[0];

// 5 Transaksi terbaru
$recentTrx = $conn->query("
    SELECT t.*, c.nama_cabang
    FROM transaksi t JOIN cabang c ON c.id = t.cabang_id
    ORDER BY t.created_at DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// 5 Setoran terbaru
$recentSetoran = $conn->query("
    SELECT s.*, c.nama_cabang
    FROM setoran s JOIN cabang c ON c.id = s.cabang_id
    ORDER BY s.created_at DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Omset per cabang (untuk ringkasan)
$omsetPerCabang = $conn->query("
    SELECT c.nama_cabang, c.kode_cabang,
           COALESCE(SUM(CASE WHEN t.status='Selesai' AND MONTH(t.tanggal)=MONTH(NOW()) AND YEAR(t.tanggal)=YEAR(NOW()) THEN t.total ELSE 0 END), 0) AS omset_bulan,
           COUNT(t.id) AS total_trx
    FROM cabang c
    LEFT JOIN transaksi t ON t.cabang_id = c.id
    GROUP BY c.id
    ORDER BY omset_bulan DESC
")->fetch_all(MYSQLI_ASSOC);

function rupiah($n)
{
    return 'Rp ' . number_format($n, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sanjai Zivanez - Dashboard Pemilik</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend+Deca:wght@100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"
        onload="window.lucideLoaded=true; if(window.initLucide) window.initLucide();"></script>
    <script>
        window.initLucide = function() {
            if (window.lucide) lucide.createIcons();
        };
        document.addEventListener('DOMContentLoaded', function() {
            if (window.lucideLoaded) window.initLucide();
        });
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
                    <p class="hidden sm:block text-sm text-secondary">Selamat datang, Pemilik — <?= date('d M Y') ?></p>
                </div>
            </div>
        </div>

        <div class="flex-1 p-5 md:p-8">

            <!-- ── ROW 1: Omset & Transaksi ── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">

                <!-- Omset Bulan Ini -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="trending-up" class="size-6 text-primary"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Omset Bulan Ini</p>
                        <p class="text-lg font-bold text-foreground truncate"><?= rupiah($omsetBulanIni) ?></p>
                        <p class="text-xs text-secondary">Transaksi Selesai</p>
                    </div>
                </div>

                <!-- Omset Hari Ini -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                        <i data-lucide="banknote" class="size-6 text-success"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Omset Hari Ini</p>
                        <p class="text-lg font-bold text-foreground truncate"><?= rupiah($omsetHariIni) ?></p>
                        <p class="text-xs text-secondary"><?= $trxHariIni ?> transaksi hari ini</p>
                    </div>
                </div>

                <!-- Total Transaksi -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-warning/20 flex items-center justify-center shrink-0">
                        <i data-lucide="receipt" class="size-6 text-yellow-600"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Transaksi</p>
                        <p class="text-2xl font-bold text-foreground"><?= number_format($totalTrx) ?></p>
                        <p class="text-xs text-secondary">semua cabang</p>
                    </div>
                </div>

                <!-- Setoran Bulan Ini -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                        <i data-lucide="arrow-down-to-line" class="size-6 text-success"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Setoran Bulan Ini</p>
                        <p class="text-lg font-bold text-foreground truncate"><?= rupiah($totalSetoran) ?></p>
                        <p class="text-xs <?= $setoranMenunggu > 0 ? 'text-error font-semibold' : 'text-secondary' ?>">
                            <?= $setoranMenunggu ?> menunggu verifikasi
                        </p>
                    </div>
                </div>

            </div>

            <!-- ── ROW 2: Stok & Cabang ── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">

                <!-- Total Produk -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="package" class="size-6 text-primary"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Produk</p>
                        <p class="text-2xl font-bold text-foreground"><?= $totalProduk ?></p>
                        <p class="text-xs text-secondary">seluruh cabang</p>
                    </div>
                </div>

                <!-- Nilai Stok -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                        <i data-lucide="boxes" class="size-6 text-success"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Nilai Stok</p>
                        <p class="text-lg font-bold text-foreground truncate"><?= rupiah($nilaiStok) ?></p>
                        <p class="text-xs text-secondary">estimasi total</p>
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

                <!-- Cabang Aktif -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="store" class="size-6 text-primary"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Cabang Aktif</p>
                        <p class="text-2xl font-bold text-foreground"><?= $cabangAktif ?></p>
                        <p class="text-xs text-secondary">dari <?= $totalCabang ?> total cabang</p>
                    </div>
                </div>

            </div>

            <!-- ── ROW 3: SDM & Penggajian ── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">

                <!-- Karyawan -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="users" class="size-6 text-primary"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Karyawan</p>
                        <p class="text-2xl font-bold text-foreground"><?= $totalKaryawan ?></p>
                        <p class="text-xs text-secondary">terdaftar di sistem</p>
                    </div>
                </div>

                <!-- Penggajian -->
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl <?= $gajiMenunggu > 0 ? 'bg-warning/20' : 'bg-success/10' ?> flex items-center justify-center shrink-0">
                        <i data-lucide="wallet" class="size-6 <?= $gajiMenunggu > 0 ? 'text-yellow-600' : 'text-success' ?>"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Gaji Belum Dibayar</p>
                        <p class="text-2xl font-bold <?= $gajiMenunggu > 0 ? 'text-yellow-600' : 'text-success' ?>"><?= $gajiMenunggu ?></p>
                        <p class="text-xs text-secondary truncate">Total bulan ini: <?= rupiah($totalGajiBulan) ?></p>
                    </div>
                </div>

            </div>

            <!-- ── ROW 4: Tabel Terbaru & Omset per Cabang ── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">

                <!-- Transaksi Terbaru -->
                <div class="bg-white rounded-2xl border border-border overflow-hidden">
                    <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center">
                                <i data-lucide="receipt" class="size-4 text-primary"></i>
                            </div>
                            <h3 class="font-bold text-sm">Transaksi Terbaru</h3>
                        </div>
                    </div>
                    <div class="overflow-x-auto scrollbar-hide">
                        <table class="w-full min-w-[400px]">
                            <thead>
                                <tr class="border-b border-border bg-muted/60">
                                    <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">No. Transaksi</th>
                                    <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                    <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Total</th>
                                    <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentTrx)): ?>
                                    <tr>
                                        <td colspan="4" class="px-5 py-8 text-center text-sm text-secondary">Belum ada transaksi</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentTrx as $t): ?>
                                        <tr class="row-hover border-b border-border transition-colors">
                                            <td class="px-5 py-3">
                                                <p class="text-xs font-mono font-semibold"><?= htmlspecialchars($t['no_transaksi']) ?></p>
                                                <p class="text-xs text-secondary"><?= htmlspecialchars($t['nama_pelanggan']) ?></p>
                                            </td>
                                            <td class="px-5 py-3 text-xs text-secondary"><?= htmlspecialchars($t['nama_cabang']) ?></td>
                                            <td class="px-5 py-3 text-right text-xs font-bold text-primary"><?= rupiah($t['total']) ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <?php
                                                if ($t['status'] === 'Selesai')        $sc = 'bg-success/10 text-success';
                                                elseif ($t['status'] === 'Proses')     $sc = 'bg-primary/10 text-primary';
                                                elseif ($t['status'] === 'Dibatalkan') $sc = 'bg-error/10 text-error';
                                                else                                   $sc = 'bg-warning/20 text-yellow-700';
                                                ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $sc ?>">
                                                    <?= htmlspecialchars($t['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Setoran Terbaru -->
                <div class="bg-white rounded-2xl border border-border overflow-hidden">
                    <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="size-8 rounded-lg bg-success/10 flex items-center justify-center">
                                <i data-lucide="arrow-down-to-line" class="size-4 text-success"></i>
                            </div>
                            <h3 class="font-bold text-sm">Setoran Terbaru</h3>
                        </div>
                    </div>
                    <div class="overflow-x-auto scrollbar-hide">
                        <table class="w-full min-w-[400px]">
                            <thead>
                                <tr class="border-b border-border bg-muted/60">
                                    <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">No. Setoran</th>
                                    <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                    <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Jumlah</th>
                                    <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentSetoran)): ?>
                                    <tr>
                                        <td colspan="4" class="px-5 py-8 text-center text-sm text-secondary">Belum ada setoran</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentSetoran as $s): ?>
                                        <tr class="row-hover border-b border-border transition-colors">
                                            <td class="px-5 py-3">
                                                <p class="text-xs font-mono font-semibold"><?= htmlspecialchars($s['no_setoran']) ?></p>
                                                <p class="text-xs text-secondary"><?= date('d M Y', strtotime($s['tanggal'])) ?></p>
                                            </td>
                                            <td class="px-5 py-3 text-xs text-secondary"><?= htmlspecialchars($s['nama_cabang']) ?></td>
                                            <td class="px-5 py-3 text-right text-xs font-bold text-success"><?= rupiah($s['jumlah_setoran']) ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <?php
                                                if ($s['status'] === 'Diterima')    $ss = 'bg-success/10 text-success';
                                                elseif ($s['status'] === 'Ditolak') $ss = 'bg-error/10 text-error';
                                                else                                $ss = 'bg-warning/20 text-yellow-700';
                                                ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $ss ?>">
                                                    <?= htmlspecialchars($s['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ── ROW 5: Omset per Cabang ── -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center">
                        <i data-lucide="store" class="size-4 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-sm">Omset Bulan Ini per Cabang</h3>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[500px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Total Transaksi</th>
                                <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Omset Bulan Ini</th>
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider w-48">Persentase</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($omsetPerCabang)): ?>
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-sm text-secondary">Belum ada data</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($omsetPerCabang as $oc):
                                    $pct = $omsetBulanIni > 0 ? round(($oc['omset_bulan'] / $omsetBulanIni) * 100) : 0;
                                ?>
                                    <tr class="row-hover border-b border-border transition-colors">
                                        <td class="px-5 py-3">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-secondary/10 text-secondary text-xs font-bold font-mono">
                                                    <?= htmlspecialchars($oc['kode_cabang']) ?>
                                                </span>
                                                <span class="text-sm font-semibold text-foreground"><?= htmlspecialchars($oc['nama_cabang']) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-5 py-3 text-center text-sm text-secondary"><?= number_format($oc['total_trx']) ?></td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-primary"><?= rupiah($oc['omset_bulan']) ?></td>
                                        <td class="px-5 py-3">
                                            <div class="flex items-center gap-2">
                                                <div class="flex-1 h-2 rounded-full bg-muted overflow-hidden">
                                                    <div class="h-full rounded-full bg-primary transition-all" style="width:<?= $pct ?>%"></div>
                                                </div>
                                                <span class="text-xs font-semibold text-secondary w-8 text-right"><?= $pct ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($omsetPerCabang)): ?>
                            <tfoot>
                                <tr class="bg-muted/60 border-t-2 border-primary">
                                    <td class="px-5 py-3 text-sm font-bold text-foreground">Total</td>
                                    <td class="px-5 py-3 text-center text-sm font-bold text-foreground"><?= number_format($totalTrx) ?></td>
                                    <td class="px-5 py-3 text-right text-sm font-bold text-primary"><?= rupiah($omsetBulanIni) ?></td>
                                    <td class="px-5 py-3"></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

        </div><!-- /p-5 md:p-8 -->
    </main>

    <script src="../layout/index.js"></script>
</body>

</html>