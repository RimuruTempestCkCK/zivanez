<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'AdminP') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

$msg     = '';
$msgType = '';

// Potongan per hari tidak hadir
define('POTONGAN_PER_HARI', 50000);
define('GAJI_POKOK_DEFAULT', 1500000);

/**
 * Hitung jumlah hari tidak hadir (Alpha/Izin/Sakit) berdasarkan NIK + bulan + tahun
 * Mengembalikan array ['hari_tidak_hadir' => int, 'potongan' => int]
 */
function hitungPotongan($conn, $nik, $bulan, $tahun)
{
    $bulanAngka = array_search($bulan, [
        '',
        'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ]);

    if (!$bulanAngka) return ['hari_tidak_hadir' => 0, 'potongan' => 0];

    // Cari user id berdasarkan NIK
    $uStmt = $conn->prepare("SELECT id FROM users WHERE nik = ? LIMIT 1");
    $uStmt->bind_param("s", $nik);
    $uStmt->execute();
    $uRow = $uStmt->get_result()->fetch_assoc();
    $uStmt->close();

    if (!$uRow) return ['hari_tidak_hadir' => 0, 'potongan' => 0];

    $karyawan_id = $uRow['id'];

    // Hitung hari tidak hadir di bulan & tahun tsb
    $aStmt = $conn->prepare("
        SELECT COUNT(*) AS jml FROM absensi
        WHERE karyawan_id = ?
          AND MONTH(tanggal) = ?
          AND YEAR(tanggal) = ?
          AND status != 'Hadir'
    ");
    $aStmt->bind_param("iii", $karyawan_id, $bulanAngka, $tahun);
    $aStmt->execute();
    $hari_tidak_hadir = (int)$aStmt->get_result()->fetch_assoc()['jml'];
    $aStmt->close();

    return [
        'hari_tidak_hadir' => $hari_tidak_hadir,
        'potongan'         => $hari_tidak_hadir * POTONGAN_PER_HARI,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ===================== CREATE ===================== */
    if ($_POST['action'] === 'create') {

        $nama        = trim($_POST['nama']          ?? '');
        $nik         = trim($_POST['nik']           ?? '');
        $jabatan     = trim($_POST['jabatan']       ?? '');
        $jml_anggota = (int)($_POST['jml_anggota']  ?? 1);
        $gaji_pokok  = GAJI_POKOK_DEFAULT; // ← selalu 1.500.000
        $tunjangan   = (int)($_POST['tunjangan']    ?? 0);
        $lembur      = (int)($_POST['lembur']       ?? 0); // ← baru
        $bulan       = trim($_POST['bulan']         ?? '');
        $tahun       = (int)($_POST['tahun']        ?? date('Y'));
        $status      = trim($_POST['status']        ?? 'Belum Dibayar');
        $alamat      = trim($_POST['alamat']        ?? '');

        if ($nama === '' || $nik === '' || $jabatan === '') {
            $msg = "Nama, NIK, dan Jabatan wajib diisi";
            $msgType = 'error';
        } elseif ($bulan === '') {
            $msg = "Bulan wajib dipilih";
            $msgType = 'error';
        } else {
            $check = $conn->prepare("SELECT id FROM penggajian WHERE nik = ? AND bulan = ? AND tahun = ?");
            $check->bind_param("ssi", $nik, $bulan, $tahun);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $msg = "Data gaji untuk NIK ini pada bulan dan tahun yang sama sudah ada";
                $msgType = 'error';
            } else {
                $abs              = hitungPotongan($conn, $nik, $bulan, $tahun);
                $hari_tidak_hadir = $abs['hari_tidak_hadir'];
                $potongan         = $abs['potongan'];
                $total_gaji       = max(0, $gaji_pokok - $potongan + $tunjangan + $lembur); // ← + lembur

                $stmt = $conn->prepare("
                    INSERT INTO penggajian
                        (nama, nik, jabatan, jml_anggota, gaji_pokok, tunjangan, lembur, potongan, hari_tidak_hadir, total_gaji, bulan, tahun, status, alamat)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "sssiiiiiiisiss",
                    $nama,
                    $nik,
                    $jabatan,
                    $jml_anggota,
                    $gaji_pokok,
                    $tunjangan,
                    $lembur,
                    $potongan,
                    $hari_tidak_hadir,
                    $total_gaji,
                    $bulan,
                    $tahun,
                    $status,
                    $alamat
                );
                if ($stmt->execute()) {
                    $info = $hari_tidak_hadir > 0
                        ? " (Potongan: {$hari_tidak_hadir} hari × Rp 50.000 = Rp " . number_format($potongan, 0, ',', '.') . ")"
                        : "";
                    $msg = "Data penggajian berhasil ditambahkan{$info}";
                    $msgType = 'success';
                } else {
                    $msg = "Gagal menyimpan data: " . $stmt->error;
                    $msgType = 'error';
                }
                $stmt->close();
            }
            $check->close();
        }
    }

    /* ===================== UPDATE ===================== */
    if ($_POST['action'] === 'update') {

        $id          = (int)($_POST['id']           ?? 0);
        $nama        = trim($_POST['nama']          ?? '');
        $nik         = trim($_POST['nik']           ?? '');
        $jabatan     = trim($_POST['jabatan']       ?? '');
        $jml_anggota = (int)($_POST['jml_anggota']  ?? 1);
        $gaji_pokok  = GAJI_POKOK_DEFAULT; // ← selalu 1.500.000
        $tunjangan   = (int)($_POST['tunjangan']    ?? 0);
        $lembur      = (int)($_POST['lembur']       ?? 0); // ← baru
        $bulan       = trim($_POST['bulan']         ?? '');
        $tahun       = (int)($_POST['tahun']        ?? date('Y'));
        $status      = trim($_POST['status']        ?? 'Belum Dibayar');
        $alamat      = trim($_POST['alamat']        ?? '');

        if ($id === 0) {
            $msg = "ID tidak valid";
            $msgType = 'error';
        } elseif ($nama === '' || $nik === '' || $jabatan === '') {
            $msg = "Nama, NIK, dan Jabatan wajib diisi";
            $msgType = 'error';
        } elseif ($bulan === '') {
            $msg = "Bulan wajib dipilih";
            $msgType = 'error';
        } else {
            $check = $conn->prepare("SELECT id FROM penggajian WHERE nik = ? AND bulan = ? AND tahun = ? AND id != ?");
            $check->bind_param("ssii", $nik, $bulan, $tahun, $id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $msg = "Data gaji untuk NIK ini pada bulan dan tahun yang sama sudah ada";
                $msgType = 'error';
            } else {
                $abs              = hitungPotongan($conn, $nik, $bulan, $tahun);
                $hari_tidak_hadir = $abs['hari_tidak_hadir'];
                $potongan         = $abs['potongan'];
                $total_gaji       = max(0, $gaji_pokok - $potongan + $tunjangan + $lembur); // ← + lembur

                $stmt = $conn->prepare("
                    UPDATE penggajian
                    SET nama=?, nik=?, jabatan=?, jml_anggota=?,
                        gaji_pokok=?, tunjangan=?, lembur=?, potongan=?, hari_tidak_hadir=?, total_gaji=?,
                        bulan=?, tahun=?, status=?, alamat=?
                    WHERE id=?
                ");
                $stmt->bind_param(
                    "sssiiiiiiisissi",
                    $nama,
                    $nik,
                    $jabatan,
                    $jml_anggota,
                    $gaji_pokok,
                    $tunjangan,
                    $lembur,
                    $potongan,
                    $hari_tidak_hadir,
                    $total_gaji,
                    $bulan,
                    $tahun,
                    $status,
                    $alamat,
                    $id
                );
                if ($stmt->execute()) {
                    $info = $hari_tidak_hadir > 0
                        ? " (Potongan: {$hari_tidak_hadir} hari × Rp 50.000 = Rp " . number_format($potongan, 0, ',', '.') . ")"
                        : "";
                    $msg = "Data penggajian berhasil diperbarui{$info}";
                    $msgType = 'success';
                } else {
                    $msg = "Gagal update data: " . $stmt->error;
                    $msgType = 'error';
                }
                $stmt->close();
            }
            $check->close();
        }
    }

    /* ===================== DELETE ===================== */
    if ($_POST['action'] === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM penggajian WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $msg = "Data penggajian berhasil dihapus";
            $msgType = 'success';
        } else {
            $msg = "Gagal menghapus data penggajian";
            $msgType = 'error';
        }
        $stmt->close();
    }

    /* ===================== BULK DELETE ===================== */
    if ($_POST['action'] === 'bulk_delete') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $conn->query("DELETE FROM penggajian WHERE id IN (" . implode(',', $ids) . ")");
            $msg = count($ids) . " data penggajian berhasil dihapus";
            $msgType = 'success';
        }
    }
}

/* ── Fetch karyawan ── */
$karyawanList = [];
$res = $conn->query("SELECT id, nama_lengkap, nik, jabatan FROM users WHERE role = 'Karyawan' AND nama_lengkap IS NOT NULL AND nama_lengkap != '' ORDER BY nama_lengkap ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) $karyawanList[] = $row;
}

/* ── Fetch penggajian ── */
$search       = trim($_GET['search'] ?? '');
$filterBulan  = trim($_GET['bulan']  ?? '');
$filterTahun  = trim($_GET['tahun']  ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

$where  = "WHERE 1=1";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (nama LIKE ? OR nik LIKE ? OR jabatan LIKE ?)";
    $like   = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
if ($filterBulan  !== '') {
    $where .= " AND bulan = ?";
    $params[] = $filterBulan;
    $types .= "s";
}
if ($filterTahun  !== '') {
    $where .= " AND tahun = ?";
    $params[] = $filterTahun;
    $types .= "i";
}
if ($filterStatus !== '') {
    $where .= " AND status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}

$count = $conn->prepare("SELECT COUNT(*) total FROM penggajian $where");
if ($params) $count->bind_param($types, ...$params);
$count->execute();
$totalRows = $count->get_result()->fetch_assoc()['total'];
$count->close();

$stmt = $conn->prepare("
    SELECT * FROM penggajian $where
    ORDER BY tahun DESC,
             FIELD(bulan,'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember') DESC,
             created_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages   = max(1, ceil($totalRows / $perPage));
$bulanOptions = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Penggajian - Sanjai Zivanes</title>
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
        .float-select { @apply relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white; }
        .float-select label { @apply absolute left-12 text-secondary text-xs font-medium top-2 pointer-events-none; }
        .float-select select { @apply absolute bottom-0 inset-x-0 h-[38px] bg-transparent font-medium focus:outline-none pl-12 pr-10 text-foreground text-sm border-none w-full; }
        .float-select .icon { @apply absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary pointer-events-none; }
    </style>
</head>

<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">

        <!-- Header -->
        <div class="sticky top-0 z-30 flex items-center h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8 gap-4">
            <button onclick="toggleSidebar()" class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all cursor-pointer">
                <i data-lucide="menu" class="size-6"></i>
            </button>
            <div>
                <h2 class="font-bold text-2xl">Kelola Penggajian</h2>
                <p class="hidden sm:block text-sm text-secondary">Manajemen data gaji karyawan · Potongan Rp 50.000/hari tidak hadir</p>
            </div>
        </div>

        <div class="flex-1 p-5 md:p-8">

            <!-- Filter -->
            <form method="GET" id="filterForm">
                <div class="flex flex-col md:flex-row gap-3 mb-6">
                    <div class="relative flex-1">
                        <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="Cari nama / NIK / jabatan..."
                            class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none transition-all">
                    </div>
                    <select name="bulan" onchange="this.form.submit()" class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                        <option value="">Semua Bulan</option>
                        <?php foreach ($bulanOptions as $b): ?>
                            <option value="<?= $b ?>" <?= $filterBulan === $b ? 'selected' : '' ?>><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="tahun" onchange="this.form.submit()" class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                        <option value="">Semua Tahun</option>
                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?= $y ?>" <?= $filterTahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="status" onchange="this.form.submit()" class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                        <option value="">Semua Status</option>
                        <option value="Sudah Dibayar" <?= $filterStatus === 'Sudah Dibayar' ? 'selected' : '' ?>>Sudah Dibayar</option>
                        <option value="Menunggu" <?= $filterStatus === 'Menunggu'      ? 'selected' : '' ?>>Menunggu</option>
                        <option value="Belum Dibayar" <?= $filterStatus === 'Belum Dibayar' ? 'selected' : '' ?>>Belum Dibayar</option>
                    </select>
                    <button type="button" onclick="openAddModal()"
                        class="px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 flex items-center gap-2 transition-all cursor-pointer whitespace-nowrap">
                        <i data-lucide="plus" class="size-5"></i> Tambah Data
                    </button>
                </div>
            </form>

            <!-- Tabel -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1200px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="px-5 py-4 w-10">
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="size-4 rounded cursor-pointer accent-primary">
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nama / NIK</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jabatan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jml Anggota</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Gaji Pokok</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tunjangan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Lembur</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tdk Hadir</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Potongan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total Gaji</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Periode</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="12">
                                        <div class="py-16 flex flex-col items-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="search-x" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold">Data tidak ditemukan</p>
                                            <p class="text-sm text-secondary">Belum ada data penggajian. Klik "Tambah Data" untuk mulai.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr class="row-hover border-b border-border transition-colors">
                                        <td class="px-5 py-4">
                                            <input type="checkbox" class="row-check size-4 rounded cursor-pointer accent-primary" value="<?= (int)$r['id'] ?>">
                                        </td>
                                        <td class="px-5 py-4">
                                            <p class="font-semibold text-sm"><?= htmlspecialchars($r['nama']) ?></p>
                                            <p class="text-xs text-secondary font-mono"><?= htmlspecialchars($r['nik']) ?></p>
                                        </td>
                                        <td class="px-5 py-4 text-secondary text-sm"><?= htmlspecialchars($r['jabatan']) ?></td>
                                        <td class="px-5 py-4 text-center">
                                            <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary/10 text-primary text-sm font-bold">
                                                <?= (int)$r['jml_anggota'] ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-4 font-mono text-sm text-secondary">Rp <?= number_format($r['gaji_pokok'], 0, ',', '.') ?></td>
                                        <td class="px-5 py-4 font-mono text-sm text-secondary">Rp <?= number_format($r['tunjangan'], 0, ',', '.') ?></td>
                                        <td class="px-5 py-4 font-mono text-sm <?= (int)$r['lembur'] > 0 ? 'text-success font-semibold' : 'text-secondary' ?>">
                                            <?= (int)$r['lembur'] > 0 ? '+ Rp ' . number_format($r['lembur'], 0, ',', '.') : 'Rp 0' ?>
                                        </td>
                                        <td class="px-5 py-4 text-center">
                                            <?php if ((int)$r['hari_tidak_hadir'] > 0): ?>
                                                <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-full bg-error/10 text-error text-xs font-bold">
                                                    <?= (int)$r['hari_tidak_hadir'] ?> hari
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-full bg-success/10 text-success text-xs font-bold">
                                                    Full
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4 font-mono text-sm <?= (int)$r['potongan'] > 0 ? 'text-error font-semibold' : 'text-secondary' ?>">
                                            <?= (int)$r['potongan'] > 0 ? '- Rp ' . number_format($r['potongan'], 0, ',', '.') : 'Rp 0' ?>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span class="font-bold text-primary font-mono text-sm">Rp <?= number_format($r['total_gaji'], 0, ',', '.') ?></span>
                                        </td>
                                        <td class="px-5 py-4">
                                            <p class="text-sm font-medium"><?= htmlspecialchars($r['bulan']) ?></p>
                                            <p class="text-xs text-secondary"><?= htmlspecialchars($r['tahun']) ?></p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <?php if ($r['status'] === 'Sudah Dibayar'): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-success/10 text-success text-xs font-bold">
                                                    <span class="size-1.5 rounded-full bg-success mr-1.5"></span>Sudah Dibayar
                                                </span>
                                            <?php elseif ($r['status'] === 'Menunggu'): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-warning/20 text-yellow-700 text-xs font-bold">
                                                    <span class="size-1.5 rounded-full bg-warning mr-1.5"></span>Menunggu
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-error/10 text-error text-xs font-bold">
                                                    <span class="size-1.5 rounded-full bg-error mr-1.5"></span>Belum Dibayar
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"
                                                    class="size-9 flex items-center justify-center rounded-lg bg-primary/10 hover:bg-primary text-primary hover:text-white transition-all cursor-pointer">
                                                    <i data-lucide="pencil" class="size-4"></i>
                                                </button>
                                                <button type="button" onclick="confirmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['nama'], ENT_QUOTES) ?>')"
                                                    class="size-9 flex items-center justify-center rounded-lg bg-error/10 hover:bg-error text-error hover:text-white transition-all cursor-pointer">
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
                    <div class="flex items-center gap-3">
                        <p class="text-sm text-secondary">
                            Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                            dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> data
                        </p>
                        <div id="bulkActions" class="hidden items-center gap-2">
                            <span class="text-secondary">|</span>
                            <button type="button" onclick="bulkDelete()" class="text-xs font-semibold text-error hover:underline cursor-pointer flex items-center gap-1">
                                <i data-lucide="trash-2" class="size-3"></i> Hapus Terpilih
                            </button>
                        </div>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&bulan=<?= urlencode($filterBulan) ?>&tahun=<?= urlencode($filterTahun) ?>&status=<?= urlencode($filterStatus) ?>"
                                    class="p-2 rounded-lg border border-border hover:ring-1 hover:ring-primary transition-all">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&bulan=<?= urlencode($filterBulan) ?>&tahun=<?= urlencode($filterTahun) ?>&status=<?= urlencode($filterStatus) ?>"
                                    class="size-9 flex items-center justify-center rounded-lg border text-sm font-semibold transition-all
                            <?= $i == $page ? 'bg-primary/10 border-primary/20 text-primary' : 'border-border hover:bg-primary/10 hover:text-primary' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&bulan=<?= urlencode($filterBulan) ?>&tahun=<?= urlencode($filterTahun) ?>&status=<?= urlencode($filterStatus) ?>"
                                    class="p-2 rounded-lg border border-border hover:ring-1 hover:ring-primary transition-all">
                                    <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- ═══ MODAL TAMBAH / EDIT ═══ -->
    <div id="add-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-2xl shadow-2xl">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="font-bold text-xl" id="modalTitle">Tambah Data Penggajian</h3>
                <button onclick="closeAddModal()" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>

            <form id="crudForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formId" value="">

                <div class="p-6 space-y-4 overflow-y-auto max-h-[65vh]">
                    <div class="grid grid-cols-2 gap-4">

                        <!-- Pilih Karyawan -->
                        <div class="col-span-2" id="karyawanPickerWrap">
                            <label class="block text-xs font-medium text-secondary mb-1.5">Pilih Karyawan (isi otomatis)</label>
                            <div class="relative">
                                <i data-lucide="users" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary pointer-events-none"></i>
                                <select id="karyawanPicker" class="w-full h-12 pl-12 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                                    <option value="">— Pilih karyawan atau isi manual —</option>
                                    <?php foreach ($karyawanList as $k): ?>
                                        <option value="<?= htmlspecialchars($k['id']) ?>"
                                            data-nama="<?= htmlspecialchars($k['nama_lengkap']) ?>"
                                            data-nik="<?= htmlspecialchars($k['nik'] ?? '') ?>"
                                            data-jabatan="<?= htmlspecialchars($k['jabatan'] ?? '') ?>">
                                            <?= htmlspecialchars($k['nama_lengkap']) ?>
                                            <?= $k['nik']     ? ' — ' . htmlspecialchars($k['nik'])                   : '' ?>
                                            <?= $k['jabatan'] ? ' (' . htmlspecialchars($k['jabatan']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Info potongan otomatis -->
                        <div class="col-span-2">
                            <div class="flex items-start gap-2 px-4 py-3 rounded-xl bg-warning/10 border border-warning/30 text-yellow-700 text-xs font-medium">
                                <i data-lucide="info" class="size-4 shrink-0 mt-0.5"></i>
                                <span>Potongan dihitung otomatis dari data absensi bulan yang dipilih. Setiap hari tidak hadir (Alpha/Izin/Sakit) akan dipotong <strong>Rp 50.000</strong>.</span>
                            </div>
                        </div>

                        <!-- Nama -->
                        <div class="col-span-2 relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="user" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputNama" name="nama" type="text" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm">
                            <label for="inputNama" class="absolute left-12 text-secondary text-xs font-medium top-2">Nama Lengkap *</label>
                        </div>

                        <!-- NIK -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="credit-card" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputNik" name="nik" type="text" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm">
                            <label for="inputNik" class="absolute left-12 text-secondary text-xs font-medium top-2">NIK *</label>
                        </div>

                        <!-- Jabatan -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="briefcase" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputJabatan" name="jabatan" type="text" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm">
                            <label for="inputJabatan" class="absolute left-12 text-secondary text-xs font-medium top-2">Jabatan *</label>
                        </div>

                        <!-- Jumlah Anggota -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="users" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputJmlAnggota" name="jml_anggota" type="number" min="1" value="1" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm">
                            <label for="inputJmlAnggota" class="absolute left-12 text-secondary text-xs font-medium top-2">Jumlah Anggota *</label>
                        </div>

                        <!-- Gaji Pokok -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border bg-muted/50 transition-all">
                            <i data-lucide="banknote" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputGajiPokok" name="gaji_pokok" type="number" value="1500000" readonly
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm text-secondary cursor-not-allowed">
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">Gaji Pokok (Rp) — Tetap</label>
                        </div>

                        <!-- Tunjangan -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="wallet" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputTunjangan" name="tunjangan" type="number" min="0" value="0" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm">
                            <label for="inputTunjangan" class="absolute left-12 text-secondary text-xs font-medium top-2">Tunjangan (Rp)</label>
                        </div>

                        <!-- Lembur -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="clock" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputLembur" name="lembur" type="number" min="0" value="0" placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm">
                            <label for="inputLembur" class="absolute left-12 text-secondary text-xs font-medium top-2">Lembur (Rp)</label>
                        </div>

                        <!-- Bulan -->
                        <div class="float-select">
                            <span class="icon"><i data-lucide="calendar" class="size-5"></i></span>
                            <label for="inputBulan">Bulan *</label>
                            <select id="inputBulan" name="bulan" required>
                                <option value="">— Pilih Bulan —</option>
                                <?php foreach ($bulanOptions as $b): ?>
                                    <option value="<?= $b ?>"><?= $b ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Tahun -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="calendar-days" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputTahun" name="tahun" type="number" min="2020" max="2100" value="<?= date('Y') ?>" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-sm">
                            <label for="inputTahun" class="absolute left-12 text-secondary text-xs font-medium top-2">Tahun *</label>
                        </div>

                        <!-- Status -->
                        <div class="float-select">
                            <span class="icon"><i data-lucide="check-circle" class="size-5"></i></span>
                            <label for="inputStatus">Status *</label>
                            <select id="inputStatus" name="status" required>
                                <option value="Belum Dibayar">Belum Dibayar</option>
                                <option value="Menunggu">Menunggu</option>
                                <option value="Sudah Dibayar">Sudah Dibayar</option>
                            </select>
                        </div>

                        <!-- Alamat -->
                        <div class="col-span-2 relative rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white pt-6 pb-2">
                            <i data-lucide="map-pin" class="absolute left-4 top-4 size-5 text-secondary"></i>
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">Alamat</label>
                            <textarea id="inputAlamat" name="alamat" rows="2"
                                class="w-full bg-transparent font-medium focus:outline-none pl-12 pr-4 text-sm resize-none" placeholder="Masukkan alamat..."></textarea>
                        </div>

                    </div>
                </div>

                <div class="p-6 border-t border-border flex gap-3">
                    <button type="button" onclick="closeAddModal()"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit"
                        class="flex-1 py-3.5 rounded-full bg-primary text-white font-bold hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all cursor-pointer text-sm">
                        Simpan Data
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══ MODAL HAPUS ═══ -->
    <div id="delete-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-sm shadow-2xl">
            <div class="p-8 flex flex-col items-center gap-4 text-center">
                <div class="size-16 rounded-2xl bg-error/10 flex items-center justify-center">
                    <i data-lucide="trash-2" class="size-8 text-error"></i>
                </div>
                <div>
                    <h3 class="font-bold text-xl">Hapus Data</h3>
                    <p class="text-sm text-secondary mt-2">Hapus data penggajian <strong id="deleteNama"></strong>? Tindakan ini tidak dapat dibatalkan.</p>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId" value="">
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

    <!-- Bulk form (hidden) -->
    <form id="bulkForm" method="POST">
        <input type="hidden" name="action" id="bulkAction" value="">
        <div id="bulkIdsContainer"></div>
    </form>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] hidden items-center gap-3 px-5 py-4 rounded-2xl shadow-xl border border-border bg-white max-w-sm">
        <div id="toastIcon" class="size-9 rounded-xl flex items-center justify-center shrink-0"></div>
        <p id="toastMsg" class="font-semibold text-sm"></p>
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

            document.getElementById('karyawanPicker').addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                if (this.value) {
                    document.getElementById('inputNama').value = opt.dataset.nama || '';
                    document.getElementById('inputNik').value = opt.dataset.nik || '';
                    document.getElementById('inputJabatan').value = opt.dataset.jabatan || '';
                } else {
                    document.getElementById('inputNama').value = '';
                    document.getElementById('inputNik').value = '';
                    document.getElementById('inputJabatan').value = '';
                }
            });
        });

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

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            const msg = document.getElementById('toastMsg');
            const cfg = {
                success: {
                    bg: 'bg-success/10',
                    text: 'text-success',
                    icon: 'check-circle'
                },
                error: {
                    bg: 'bg-error/10',
                    text: 'text-error',
                    icon: 'x-circle'
                },
                info: {
                    bg: 'bg-primary/10',
                    text: 'text-primary',
                    icon: 'info'
                },
            };
            const c = cfg[type] || cfg.success;
            icon.className = `size-9 rounded-xl flex items-center justify-center shrink-0 ${c.bg} ${c.text}`;
            icon.innerHTML = `<i data-lucide="${c.icon}" class="size-5"></i>`;
            msg.textContent = message;
            toast.classList.remove('hidden');
            toast.classList.add('flex');
            lucide.createIcons();
            setTimeout(() => {
                toast.classList.add('hidden');
                toast.classList.remove('flex');
            }, 4500);
        }

        function toggleSelectAll(master) {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = master.checked);
            updateBulkBar();
        }
        document.addEventListener('change', e => {
            if (e.target.classList.contains('row-check')) updateBulkBar();
        });

        function updateBulkBar() {
            const checked = document.querySelectorAll('.row-check:checked').length;
            const bulk = document.getElementById('bulkActions');
            checked > 0 ? (bulk.classList.remove('hidden'), bulk.classList.add('flex')) :
                (bulk.classList.add('hidden'), bulk.classList.remove('flex'));
        }

        function bulkDelete() {
            const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
            if (!ids.length) return;
            if (!confirm(`Hapus ${ids.length} data penggajian? Tindakan ini tidak dapat dibatalkan.`)) return;
            document.getElementById('bulkAction').value = 'bulk_delete';
            const container = document.getElementById('bulkIdsContainer');
            container.innerHTML = '';
            ids.forEach(id => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'ids[]';
                inp.value = id;
                container.appendChild(inp);
            });
            document.getElementById('bulkForm').submit();
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Data Penggajian';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
            document.getElementById('crudForm').reset();
            document.getElementById('karyawanPickerWrap').classList.remove('hidden');
            toggleModal('add-modal', true);
        }

        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Edit Data Penggajian';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formId').value = data.id;
            document.getElementById('karyawanPickerWrap').classList.add('hidden');
            document.getElementById('karyawanPicker').value = '';
            document.getElementById('inputNama').value = data.nama || '';
            document.getElementById('inputNik').value = data.nik || '';
            document.getElementById('inputJabatan').value = data.jabatan || '';
            document.getElementById('inputJmlAnggota').value = data.jml_anggota || 1;
            document.getElementById('inputGajiPokok').value = 1500000; // selalu tetap
            document.getElementById('inputTunjangan').value = data.tunjangan || 0;
            document.getElementById('inputLembur').value = data.lembur || 0; // ← tambah ini
            document.getElementById('inputBulan').value = data.bulan || '';
            document.getElementById('inputTahun').value = data.tahun || new Date().getFullYear();
            document.getElementById('inputStatus').value = data.status || 'Belum Dibayar';
            document.getElementById('inputAlamat').value = data.alamat || '';
            toggleModal('add-modal', true);
        }

        function closeAddModal() {
            toggleModal('add-modal', false);
        }

        function closeDeleteModal() {
            toggleModal('delete-modal', false);
        }

        function confirmDelete(id, nama) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteNama').textContent = nama;
            toggleModal('delete-modal', true);
        }

        function toggleModal(id, show) {
            const m = document.getElementById(id);
            if (show) {
                m.classList.remove('hidden');
                m.classList.add('flex');
                setTimeout(() => lucide.createIcons(), 50);
            } else {
                m.classList.add('hidden');
                m.classList.remove('flex');
            }
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeAddModal();
                closeDeleteModal();
            }
        });
    </script>
    <script src="../layout/index.js"></script>
</body>

</html>