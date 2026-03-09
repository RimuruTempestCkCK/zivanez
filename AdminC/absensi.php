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

// ── Helper ────────────────────────────────────────────────
$msg     = '';
$msgType = '';

// ── Karyawan cabang ini ───────────────────────────────────
$karyawanList = [];
if ($cabangId > 0) {
    $kStmt = $conn->prepare("SELECT id, nama_lengkap, jabatan, nik FROM users WHERE cabang_id = ? AND role = 'Karyawan' ORDER BY nama_lengkap ASC");
    $kStmt->bind_param("i", $cabangId);
    $kStmt->execute();
    $karyawanList = $kStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $kStmt->close();
}

// ── CRUD HANDLER ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ===================== CREATE BULK ===================== */
    if ($_POST['action'] === 'create_bulk') {
        $tanggal    = trim($_POST['tanggal'] ?? date('Y-m-d'));
        $karyawanIds = $_POST['karyawan_id']  ?? [];
        $statusList  = $_POST['status_hadir'] ?? [];
        $ketList     = $_POST['keterangan']   ?? [];

        if ($cabangId === 0) {
            $msg = "Anda belum terhubung ke cabang manapun";
            $msgType = 'error';
        } elseif (empty($karyawanIds)) {
            $msg = "Tidak ada karyawan untuk diabsen";
            $msgType = 'error';
        } else {
            $conn->begin_transaction();
            $ok = true;
            $inserted = 0;
            try {
                foreach ($karyawanIds as $idx => $kid) {
                    $kid     = (int)$kid;
                    $status  = $statusList[$idx] ?? 'Hadir';
                    $ket     = trim($ketList[$idx] ?? '');

                    if (!in_array($status, ['Hadir', 'Izin', 'Sakit', 'Alpha'])) $status = 'Hadir';

                    $chk = $conn->prepare("SELECT id FROM absensi WHERE karyawan_id = ? AND tanggal = ? AND cabang_id = ? LIMIT 1");
                    $chk->bind_param("isi", $kid, $tanggal, $cabangId);
                    $chk->execute();
                    $existing = $chk->get_result()->fetch_assoc();
                    $chk->close();

                    if ($existing) {
                        $up = $conn->prepare("UPDATE absensi SET status = ?, keterangan = ?, dicatat_oleh = ? WHERE id = ?");
                        $up->bind_param("ssii", $status, $ket, $adminId, $existing['id']);
                        $up->execute();
                        $up->close();
                    } else {
                        $ins = $conn->prepare("INSERT INTO absensi (cabang_id, karyawan_id, tanggal, status, keterangan, dicatat_oleh) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->bind_param("iisssi", $cabangId, $kid, $tanggal, $status, $ket, $adminId);
                        $ins->execute();
                        $ins->close();
                    }
                    $inserted++;
                }
                $conn->commit();
                $msg = "Absensi $inserted karyawan berhasil disimpan untuk tanggal " . date('d/m/Y', strtotime($tanggal));
                $msgType = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $msg = "Gagal menyimpan absensi: " . $e->getMessage();
                $msgType = 'error';
            }
        }
    }

    /* ===================== DELETE ===================== */
    if ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $dl = $conn->prepare("DELETE FROM absensi WHERE id = ? AND cabang_id = ?");
        $dl->bind_param("ii", $id, $cabangId);
        if ($dl->execute() && $dl->affected_rows > 0) {
            $msg = "Data absensi berhasil dihapus";
            $msgType = 'success';
        } else {
            $msg = "Gagal menghapus atau data tidak ditemukan";
            $msgType = 'error';
        }
        $dl->close();
    }
}

/* ===================== FETCH DATA ===================== */
$search      = trim($_GET['search']  ?? '');
$filterStat  = trim($_GET['status']  ?? '');
$filterBulan = trim($_GET['bulan']   ?? '');
$filterTgl   = trim($_GET['tanggal'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 15;
$offset      = ($page - 1) * $perPage;

$where  = "WHERE a.cabang_id = ?";
$params = [$cabangId];
$types  = "i";

if ($search !== '') {
    $where   .= " AND (u.nama_lengkap LIKE ? OR u.nik LIKE ? OR u.jabatan LIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($filterStat !== '') {
    $where   .= " AND a.status = ?";
    $params[] = $filterStat;
    $types   .= "s";
}
if ($filterTgl !== '') {
    $where   .= " AND a.tanggal = ?";
    $params[] = $filterTgl;
    $types   .= "s";
} elseif ($filterBulan !== '') {
    $where   .= " AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?";
    $params[] = $filterBulan;
    $types   .= "s";
}

$cntStmt = $conn->prepare("SELECT COUNT(*) FROM absensi a JOIN users u ON u.id = a.karyawan_id $where");
$cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

// ── Tentukan scope rekap (bulan filter atau bulan berjalan) ──
$rekapBulan = '';
if ($filterBulan !== '') {
    $rekapBulan = $filterBulan;
} elseif ($filterTgl !== '') {
    $rekapBulan = date('Y-m', strtotime($filterTgl));
} else {
    $rekapBulan = date('Y-m');
}
$rekapBulanEsc = $conn->real_escape_string($rekapBulan);

// ── Fetch rows + rekap hadir/tidak per karyawan (scope bulan) ──
$sql = "
    SELECT a.*,
           u.nama_lengkap, u.nik, u.jabatan,
           p.nama_lengkap AS dicatat_nama,
           (SELECT COUNT(*) FROM absensi x
            WHERE x.karyawan_id = a.karyawan_id
              AND x.cabang_id   = a.cabang_id
              AND DATE_FORMAT(x.tanggal,'%Y-%m') = '$rekapBulanEsc'
              AND x.status = 'Hadir')                          AS total_hadir,
           (SELECT COUNT(*) FROM absensi x
            WHERE x.karyawan_id = a.karyawan_id
              AND x.cabang_id   = a.cabang_id
              AND DATE_FORMAT(x.tanggal,'%Y-%m') = '$rekapBulanEsc'
              AND x.status != 'Hadir')                         AS total_tidak,
           (SELECT COUNT(*) FROM absensi x
            WHERE x.karyawan_id = a.karyawan_id
              AND x.cabang_id   = a.cabang_id
              AND DATE_FORMAT(x.tanggal,'%Y-%m') = '$rekapBulanEsc'
              AND x.status = 'Izin')                           AS total_izin,
           (SELECT COUNT(*) FROM absensi x
            WHERE x.karyawan_id = a.karyawan_id
              AND x.cabang_id   = a.cabang_id
              AND DATE_FORMAT(x.tanggal,'%Y-%m') = '$rekapBulanEsc'
              AND x.status = 'Sakit')                          AS total_sakit,
           (SELECT COUNT(*) FROM absensi x
            WHERE x.karyawan_id = a.karyawan_id
              AND x.cabang_id   = a.cabang_id
              AND DATE_FORMAT(x.tanggal,'%Y-%m') = '$rekapBulanEsc'
              AND x.status = 'Alpha')                          AS total_alpha
    FROM absensi a
    JOIN users u ON u.id = a.karyawan_id
    LEFT JOIN users p ON p.id = a.dicatat_oleh
    $where
    ORDER BY a.tanggal DESC, u.nama_lengkap ASC
    LIMIT ? OFFSET ?
";
$dataStmt = $conn->prepare($sql);
$params[] = $perPage;
$params[] = $offset;
$types   .= "ii";
$dataStmt->bind_param($types, ...$params);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// ── Stats hari ini ────────────────────────────────────────
$today = date('Y-m-d');
$statHadir  = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM absensi WHERE cabang_id=$cabangId AND tanggal='$today' AND status='Hadir'")->fetch_row()[0] : 0;
$statIzin   = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM absensi WHERE cabang_id=$cabangId AND tanggal='$today' AND status='Izin'")->fetch_row()[0] : 0;
$statSakit  = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM absensi WHERE cabang_id=$cabangId AND tanggal='$today' AND status='Sakit'")->fetch_row()[0] : 0;
$statAlpha  = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM absensi WHERE cabang_id=$cabangId AND tanggal='$today' AND status='Alpha'")->fetch_row()[0] : 0;
$totalKaryawan = count($karyawanList);

// ── Rekap per karyawan untuk bulan aktif (tabel ringkasan atas) ──
$rekapKaryawan = [];
if ($cabangId > 0 && !empty($karyawanList)) {
    $rStmt = $conn->prepare("
        SELECT karyawan_id,
               SUM(status = 'Hadir') AS hadir,
               SUM(status = 'Izin')  AS izin,
               SUM(status = 'Sakit') AS sakit,
               SUM(status = 'Alpha') AS alpha,
               COUNT(*)              AS total_hari
        FROM absensi
        WHERE cabang_id = ? AND DATE_FORMAT(tanggal,'%Y-%m') = ?
        GROUP BY karyawan_id
    ");
    $rStmt->bind_param("is", $cabangId, $rekapBulan);
    $rStmt->execute();
    foreach ($rStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $rk) {
        $rekapKaryawan[$rk['karyawan_id']] = $rk;
    }
    $rStmt->close();
}

// ── Cek absensi hari ini sudah dilakukan? ─────────────────
$sudahAbsenHariIni = false;
if ($cabangId > 0) {
    $cekHariIni = $conn->prepare("SELECT COUNT(*) FROM absensi WHERE cabang_id = ? AND tanggal = ?");
    $cekHariIni->bind_param("is", $cabangId, $today);
    $cekHariIni->execute();
    $sudahAbsenHariIni = (int)$cekHariIni->get_result()->fetch_row()[0] > 0;
    $cekHariIni->close();
}

// ── Absensi hari ini per karyawan (untuk prefill modal) ───
$absenHariIniMap = [];
if ($cabangId > 0) {
    $aStmt = $conn->prepare("SELECT karyawan_id, status, keterangan FROM absensi WHERE cabang_id = ? AND tanggal = ?");
    $aStmt->bind_param("is", $cabangId, $today);
    $aStmt->execute();
    foreach ($aStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
        $absenHariIniMap[$a['karyawan_id']] = $a;
    }
    $aStmt->close();
}

$rekapLabel = date('F Y', strtotime($rekapBulan . '-01'));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Karyawan - Sanjai Zivanes</title>
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
        .float-wrap { @apply relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white; }
        .float-wrap label { @apply absolute left-12 text-secondary text-xs font-medium top-2 pointer-events-none; }
        .float-wrap input { @apply absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm; }
        .float-wrap .fi { @apply absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary pointer-events-none; }
        .status-btn { @apply px-3 py-1.5 rounded-xl text-xs font-bold border-2 transition-all cursor-pointer; }
        .status-btn.active-Hadir  { @apply bg-success/20 border-success text-success; }
        .status-btn.active-Izin   { @apply bg-primary/10 border-primary text-primary; }
        .status-btn.active-Sakit  { @apply bg-warning/20 border-warning text-yellow-700; }
        .status-btn.active-Alpha  { @apply bg-error/10 border-error text-error; }
        .status-btn:not([class*="active"]) { @apply bg-muted border-border text-secondary hover:border-secondary; }
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
                    <h2 class="font-bold text-2xl text-foreground">Absensi Karyawan</h2>
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

            <?php if ($cabangSaya && empty($karyawanList)): ?>
                <div class="mb-6 flex items-center gap-4 p-5 rounded-2xl bg-warning/20 border border-warning/40">
                    <div class="size-10 rounded-xl bg-warning/30 flex items-center justify-center shrink-0">
                        <i data-lucide="users" class="size-5 text-yellow-700"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-yellow-800">Belum ada karyawan di cabang ini</p>
                        <p class="text-sm text-yellow-700">Hubungi Admin Pusat untuk menambah karyawan ke cabang Anda.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Info bar absensi hari ini -->
            <?php if ($cabangSaya && !empty($karyawanList)): ?>
                <div class="mb-6 <?= $sudahAbsenHariIni ? 'bg-success/5 border-success/20' : 'bg-primary/5 border-primary/20' ?> border rounded-2xl p-4 flex flex-col sm:flex-row items-start sm:items-center gap-4">
                    <div class="size-10 rounded-xl <?= $sudahAbsenHariIni ? 'bg-success/10' : 'bg-primary/10' ?> flex items-center justify-center shrink-0">
                        <i data-lucide="<?= $sudahAbsenHariIni ? 'check-circle' : 'clock' ?>" class="size-5 <?= $sudahAbsenHariIni ? 'text-success' : 'text-primary' ?>"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-foreground text-sm">
                            <?= $sudahAbsenHariIni ? 'Absensi hari ini sudah dicatat' : 'Absensi hari ini belum dilakukan' ?>
                            &mdash; <span class="text-secondary"><?= date('l, d F Y') ?></span>
                        </p>
                        <div class="flex flex-wrap gap-3 mt-1">
                            <span class="text-xs text-success font-semibold">✓ Hadir: <?= $statHadir ?></span>
                            <span class="text-xs text-primary font-semibold">📋 Izin: <?= $statIzin ?></span>
                            <span class="text-xs text-yellow-600 font-semibold">🤒 Sakit: <?= $statSakit ?></span>
                            <span class="text-xs text-error font-semibold">✗ Alpha: <?= $statAlpha ?></span>
                        </div>
                    </div>
                    <button type="button" onclick="openAbsenModal()"
                        class="shrink-0 px-5 py-2.5 rounded-full <?= $sudahAbsenHariIni ? 'bg-success text-white hover:bg-green-600' : 'bg-primary text-white hover:bg-primary-hover' ?> text-xs font-bold transition-colors cursor-pointer flex items-center gap-2">
                        <i data-lucide="<?= $sudahAbsenHariIni ? 'edit' : 'clipboard-check' ?>" class="size-3.5"></i>
                        <?= $sudahAbsenHariIni ? 'Edit Absensi' : 'Mulai Absensi' ?>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-2xl border border-border p-5 flex items-center gap-3">
                    <div class="size-11 rounded-2xl bg-success/10 flex items-center justify-center shrink-0">
                        <i data-lucide="user-check" class="size-5 text-success"></i>
                    </div>
                    <div>
                        <p class="text-xs text-secondary font-medium">Hadir Hari Ini</p>
                        <p class="text-2xl font-bold text-foreground"><?= $statHadir ?></p>
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-border p-5 flex items-center gap-3">
                    <div class="size-11 rounded-2xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="file-check" class="size-5 text-primary"></i>
                    </div>
                    <div>
                        <p class="text-xs text-secondary font-medium">Izin Hari Ini</p>
                        <p class="text-2xl font-bold text-foreground"><?= $statIzin ?></p>
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-border p-5 flex items-center gap-3">
                    <div class="size-11 rounded-2xl bg-warning/20 flex items-center justify-center shrink-0">
                        <i data-lucide="thermometer" class="size-5 text-yellow-600"></i>
                    </div>
                    <div>
                        <p class="text-xs text-secondary font-medium">Sakit Hari Ini</p>
                        <p class="text-2xl font-bold text-foreground"><?= $statSakit ?></p>
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-border p-5 flex items-center gap-3">
                    <div class="size-11 rounded-2xl bg-error/10 flex items-center justify-center shrink-0">
                        <i data-lucide="user-x" class="size-5 text-error"></i>
                    </div>
                    <div>
                        <p class="text-xs text-secondary font-medium">Alpha Hari Ini</p>
                        <p class="text-2xl font-bold text-foreground"><?= $statAlpha ?></p>
                    </div>
                </div>
            </div>

            <!-- ═══ REKAP KEHADIRAN PER KARYAWAN ═══ -->
            <?php if ($cabangSaya && !empty($karyawanList)): ?>
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-6">
                <div class="flex items-center gap-3 px-5 py-4 border-b border-border">
                    <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="bar-chart-2" class="size-4 text-primary"></i>
                    </div>
                    <div>
                        <p class="font-bold text-foreground text-sm">Rekap Kehadiran Karyawan</p>
                        <p class="text-xs text-secondary"><?= $rekapLabel ?> &mdash; semua karyawan cabang</p>
                    </div>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[700px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/40">
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider w-[220px]">Karyawan</th>
                                <th class="text-center px-4 py-3 text-xs font-bold text-success uppercase tracking-wider">✓ Hadir</th>
                                <th class="text-center px-4 py-3 text-xs font-bold text-error uppercase tracking-wider">✗ Tdk Hadir</th>
                                <th class="text-center px-4 py-3 text-xs font-bold text-primary uppercase tracking-wider">Izin</th>
                                <th class="text-center px-4 py-3 text-xs font-bold text-yellow-600 uppercase tracking-wider">Sakit</th>
                                <th class="text-center px-4 py-3 text-xs font-bold text-error uppercase tracking-wider">Alpha</th>
                                <th class="text-left px-5 py-3 text-xs font-bold text-secondary uppercase tracking-wider">% Kehadiran</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($karyawanList as $k):
                                $rek    = $rekapKaryawan[$k['id']] ?? ['hadir'=>0,'izin'=>0,'sakit'=>0,'alpha'=>0,'total_hari'=>0];
                                $hadir  = (int)$rek['hadir'];
                                $izin   = (int)$rek['izin'];
                                $sakit  = (int)$rek['sakit'];
                                $alpha  = (int)$rek['alpha'];
                                $total  = (int)$rek['total_hari'];
                                $tidak  = $izin + $sakit + $alpha;
                                $persen = $total > 0 ? round(($hadir / $total) * 100) : 0;
                                $barClr = $persen >= 80 ? 'bg-success' : ($persen >= 50 ? 'bg-warning' : 'bg-error');
                                $pctClr = $persen >= 80 ? 'text-success' : ($persen >= 50 ? 'text-yellow-600' : 'text-error');
                            ?>
                            <tr class="table-row-hover border-b border-border transition-colors">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="size-8 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                            <i data-lucide="user" class="size-3.5 text-primary"></i>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($k['nama_lengkap'] ?? 'Tanpa Nama') ?></p>
                                            <p class="text-xs text-secondary"><?= htmlspecialchars($k['jabatan'] ?? '—') ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center min-w-[2rem] px-2 h-8 rounded-xl bg-success/10 text-success text-sm font-bold"><?= $hadir ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center min-w-[2rem] px-2 h-8 rounded-xl bg-error/10 text-error text-sm font-bold"><?= $tidak ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center min-w-[2rem] px-2 h-8 rounded-xl bg-primary/10 text-primary text-sm font-bold"><?= $izin ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center min-w-[2rem] px-2 h-8 rounded-xl bg-warning/20 text-yellow-700 text-sm font-bold"><?= $sakit ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center min-w-[2rem] px-2 h-8 rounded-xl bg-error/10 text-error text-sm font-bold"><?= $alpha ?></span>
                                </td>
                                <td class="px-5 py-3">
                                    <?php if ($total > 0): ?>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-2 bg-muted rounded-full overflow-hidden min-w-[80px]">
                                            <div class="h-full <?= $barClr ?> rounded-full" style="width:<?= $persen ?>%"></div>
                                        </div>
                                        <span class="text-xs font-bold <?= $pctClr ?> w-9 text-right shrink-0"><?= $persen ?>%</span>
                                    </div>
                                    <p class="text-[10px] text-secondary mt-0.5"><?= $total ?> hari tercatat</p>
                                    <?php else: ?>
                                        <span class="text-xs text-secondary italic">Belum ada data</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Toolbar -->
            <form method="GET" id="filterForm">
                <div class="flex flex-col md:flex-row gap-4 justify-between items-center mb-6">
                    <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-1 max-w-3xl flex-wrap">
                        <div class="relative flex-1 min-w-[200px] group">
                            <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary group-focus-within:text-primary transition-colors"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Cari nama / NIK karyawan..."
                                class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300">
                        </div>
                        <select name="status" onchange="this.form.submit()"
                            class="h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none min-w-[140px]">
                            <option value="">Semua Status</option>
                            <option value="Hadir"  <?= $filterStat === 'Hadir'  ? 'selected' : '' ?>>Hadir</option>
                            <option value="Izin"   <?= $filterStat === 'Izin'   ? 'selected' : '' ?>>Izin</option>
                            <option value="Sakit"  <?= $filterStat === 'Sakit'  ? 'selected' : '' ?>>Sakit</option>
                            <option value="Alpha"  <?= $filterStat === 'Alpha'  ? 'selected' : '' ?>>Alpha</option>
                        </select>
                        <input type="date" name="tanggal" value="<?= htmlspecialchars($filterTgl) ?>"
                            onchange="this.form.submit()"
                            class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                        <input type="month" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>"
                            onchange="this.form.submit()"
                            class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div class="flex gap-3 w-full md:w-auto">
                        <button type="button" onclick="openAbsenModal()"
                            <?= (!$cabangSaya || empty($karyawanList)) ? 'disabled' : '' ?>
                            class="flex-1 md:flex-none px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 hover:shadow-primary/40 flex items-center justify-center gap-2 transition-all duration-300 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <i data-lucide="clipboard-check" class="size-5"></i>
                            <span>Catat Absensi</span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Table Riwayat Absensi -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="flex items-center gap-3 px-5 py-4 border-b border-border">
                    <div class="size-9 rounded-xl bg-muted flex items-center justify-center shrink-0">
                        <i data-lucide="clipboard-list" class="size-4 text-secondary"></i>
                    </div>
                    <div>
                        <p class="font-bold text-foreground text-sm">Riwayat Absensi</p>
                        <p class="text-xs text-secondary">Kolom Hadir &amp; Tdk Hadir = rekap <?= $rekapLabel ?> per karyawan</p>
                    </div>
                </div>
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[960px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Karyawan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">NIK</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jabatan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <!-- ── KOLOM BARU ── -->
                                <th class="text-center px-4 py-4 text-xs font-bold text-success uppercase tracking-wider">✓ Hadir</th>
                                <th class="text-center px-4 py-4 text-xs font-bold text-error uppercase tracking-wider">✗ Tdk Hadir</th>
                                <!-- ── END KOLOM BARU ── -->
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keterangan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Dicatat Oleh</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="py-16 flex flex-col items-center justify-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="clipboard-list" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Belum ada data absensi</p>
                                            <p class="text-sm text-secondary">Klik "Catat Absensi" untuk mencatat kehadiran karyawan.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r):
                                    $stCfg = [
                                        'Hadir' => ['bg-success/10', 'text-success',    'user-check'],
                                        'Izin'  => ['bg-primary/10', 'text-primary',    'file-check'],
                                        'Sakit' => ['bg-warning/20', 'text-yellow-700', 'thermometer'],
                                        'Alpha' => ['bg-error/10',   'text-error',      'user-x'],
                                    ];
                                    [$sBg, $sTx, $sIco] = $stCfg[$r['status']] ?? ['bg-secondary/10', 'text-secondary', 'circle'];

                                    $rHadir  = (int)$r['total_hadir'];
                                    $rTidak  = (int)$r['total_tidak'];
                                    $rTotal  = $rHadir + $rTidak;
                                    $rPersen = $rTotal > 0 ? round(($rHadir / $rTotal) * 100) : 0;
                                    $rBar    = $rPersen >= 80 ? 'bg-success' : ($rPersen >= 50 ? 'bg-warning' : 'bg-error');
                                    $rPct    = $rPersen >= 80 ? 'text-success' : ($rPersen >= 50 ? 'text-yellow-600' : 'text-error');
                                ?>
                                    <tr class="table-row-hover border-b border-border transition-colors duration-150">
                                        <!-- Karyawan -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                                    <i data-lucide="user" class="size-4 text-primary"></i>
                                                </div>
                                                <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_lengkap'] ?? '—') ?></p>
                                            </div>
                                        </td>
                                        <!-- NIK -->
                                        <td class="px-5 py-4">
                                            <span class="text-xs font-mono text-secondary"><?= htmlspecialchars($r['nik'] ?? '—') ?></span>
                                        </td>
                                        <!-- Jabatan -->
                                        <td class="px-5 py-4">
                                            <span class="text-sm text-secondary"><?= htmlspecialchars($r['jabatan'] ?? '—') ?></span>
                                        </td>
                                        <!-- Tanggal -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm font-semibold text-foreground"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></p>
                                            <p class="text-xs text-secondary"><?= date('l', strtotime($r['tanggal'])) ?></p>
                                        </td>
                                        <!-- Status -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full <?= $sBg ?> <?= $sTx ?> text-xs font-bold">
                                                <i data-lucide="<?= $sIco ?>" class="size-3"></i>
                                                <?= htmlspecialchars($r['status']) ?>
                                            </span>
                                        </td>
                                        <!-- ── KOLOM BARU: Hadir ── -->
                                        <td class="px-4 py-4 text-center">
                                            <div class="flex flex-col items-center gap-0.5">
                                                <span class="inline-flex items-center justify-center min-w-[2rem] px-2 h-8 rounded-xl bg-success/10 text-success font-bold text-sm"><?= $rHadir ?></span>
                                                <span class="text-[10px] text-secondary">hari</span>
                                            </div>
                                        </td>
                                        <!-- ── KOLOM BARU: Tidak Hadir ── -->
                                        <td class="px-4 py-4 text-center">
                                            <div class="flex flex-col items-center gap-0.5">
                                                <span class="inline-flex items-center justify-center min-w-[2rem] px-2 h-8 rounded-xl bg-error/10 text-error font-bold text-sm"><?= $rTidak ?></span>
                                                <?php
                                                    $detail = [];
                                                    if ((int)$r['total_izin']  > 0) $detail[] = "I:{$r['total_izin']}";
                                                    if ((int)$r['total_sakit'] > 0) $detail[] = "S:{$r['total_sakit']}";
                                                    if ((int)$r['total_alpha'] > 0) $detail[] = "A:{$r['total_alpha']}";
                                                ?>
                                                <?php if ($detail): ?>
                                                    <span class="text-[9px] text-secondary leading-tight"><?= implode(' ', $detail) ?></span>
                                                <?php else: ?>
                                                    <span class="text-[10px] text-secondary">hari</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <!-- Keterangan -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm text-secondary max-w-[120px] truncate" title="<?= htmlspecialchars($r['keterangan'] ?? '') ?>">
                                                <?= $r['keterangan'] ? htmlspecialchars($r['keterangan']) : '—' ?>
                                            </p>
                                        </td>
                                        <!-- Dicatat Oleh -->
                                        <td class="px-5 py-4">
                                            <p class="text-xs text-secondary"><?= htmlspecialchars($r['dicatat_nama'] ?? '—') ?></p>
                                        </td>
                                        <!-- Aksi -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-center">
                                                <button type="button"
                                                    onclick="confirmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['nama_lengkap'] ?? '', ENT_QUOTES) ?>', '<?= date('d/m/Y', strtotime($r['tanggal'])) ?>')"
                                                    title="Hapus"
                                                    class="size-9 flex items-center justify-center rounded-lg bg-error/10 hover:bg-error text-error hover:text-white transition-all duration-200 cursor-pointer">
                                                    <i data-lucide="trash-2" class="size-4"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer -->
                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3">
                    <p class="text-sm text-secondary">
                        Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                        dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> data absensi
                    </p>
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>&tanggal=<?= urlencode($filterTgl) ?>"
                                    class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>&tanggal=<?= urlencode($filterTgl) ?>"
                                    class="size-9 flex items-center justify-center rounded-lg border <?= $i == $page ? 'bg-primary/10 border-primary/20 font-semibold text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?> text-sm transition-all cursor-pointer">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>&tanggal=<?= urlencode($filterTgl) ?>"
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

    <!-- ===================== MODAL CATAT ABSENSI ===================== -->
    <div id="absen-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-2xl shadow-2xl flex flex-col" style="max-height: 92vh;">
            <div class="flex items-center justify-between p-6 border-b border-border shrink-0">
                <div>
                    <h3 class="font-bold text-xl text-foreground">Catat Absensi</h3>
                    <p class="text-sm text-secondary mt-0.5" id="modalTanggalLabel"></p>
                </div>
                <button onclick="closeAbsenModal()" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>

            <form id="absenForm" method="POST" class="flex flex-col flex-1 overflow-hidden">
                <input type="hidden" name="action" value="create_bulk">

                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <!-- Tanggal -->
                    <div class="float-wrap">
                        <span class="fi"><i data-lucide="calendar" class="size-5 text-secondary"></i></span>
                        <label for="inputTanggal">Tanggal Absensi *</label>
                        <input id="inputTanggal" name="tanggal" type="date" required onchange="updateTanggalLabel(this.value)">
                    </div>

                    <!-- Daftar Karyawan -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <p class="font-bold text-sm text-foreground">Daftar Karyawan</p>
                            <div class="flex gap-2">
                                <button type="button" onclick="setAllStatus('Hadir')"
                                    class="px-3 py-1 rounded-xl text-xs font-bold bg-success/10 text-success hover:bg-success/20 transition-colors cursor-pointer">
                                    Semua Hadir
                                </button>
                                <button type="button" onclick="setAllStatus('Alpha')"
                                    class="px-3 py-1 rounded-xl text-xs font-bold bg-error/10 text-error hover:bg-error/20 transition-colors cursor-pointer">
                                    Semua Alpha
                                </button>
                            </div>
                        </div>

                        <?php if (empty($karyawanList)): ?>
                            <div class="py-8 text-center text-secondary text-sm">Tidak ada karyawan di cabang ini.</div>
                        <?php else: ?>
                            <div class="space-y-3" id="karyawanContainer">
                                <?php foreach ($karyawanList as $idx => $k):
                                    $prevStatus = $absenHariIniMap[$k['id']]['status'] ?? 'Hadir';
                                    $prevKet    = $absenHariIniMap[$k['id']]['keterangan'] ?? '';
                                    // mini rekap di modal
                                    $mRek  = $rekapKaryawan[$k['id']] ?? ['hadir'=>0,'alpha'=>0,'total_hari'=>0];
                                    $mPct  = (int)$mRek['total_hari'] > 0 ? round(((int)$mRek['hadir'] / (int)$mRek['total_hari']) * 100) : null;
                                ?>
                                <div class="p-4 rounded-2xl border border-border bg-muted/20" id="krow-<?= $k['id'] ?>">
                                    <input type="hidden" name="karyawan_id[]" value="<?= $k['id'] ?>">

                                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                        <div class="flex items-center gap-3 flex-1 min-w-0">
                                            <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                                <i data-lucide="user" class="size-4 text-primary"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-semibold text-foreground text-sm truncate"><?= htmlspecialchars($k['nama_lengkap'] ?? 'Tanpa Nama') ?></p>
                                                <p class="text-xs text-secondary flex items-center gap-2 flex-wrap">
                                                    <span><?= htmlspecialchars($k['jabatan'] ?? '') ?><?= $k['nik'] ? ' · ' . htmlspecialchars($k['nik']) : '' ?></span>
                                                    <?php if ($mPct !== null): ?>
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-lg <?= $mPct >= 80 ? 'bg-success/10 text-success' : ($mPct >= 50 ? 'bg-warning/20 text-yellow-700' : 'bg-error/10 text-error') ?> font-bold text-[10px]">
                                                            <?= $mPct ?>% hadir <?= $rekapLabel ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <!-- Status Toggle Buttons -->
                                        <div class="flex gap-1.5 flex-wrap shrink-0" id="statBtns-<?= $k['id'] ?>">
                                            <?php foreach (['Hadir', 'Izin', 'Sakit', 'Alpha'] as $st): ?>
                                                <button type="button"
                                                    onclick="setStatus(<?= $k['id'] ?>, '<?= $st ?>')"
                                                    id="btn-<?= $k['id'] ?>-<?= $st ?>"
                                                    class="status-btn <?= $prevStatus === $st ? 'active-' . $st : '' ?>">
                                                    <?= $st ?>
                                                </button>
                                            <?php endforeach; ?>
                                            <input type="hidden" name="status_hadir[]" id="statusInput-<?= $k['id'] ?>" value="<?= htmlspecialchars($prevStatus) ?>">
                                        </div>
                                    </div>

                                    <!-- Keterangan (shown when not Hadir) -->
                                    <div id="ketWrap-<?= $k['id'] ?>" class="<?= $prevStatus === 'Hadir' ? 'hidden' : '' ?> mt-3">
                                        <div class="relative h-[50px] rounded-xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                                            <i data-lucide="file-text" class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-secondary"></i>
                                            <input type="text" name="keterangan[]"
                                                value="<?= htmlspecialchars($prevKet) ?>"
                                                placeholder="Keterangan (opsional)..."
                                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-9 pr-4 text-foreground text-sm rounded-xl">
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="p-6 border-t border-border flex gap-3 shrink-0">
                    <button type="button" onclick="closeAbsenModal()"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit"
                        class="flex-1 py-3.5 rounded-full bg-primary text-white font-bold hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all cursor-pointer text-sm flex items-center justify-center gap-2">
                        <i data-lucide="save" class="size-4"></i>
                        Simpan Absensi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL HAPUS ===================== -->
    <div id="delete-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-sm shadow-2xl">
            <div class="p-8 flex flex-col items-center gap-4 text-center">
                <div class="size-16 rounded-2xl bg-error/10 flex items-center justify-center">
                    <i data-lucide="trash-2" class="size-8 text-error"></i>
                </div>
                <div>
                    <h3 class="font-bold text-xl text-foreground">Hapus Absensi</h3>
                    <p class="text-sm text-secondary mt-2">Hapus absensi <strong id="deleteNama"></strong> tanggal <strong id="deleteTgl"></strong>?<br>Tindakan ini tidak dapat dibatalkan.</p>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <div class="px-6 pb-6 flex gap-3">
                    <button type="button" onclick="closeDeleteModal()"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit"
                        class="flex-1 py-3.5 rounded-full bg-error text-white font-bold hover:bg-red-500 shadow-lg shadow-error/20 transition-all cursor-pointer text-sm">
                        Ya, Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[300] hidden items-center gap-3 px-5 py-4 rounded-2xl shadow-xl border border-border bg-white max-w-xs transition-all duration-300">
        <div id="toastIcon" class="size-9 rounded-xl flex items-center justify-center shrink-0"></div>
        <p id="toastMsg" class="font-semibold text-sm text-foreground"></p>
    </div>

    <?php if ($msg): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast(<?= json_encode($msg) ?>, <?= json_encode($msgType) ?>);
            });
        </script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (!sidebar) return;
            sidebar.classList.contains('-translate-x-full') ?
                (sidebar.classList.remove('-translate-x-full'), overlay?.classList.remove('hidden'), document.body.style.overflow = 'hidden') :
                (sidebar.classList.add('-translate-x-full'), overlay?.classList.add('hidden'), document.body.style.overflow = '');
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const icon  = document.getElementById('toastIcon');
            const msg   = document.getElementById('toastMsg');
            const cfg = {
                success: { bg: 'bg-success/10', text: 'text-success', icon: 'check-circle' },
                error:   { bg: 'bg-error/10',   text: 'text-error',   icon: 'x-circle'     },
                info:    { bg: 'bg-primary/10',  text: 'text-primary', icon: 'info'         },
            };
            const c = cfg[type] || cfg.success;
            icon.className = `size-9 rounded-xl flex items-center justify-center shrink-0 ${c.bg} ${c.text}`;
            icon.innerHTML = `<i data-lucide="${c.icon}" class="size-5"></i>`;
            msg.textContent = message;
            toast.classList.remove('hidden');
            toast.classList.add('flex');
            lucide.createIcons();
            setTimeout(() => { toast.classList.add('hidden'); toast.classList.remove('flex'); }, 4000);
        }

        function setStatus(karyawanId, status) {
            document.getElementById(`statusInput-${karyawanId}`).value = status;
            ['Hadir','Izin','Sakit','Alpha'].forEach(s => {
                const btn = document.getElementById(`btn-${karyawanId}-${s}`);
                if (!btn) return;
                btn.className = `status-btn${s === status ? ' active-' + s : ''}`;
            });
            const ketWrap = document.getElementById(`ketWrap-${karyawanId}`);
            if (ketWrap) {
                status === 'Hadir' ? ketWrap.classList.add('hidden') : ketWrap.classList.remove('hidden');
            }
        }

        function setAllStatus(status) {
            document.querySelectorAll('[id^="statusInput-"]').forEach(input => {
                const kId = input.id.replace('statusInput-', '');
                setStatus(kId, status);
            });
        }

        function updateTanggalLabel(val) {
            const label = document.getElementById('modalTanggalLabel');
            if (!label || !val) return;
            const d = new Date(val + 'T00:00:00');
            const days   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
            const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
            label.textContent = `${days[d.getDay()]}, ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
        }

        function openAbsenModal() {
            const today = new Date();
            const val   = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
            document.getElementById('inputTanggal').value = val;
            updateTanggalLabel(val);
            toggleModal('absen-modal', true);
        }

        function closeAbsenModal()  { toggleModal('absen-modal',  false); }

        function confirmDelete(id, nama, tgl) {
            document.getElementById('deleteId').value         = id;
            document.getElementById('deleteNama').textContent = nama;
            document.getElementById('deleteTgl').textContent  = tgl;
            toggleModal('delete-modal', true);
        }

        function closeDeleteModal() { toggleModal('delete-modal', false); }

        function toggleModal(id, show) {
            const m = document.getElementById(id);
            show ? (m.classList.remove('hidden'), m.classList.add('flex'), setTimeout(() => lucide.createIcons(), 50)) :
                   (m.classList.add('hidden'), m.classList.remove('flex'));
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') { closeAbsenModal(); closeDeleteModal(); }
        });
    </script>
    <script src="../layout/index.js"></script>
</body>

</html>