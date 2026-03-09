<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] != 'AdminC') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// Ambil user ID dari session (coba berbagai kemungkinan key)
$userId = 0;
if (!empty($_SESSION['user_id']))              $userId = (int)$_SESSION['user_id'];
elseif (!empty($_SESSION['id']))               $userId = (int)$_SESSION['id'];
elseif (!empty($_SESSION['user']['id']))      $userId = (int)$_SESSION['user']['id'];

// Fallback: cari dari username jika ID masih 0
if ($userId === 0) {
    $uname = '';
    if (!empty($_SESSION['username']))          $uname = $_SESSION['username'];
    elseif (!empty($_SESSION['user']) && is_string($_SESSION['user'])) $uname = $_SESSION['user'];
    if ($uname !== '') {
        $stmtU = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmtU->bind_param("s", $uname);
        $stmtU->execute();
        $rowU = $stmtU->get_result()->fetch_assoc();
        $stmtU->close();
        if ($rowU) $userId = (int)$rowU['id'];
    }
}

$cabang   = null;
$cabangId = 0;

$stmtC = $conn->prepare("SELECT id, kode_cabang, nama_cabang, alamat, telepon, status FROM cabang WHERE admin_id = ? LIMIT 1");
$stmtC->bind_param("i", $userId);
$stmtC->execute();
$cabang = $stmtC->get_result()->fetch_assoc();
$stmtC->close();

if ($cabang) {
    $cabangId = (int)$cabang['id'];
}

// ── Stats Transaksi (cabang ini) ─────────────────────────
$stmtTrx = $conn->prepare("SELECT COUNT(*) FROM transaksi WHERE cabang_id = ?");
$stmtTrx->bind_param("i", $cabangId); $stmtTrx->execute();
$totalTrx = (int)$stmtTrx->get_result()->fetch_row()[0]; $stmtTrx->close();

$stmtTH = $conn->prepare("SELECT COUNT(*) FROM transaksi WHERE cabang_id = ? AND tanggal = CURDATE()");
$stmtTH->bind_param("i", $cabangId); $stmtTH->execute();
$trxHariIni = (int)$stmtTH->get_result()->fetch_row()[0]; $stmtTH->close();

$stmtOM = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM transaksi WHERE cabang_id = ? AND MONTH(tanggal)=MONTH(NOW()) AND YEAR(tanggal)=YEAR(NOW()) AND status='Selesai'");
$stmtOM->bind_param("i", $cabangId); $stmtOM->execute();
$omsetBulanIni = (int)$stmtOM->get_result()->fetch_row()[0]; $stmtOM->close();

$stmtOH = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM transaksi WHERE cabang_id = ? AND tanggal = CURDATE() AND status='Selesai'");
$stmtOH->bind_param("i", $cabangId); $stmtOH->execute();
$omsetHariIni = (int)$stmtOH->get_result()->fetch_row()[0]; $stmtOH->close();

// ── Stats Stok (cabang ini) ──────────────────────────────
$stmtSP = $conn->prepare("SELECT COUNT(*) FROM stok WHERE cabang_id = ?");
$stmtSP->bind_param("i", $cabangId); $stmtSP->execute();
$totalProduk = (int)$stmtSP->get_result()->fetch_row()[0]; $stmtSP->close();

$stmtSH = $conn->prepare("SELECT COUNT(*) FROM stok WHERE cabang_id = ? AND stok_tersedia <= 0");
$stmtSH->bind_param("i", $cabangId); $stmtSH->execute();
$stokHabis = (int)$stmtSH->get_result()->fetch_row()[0]; $stmtSH->close();

$stmtSM = $conn->prepare("SELECT COUNT(*) FROM stok WHERE cabang_id = ? AND stok_tersedia > 0 AND stok_tersedia <= 10");
$stmtSM->bind_param("i", $cabangId); $stmtSM->execute();
$stokMenipis = (int)$stmtSM->get_result()->fetch_row()[0]; $stmtSM->close();

$stmtNS = $conn->prepare("SELECT COALESCE(SUM(stok_tersedia * harga_jual),0) FROM stok WHERE cabang_id = ?");
$stmtNS->bind_param("i", $cabangId); $stmtNS->execute();
$nilaiStok = (int)$stmtNS->get_result()->fetch_row()[0]; $stmtNS->close();

// ── Stats Setoran (cabang ini) ───────────────────────────
$stmtSW = $conn->prepare("SELECT COUNT(*) FROM setoran WHERE cabang_id = ? AND status = 'Menunggu'");
$stmtSW->bind_param("i", $cabangId); $stmtSW->execute();
$setoranMenunggu = (int)$stmtSW->get_result()->fetch_row()[0]; $stmtSW->close();

$stmtSD = $conn->prepare("SELECT COALESCE(SUM(jumlah_setoran),0) FROM setoran WHERE cabang_id = ? AND status='Diterima' AND MONTH(tanggal)=MONTH(NOW()) AND YEAR(tanggal)=YEAR(NOW())");
$stmtSD->bind_param("i", $cabangId); $stmtSD->execute();
$totalSetoran = (int)$stmtSD->get_result()->fetch_row()[0]; $stmtSD->close();

// ── 5 Transaksi terbaru (cabang ini) ────────────────────
$stmtRT = $conn->prepare("
    SELECT t.*, c.nama_cabang
    FROM transaksi t JOIN cabang c ON c.id = t.cabang_id
    WHERE t.cabang_id = ?
    ORDER BY t.created_at DESC LIMIT 5
");
$stmtRT->bind_param("i", $cabangId); $stmtRT->execute();
$recentTrx = $stmtRT->get_result()->fetch_all(MYSQLI_ASSOC); $stmtRT->close();

// ── 5 Setoran terbaru (cabang ini) ──────────────────────
$stmtRS = $conn->prepare("
    SELECT s.*, c.nama_cabang
    FROM setoran s JOIN cabang c ON c.id = s.cabang_id
    WHERE s.cabang_id = ?
    ORDER BY s.created_at DESC LIMIT 5
");
$stmtRS->bind_param("i", $cabangId); $stmtRS->execute();
$recentSetoran = $stmtRS->get_result()->fetch_all(MYSQLI_ASSOC); $stmtRS->close();

// ── 5 Stok kritis (cabang ini) ───────────────────────────
$stmtSK = $conn->prepare("
    SELECT * FROM stok
    WHERE cabang_id = ? AND stok_tersedia <= 10
    ORDER BY stok_tersedia ASC LIMIT 5
");
$stmtSK->bind_param("i", $cabangId); $stmtSK->execute();
$stokKritis = $stmtSK->get_result()->fetch_all(MYSQLI_ASSOC); $stmtSK->close();

function rupiah($n) { return 'Rp ' . number_format($n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sanjai Zivanes - Dashboard</title>
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
                <p class="hidden sm:block text-sm text-secondary">
                    <?php if ($cabang): ?>
                        <?= htmlspecialchars($cabang['nama_cabang']) ?> (<?= htmlspecialchars($cabang['kode_cabang']) ?>) — <?= date('d M Y') ?>
                    <?php else: ?>
                        Selamat datang, Admin Cabang — <?= date('d M Y') ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <div class="flex-1 p-5 md:p-8">

        <?php if (!$cabang): ?>
        <!-- Peringatan jika cabang tidak ditemukan -->
        <div class="flex items-center gap-4 p-5 rounded-2xl bg-warning/10 border border-warning/30 mb-6">
            <div class="size-10 rounded-xl bg-warning/20 flex items-center justify-center shrink-0">
                <i data-lucide="alert-triangle" class="size-5 text-yellow-600"></i>
            </div>
            <div>
                <p class="font-semibold text-sm">Cabang belum ditentukan</p>
                <p class="text-xs text-secondary mt-0.5">Akun Anda belum terhubung ke cabang manapun. Hubungi Admin Pusat.</p>
            </div>
        </div>
        <?php else: ?>

        <!-- ── INFO CABANG ────────────────────────────────── -->
        <div class="flex items-center gap-4 p-5 rounded-2xl bg-primary/5 border border-primary/20 mb-6">
            <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                <i data-lucide="store" class="size-6 text-primary"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <p class="font-bold text-base"><?= htmlspecialchars($cabang['nama_cabang']) ?></p>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-primary/10 text-primary text-xs font-bold">
                        <?= htmlspecialchars($cabang['kode_cabang']) ?>
                    </span>
                    <?php if ($cabang['status'] === 'Aktif'): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-success/10 text-success text-xs font-bold">
                            <span class="size-1.5 rounded-full bg-success mr-1.5"></span>Aktif
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-error/10 text-error text-xs font-bold">
                            <span class="size-1.5 rounded-full bg-error mr-1.5"></span>Nonaktif
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-secondary mt-1 truncate">
                    <?= $cabang['alamat'] ? htmlspecialchars($cabang['alamat']) : '-' ?>
                    <?= $cabang['telepon'] ? ' · ' . htmlspecialchars($cabang['telepon']) : '' ?>
                </p>
            </div>
        </div>

        <?php endif; ?>

        <!-- ── ROW 1: Omset & Transaksi ─────────────────────── -->
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
                    <p class="text-xs text-secondary">cabang ini</p>
                </div>
            </div>

            <!-- Setoran Menunggu -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl <?= $setoranMenunggu > 0 ? 'bg-error/10' : 'bg-muted' ?> flex items-center justify-center shrink-0">
                    <i data-lucide="clock" class="size-6 <?= $setoranMenunggu > 0 ? 'text-error' : 'text-secondary' ?>"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Setoran Menunggu</p>
                    <p class="text-2xl font-bold <?= $setoranMenunggu > 0 ? 'text-error' : 'text-foreground' ?>"><?= $setoranMenunggu ?></p>
                    <p class="text-xs text-secondary">belum diverifikasi</p>
                </div>
            </div>

        </div>

        <!-- ── ROW 2: Stok ───────────────────────────────────── -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">

            <!-- Total Produk -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                    <i data-lucide="package" class="size-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Produk</p>
                    <p class="text-2xl font-bold text-foreground"><?= $totalProduk ?></p>
                    <p class="text-xs text-secondary">di cabang ini</p>
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

            <!-- Setoran Diterima Bulan Ini -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                    <i data-lucide="arrow-down-to-line" class="size-6 text-success"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Setoran Bulan Ini</p>
                    <p class="text-lg font-bold text-foreground truncate"><?= rupiah($totalSetoran) ?></p>
                    <p class="text-xs text-secondary">sudah diterima</p>
                </div>
            </div>

        </div>

        <!-- ── ROW 3: Tabel Terbaru ──────────────────────────── -->
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
                    <a href="kelola_transaksi.php" class="text-xs font-semibold text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[400px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">No. Transaksi</th>
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Pelanggan</th>
                                <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Total</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTrx)): ?>
                                <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-secondary">Belum ada transaksi</td></tr>
                            <?php else: ?>
                            <?php foreach ($recentTrx as $t): ?>
                            <tr class="row-hover border-b border-border transition-colors">
                                <td class="px-5 py-3">
                                    <p class="text-xs font-mono font-semibold"><?= htmlspecialchars($t['no_transaksi']) ?></p>
                                    <p class="text-xs text-secondary"><?= date('d M Y', strtotime($t['tanggal'])) ?></p>
                                </td>
                                <td class="px-5 py-3 text-xs text-secondary"><?= htmlspecialchars($t['nama_pelanggan']) ?></td>
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
                    <a href="setoran.php" class="text-xs font-semibold text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[400px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">No. Setoran</th>
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Jumlah</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSetoran)): ?>
                                <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-secondary">Belum ada setoran</td></tr>
                            <?php else: ?>
                            <?php foreach ($recentSetoran as $s): ?>
                            <tr class="row-hover border-b border-border transition-colors">
                                <td class="px-5 py-3">
                                    <p class="text-xs font-mono font-semibold"><?= htmlspecialchars($s['no_setoran']) ?></p>
                                </td>
                                <td class="px-5 py-3 text-xs text-secondary"><?= date('d M Y', strtotime($s['tanggal'])) ?></td>
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

        <!-- ── ROW 4: Stok Kritis ────────────────────────────── -->
        <?php if (!empty($stokKritis)): ?>
        <div class="bg-white rounded-2xl border border-border overflow-hidden">
            <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="size-8 rounded-lg bg-error/10 flex items-center justify-center">
                        <i data-lucide="package-x" class="size-4 text-error"></i>
                    </div>
                    <h3 class="font-bold text-sm">Stok Kritis</h3>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-error/10 text-error text-xs font-bold">
                        <?= count($stokKritis) ?> produk
                    </span>
                </div>
                <a href="stok.php" class="text-xs font-semibold text-primary hover:underline">Lihat Semua</a>
            </div>
            <div class="overflow-x-auto scrollbar-hide">
                <table class="w-full min-w-[500px]">
                    <thead>
                        <tr class="border-b border-border bg-muted/60">
                            <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Kode Stok</th>
                            <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Nama Produk</th>
                            <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Kategori</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                            <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stokKritis as $sk): $t = (int)$sk['stok_tersedia']; ?>
                        <tr class="row-hover border-b border-border transition-colors">
                            <td class="px-5 py-3">
                                <span class="inline-block bg-muted text-secondary text-xs font-mono px-2 py-1 rounded-lg"><?= htmlspecialchars($sk['kode_stok']) ?></span>
                            </td>
                            <td class="px-5 py-3 font-semibold text-sm"><?= htmlspecialchars($sk['nama_produk']) ?></td>
                            <td class="px-5 py-3 text-xs text-secondary"><?= htmlspecialchars($sk['kategori'] ?: '-') ?></td>
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
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /p-5 md:p-8 -->
</main>

<script src="../layout/index.js"></script>
</body>
</html>