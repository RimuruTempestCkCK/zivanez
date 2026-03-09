<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] != 'Karyawan') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// Ambil user ID dari session (fallback berlapis)
$userId = 0;
if (!empty($_SESSION['user_id']))             $userId = (int)$_SESSION['user_id'];
elseif (!empty($_SESSION['id']))              $userId = (int)$_SESSION['id'];
elseif (!empty($_SESSION['user']['id']))      $userId = (int)$_SESSION['user']['id'];

// Fallback: cari dari username
if ($userId === 0) {
    $uname = '';
    if (!empty($_SESSION['username']))        $uname = $_SESSION['username'];
    elseif (!empty($_SESSION['user']) && is_string($_SESSION['user'])) $uname = $_SESSION['user'];
    if ($uname !== '') {
        $stmtU = $conn->prepare("SELECT id, nama_lengkap, nik, jabatan FROM users WHERE username = ? LIMIT 1");
        $stmtU->bind_param("s", $uname);
        $stmtU->execute();
        $rowU = $stmtU->get_result()->fetch_assoc();
        $stmtU->close();
        if ($rowU) $userId = (int)$rowU['id'];
    }
}

// Ambil data user lengkap
$userInfo = null;
if ($userId > 0) {
    $stmtUI = $conn->prepare("SELECT id, username, nama_lengkap, nik, jabatan FROM users WHERE id = ? LIMIT 1");
    $stmtUI->bind_param("i", $userId);
    $stmtUI->execute();
    $userInfo = $stmtUI->get_result()->fetch_assoc();
    $stmtUI->close();
}

$nikUser     = $userInfo['nik']          ?? '';
$namaUser    = $userInfo['nama_lengkap'] ?? ($userInfo['username'] ?? 'Karyawan');
$jabatanUser = $userInfo['jabatan']      ?? '-';

// ── Data Gaji Pribadi (berdasarkan NIK) ──────────────────
$gajiData    = null;
$riwayatGaji = [];

if ($nikUser !== '') {
    // Gaji bulan ini
    $stmtG = $conn->prepare("
        SELECT * FROM penggajian
        WHERE nik = ? AND bulan = DATE_FORMAT(NOW(),'%M') AND tahun = YEAR(NOW())
        LIMIT 1
    ");
    $stmtG->bind_param("s", $nikUser);
    $stmtG->execute();
    $gajiData = $stmtG->get_result()->fetch_assoc();
    $stmtG->close();

    // Riwayat 5 gaji terakhir
    $stmtRG = $conn->prepare("
        SELECT * FROM penggajian WHERE nik = ?
        ORDER BY tahun DESC,
                 FIELD(bulan,'Januari','Februari','Maret','April','Mei','Juni',
                             'Juli','Agustus','September','Oktober','November','Desember') DESC
        LIMIT 5
    ");
    $stmtRG->bind_param("s", $nikUser);
    $stmtRG->execute();
    $riwayatGaji = $stmtRG->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtRG->close();

    // Total gaji sudah dibayar tahun ini
    $stmtTG = $conn->prepare("SELECT COALESCE(SUM(total_gaji),0) FROM penggajian WHERE nik = ? AND tahun = YEAR(NOW()) AND status = 'Sudah Dibayar'");
    $stmtTG->bind_param("s", $nikUser);
    $stmtTG->execute();
    $totalGajiTahunIni = (int)$stmtTG->get_result()->fetch_row()[0];
    $stmtTG->close();
} else {
    $totalGajiTahunIni = 0;
}

// ── Stats Transaksi Hari Ini (semua cabang, info umum) ───
$trxHariIni   = (int)$conn->query("SELECT COUNT(*) FROM transaksi WHERE tanggal = CURDATE()")->fetch_row()[0];
$omsetHariIni = (int)$conn->query("SELECT COALESCE(SUM(total),0) FROM transaksi WHERE tanggal = CURDATE() AND status='Selesai'")->fetch_row()[0];

// ── Stats Stok (info umum) ───────────────────────────────
$totalProduk = (int)$conn->query("SELECT COUNT(*) FROM stok")->fetch_row()[0];
$stokHabis   = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia <= 0")->fetch_row()[0];
$stokMenipis = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia > 0 AND stok_tersedia <= 10")->fetch_row()[0];

// ── 5 Transaksi terbaru hari ini ─────────────────────────
$recentTrx = $conn->query("
    SELECT t.*, c.nama_cabang
    FROM transaksi t JOIN cabang c ON c.id = t.cabang_id
    WHERE t.tanggal = CURDATE()
    ORDER BY t.created_at DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

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
                    Selamat datang, <?= htmlspecialchars($namaUser) ?> — <?= date('d M Y') ?>
                </p>
            </div>
        </div>
    </div>

    <div class="flex-1 p-5 md:p-8">

        <!-- ── INFO PROFIL KARYAWAN ───────────────────────── -->
        <div class="flex items-center gap-4 p-5 rounded-2xl bg-primary/5 border border-primary/20 mb-6">
            <div class="size-14 rounded-2xl bg-primary/10 flex items-center justify-center shrink-0">
                <i data-lucide="user-circle" class="size-8 text-primary"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-base"><?= htmlspecialchars($namaUser) ?></p>
                <div class="flex items-center gap-3 mt-1 flex-wrap">
                    <span class="text-xs text-secondary"><?= htmlspecialchars($jabatanUser) ?></span>
                    <?php if ($nikUser): ?>
                        <span class="text-xs bg-muted text-secondary px-2 py-0.5 rounded-lg font-mono">NIK: <?= htmlspecialchars($nikUser) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-right shrink-0 hidden sm:block">
                <p class="text-xs text-secondary">Total Gaji Diterima</p>
                <p class="font-bold text-success text-lg"><?= rupiah($totalGajiTahunIni) ?></p>
                <p class="text-xs text-secondary">tahun <?= date('Y') ?></p>
            </div>
        </div>

        <!-- ── ROW 1: Gaji Bulan Ini ─────────────────────── -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">

            <!-- Gaji Pokok -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                    <i data-lucide="banknote" class="size-6 text-primary"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Gaji Pokok</p>
                    <p class="text-lg font-bold text-foreground truncate"><?= $gajiData ? rupiah($gajiData['gaji_pokok']) : '-' ?></p>
                    <p class="text-xs text-secondary">bulan <?= date('F Y') ?></p>
                </div>
            </div>

            <!-- Tunjangan -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                    <i data-lucide="wallet" class="size-6 text-success"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Tunjangan</p>
                    <p class="text-lg font-bold text-foreground truncate"><?= $gajiData ? rupiah($gajiData['tunjangan']) : '-' ?></p>
                    <p class="text-xs text-secondary">bulan ini</p>
                </div>
            </div>

            <!-- Total Gaji -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-warning/20 flex items-center justify-center shrink-0">
                    <i data-lucide="trending-up" class="size-6 text-yellow-600"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Total Gaji</p>
                    <p class="text-lg font-bold text-foreground truncate"><?= $gajiData ? rupiah($gajiData['total_gaji']) : '-' ?></p>
                    <p class="text-xs text-secondary">bulan ini</p>
                </div>
            </div>

            <!-- Status Gaji -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <?php
                $statusGaji = $gajiData['status'] ?? null;
                if ($statusGaji === 'Sudah Dibayar') {
                    $iconColor = 'bg-success/10'; $iColor = 'text-success'; $icon = 'check-circle';
                } elseif ($statusGaji === 'Menunggu') {
                    $iconColor = 'bg-warning/20'; $iColor = 'text-yellow-600'; $icon = 'clock';
                } elseif ($statusGaji === 'Belum Dibayar') {
                    $iconColor = 'bg-error/10'; $iColor = 'text-error'; $icon = 'x-circle';
                } else {
                    $iconColor = 'bg-muted'; $iColor = 'text-secondary'; $icon = 'minus-circle';
                }
                ?>
                <div class="size-12 rounded-xl <?= $iconColor ?> flex items-center justify-center shrink-0">
                    <i data-lucide="<?= $icon ?>" class="size-6 <?= $iColor ?>"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Status Gaji</p>
                    <?php if ($statusGaji): ?>
                        <?php
                        if ($statusGaji === 'Sudah Dibayar')      $badgeClass = 'bg-success/10 text-success';
                        elseif ($statusGaji === 'Menunggu')        $badgeClass = 'bg-warning/20 text-yellow-700';
                        else                                       $badgeClass = 'bg-error/10 text-error';
                        ?>
                        <span class="inline-flex items-center mt-1 px-2.5 py-1 rounded-full text-xs font-bold <?= $badgeClass ?>">
                            <?= htmlspecialchars($statusGaji) ?>
                        </span>
                    <?php else: ?>
                        <p class="text-sm font-semibold text-secondary mt-1">Belum ada data</p>
                    <?php endif; ?>
                    <p class="text-xs text-secondary mt-0.5">bulan ini</p>
                </div>
            </div>

        </div>

        <!-- ── ROW 2: Info Operasional Hari Ini ──────────── -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

            <!-- Transaksi Hari Ini -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                    <i data-lucide="receipt" class="size-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Transaksi Hari Ini</p>
                    <p class="text-2xl font-bold text-foreground"><?= $trxHariIni ?></p>
                    <p class="text-xs text-secondary">seluruh cabang</p>
                </div>
            </div>

            <!-- Omset Hari Ini -->
            <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center shrink-0">
                    <i data-lucide="trending-up" class="size-6 text-success"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wide">Omset Hari Ini</p>
                    <p class="text-lg font-bold text-foreground truncate"><?= rupiah($omsetHariIni) ?></p>
                    <p class="text-xs text-secondary">transaksi selesai</p>
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

        </div>

        <!-- ── ROW 3: Tabel Terbaru ──────────────────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

            <!-- Transaksi Hari Ini -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center">
                        <i data-lucide="receipt" class="size-4 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-sm">Transaksi Hari Ini</h3>
                    <?php if ($trxHariIni > 0): ?>
                        <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded-full bg-primary/10 text-primary text-xs font-bold"><?= $trxHariIni ?></span>
                    <?php endif; ?>
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
                                <tr><td colspan="4">
                                    <div class="py-12 flex flex-col items-center gap-2 text-center">
                                        <i data-lucide="receipt" class="size-8 text-secondary"></i>
                                        <p class="text-sm text-secondary">Belum ada transaksi hari ini</p>
                                    </div>
                                </td></tr>
                            <?php else: ?>
                            <?php foreach ($recentTrx as $t): ?>
                            <tr class="row-hover border-b border-border transition-colors">
                                <td class="px-5 py-3">
                                    <p class="text-xs font-mono font-semibold"><?= htmlspecialchars($t['no_transaksi']) ?></p>
                                    <p class="text-xs text-secondary"><?= htmlspecialchars($t['nama_cabang']) ?></p>
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

            <!-- Riwayat Gaji -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden">
                <div class="px-5 py-4 border-b border-border flex items-center gap-3">
                    <div class="size-8 rounded-lg bg-success/10 flex items-center justify-center">
                        <i data-lucide="wallet" class="size-4 text-success"></i>
                    </div>
                    <h3 class="font-bold text-sm">Riwayat Gaji Saya</h3>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[380px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Periode</th>
                                <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Gaji Pokok</th>
                                <th class="text-right px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Total</th>
                                <th class="text-center px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($riwayatGaji)): ?>
                                <tr><td colspan="4">
                                    <div class="py-12 flex flex-col items-center gap-2 text-center">
                                        <i data-lucide="file-x" class="size-8 text-secondary"></i>
                                        <p class="text-sm text-secondary">Belum ada data penggajian</p>
                                        <?php if (!$nikUser): ?>
                                            <p class="text-xs text-secondary">NIK belum terdaftar di akun Anda</p>
                                        <?php endif; ?>
                                    </div>
                                </td></tr>
                            <?php else: ?>
                            <?php foreach ($riwayatGaji as $g): ?>
                            <tr class="row-hover border-b border-border transition-colors">
                                <td class="px-5 py-3">
                                    <p class="text-sm font-semibold"><?= htmlspecialchars($g['bulan']) ?></p>
                                    <p class="text-xs text-secondary"><?= htmlspecialchars($g['tahun']) ?></p>
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-xs text-secondary"><?= rupiah($g['gaji_pokok']) ?></td>
                                <td class="px-5 py-3 text-right">
                                    <span class="font-bold text-primary font-mono text-xs"><?= rupiah($g['total_gaji']) ?></span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <?php
                                    if ($g['status'] === 'Sudah Dibayar')  $gs = 'bg-success/10 text-success';
                                    elseif ($g['status'] === 'Menunggu')   $gs = 'bg-warning/20 text-yellow-700';
                                    else                                   $gs = 'bg-error/10 text-error';
                                    ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $gs ?>">
                                        <?= htmlspecialchars($g['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /grid row 3 -->

    </div><!-- /p-5 md:p-8 -->
</main>

<script src="../layout/index.js"></script>
</body>
</html>