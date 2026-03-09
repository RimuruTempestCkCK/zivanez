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

// Auto-generate no setoran
function generateNoSetoran($conn)
{
    $prefix  = 'SET-' . date('Ymd') . '-';
    $res     = $conn->query("SELECT COUNT(*) FROM setoran WHERE no_setoran LIKE '{$prefix}%'");
    $count   = (int)$res->fetch_row()[0];
    $nextSeq = $count + 1;
    do {
        $candidate = $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
        $chk = $conn->prepare("SELECT id FROM setoran WHERE no_setoran = ? LIMIT 1");
        $chk->bind_param("s", $candidate);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if ($exists) $nextSeq++;
    } while ($exists);
    return $candidate;
}

// Total omset hari ini untuk cabang ini (referensi otomatis)
function getTotalHariIni($conn, $cabangId)
{
    if (!$cabangId) return 0;
    $today = date('Y-m-d');
    $res = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM transaksi WHERE cabang_id=? AND tanggal=? AND status='Selesai'");
    $res->bind_param("is", $cabangId, $today);
    $res->execute();
    return (int)$res->get_result()->fetch_row()[0];
}

// ── UPLOAD FOLDER ─────────────────────────────────────────
$uploadDir = __DIR__ . '/../uploads/setoran/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── CRUD HANDLER ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ===================== CREATE ===================== */
    if ($_POST['action'] === 'create') {
        $no_setoran      = generateNoSetoran($conn);
        $tanggalInput = trim($_POST['tanggal'] ?? '');

        if (!$tanggalInput) {
            $tanggal = date('Y-m-d');
        } else {
            $d = DateTime::createFromFormat('Y-m-d', $tanggalInput);
            $tanggal = ($d && $d->format('Y-m-d') === $tanggalInput)
                ? $tanggalInput
                : date('Y-m-d');
        }
        $jumlah_setoran  = (int)($_POST['jumlah_setoran'] ?? 0);
        $total_transaksi = (int)($_POST['total_transaksi'] ?? 0);
        $keterangan      = trim($_POST['keterangan']      ?? '');

        if ($cabangId === 0) {
            $msg = "Anda belum terhubung ke cabang manapun";
            $msgType = 'error';
        } elseif ($jumlah_setoran <= 0) {
            $msg = "Jumlah setoran harus lebih dari 0";
            $msgType = 'error';
        } else {
            // Upload foto
            $bukti_foto = null;
            if (!empty($_FILES['bukti_foto']['name'])) {
                $file     = $_FILES['bukti_foto'];
                $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed  = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                $maxSize  = 5 * 1024 * 1024; // 5 MB

                if (!in_array($ext, $allowed)) {
                    $msg = "Format file tidak didukung. Gunakan JPG, PNG, WEBP, atau PDF.";
                    $msgType = 'error';
                } elseif ($file['size'] > $maxSize) {
                    $msg = "Ukuran file melebihi batas 5 MB.";
                    $msgType = 'error';
                } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                    $msg = "Gagal mengupload file. Kode error: " . $file['error'];
                    $msgType = 'error';
                } else {
                    $newName    = 'SET-' . date('YmdHis') . '-' . uniqid() . '.' . $ext;
                    $targetPath = $uploadDir . $newName;
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $bukti_foto = $newName;
                    } else {
                        $msg = "Gagal memindahkan file upload.";
                        $msgType = 'error';
                    }
                }
            }

            if ($msgType !== 'error') {
                $stmt = $conn->prepare("
                    INSERT INTO setoran
                        (no_setoran, cabang_id, admin_id, tanggal, jumlah_setoran, total_transaksi, keterangan, bukti_foto)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "siisiiss",
                    $no_setoran,
                    $cabangId,
                    $adminId,
                    $tanggal,
                    $jumlah_setoran,
                    $total_transaksi,
                    $keterangan,
                    $bukti_foto
                );

                if ($stmt->execute()) {
                    $msg = "Setoran berhasil dikirim! No: $no_setoran";
                    $msgType = 'success';
                } else {
                    // Hapus file jika DB gagal
                    if ($bukti_foto && file_exists($uploadDir . $bukti_foto)) {
                        unlink($uploadDir . $bukti_foto);
                    }
                    $msg = "Gagal menyimpan setoran: " . $stmt->error;
                    $msgType = 'error';
                }
                $stmt->close();
            }
        }
    }

    /* ===================== DELETE ===================== */
    if ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Hanya bisa hapus jika masih Menunggu
        $chk = $conn->prepare("SELECT bukti_foto, status FROM setoran WHERE id=? AND cabang_id=? LIMIT 1");
        $chk->bind_param("ii", $id, $cabangId);
        $chk->execute();
        $rowDel = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$rowDel) {
            $msg = "Data tidak ditemukan";
            $msgType = 'error';
        } elseif ($rowDel['status'] !== 'Menunggu') {
            $msg = "Setoran yang sudah diverifikasi tidak dapat dihapus";
            $msgType = 'error';
        } else {
            $dl = $conn->prepare("DELETE FROM setoran WHERE id=? AND cabang_id=?");
            $dl->bind_param("ii", $id, $cabangId);
            if ($dl->execute()) {
                // Hapus file bukti
                if ($rowDel['bukti_foto'] && file_exists($uploadDir . $rowDel['bukti_foto'])) {
                    unlink($uploadDir . $rowDel['bukti_foto']);
                }
                $msg = "Setoran berhasil dihapus";
                $msgType = 'success';
            } else {
                $msg = "Gagal menghapus setoran";
                $msgType = 'error';
            }
            $dl->close();
        }
    }
}

/* ===================== FETCH DATA ===================== */
$search     = trim($_GET['search'] ?? '');
$filterStat = trim($_GET['status'] ?? '');
$filterBulan = trim($_GET['bulan']  ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 10;
$offset     = ($page - 1) * $perPage;

$where  = "WHERE s.cabang_id = ?";
$params = [$cabangId];
$types  = "i";

if ($search !== '') {
    $where   .= " AND (s.no_setoran LIKE ? OR s.keterangan LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types   .= "ss";
}
if ($filterStat !== '') {
    $where   .= " AND s.status = ?";
    $params[] = $filterStat;
    $types .= "s";
}
if ($filterBulan !== '') {
    $where   .= " AND DATE_FORMAT(s.tanggal, '%Y-%m') = ?";
    $params[] = $filterBulan;
    $types .= "s";
}

$cntStmt = $conn->prepare("SELECT COUNT(*) FROM setoran s $where");
$cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

$sql = "
    SELECT s.*
    FROM setoran s
    $where
    ORDER BY s.tanggal DESC, s.created_at DESC
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

// ── Stats ─────────────────────────────────────────────────
$statTotal    = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM setoran WHERE cabang_id=$cabangId")->fetch_row()[0] : 0;
$statMenunggu = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM setoran WHERE cabang_id=$cabangId AND status='Menunggu'")->fetch_row()[0] : 0;
$statDiterima = $cabangId ? (int)$conn->query("SELECT COUNT(*) FROM setoran WHERE cabang_id=$cabangId AND status='Diterima'")->fetch_row()[0] : 0;
$statNominal  = $cabangId ? (int)$conn->query("SELECT COALESCE(SUM(jumlah_setoran),0) FROM setoran WHERE cabang_id=$cabangId AND status='Diterima'")->fetch_row()[0] : 0;

$nextNo       = generateNoSetoran($conn);
$totalHariIni = getTotalHariIni($conn, $cabangId);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setoran Harian - Sanjai Zivanes</title>
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
        .float-select-wrap { @apply relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white; }
        .float-select-wrap label { @apply absolute left-12 text-secondary text-xs font-medium top-2 pointer-events-none; }
        .float-select-wrap select { @apply absolute bottom-0 inset-x-0 h-[38px] bg-transparent font-medium focus:outline-none pl-12 pr-10 text-foreground text-sm border-none w-full; }
        .float-select-wrap .icon { @apply absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary pointer-events-none; }
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
                    <h2 class="font-bold text-2xl text-foreground">Setoran Harian</h2>
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

            <!-- Omset hari ini info bar -->
            <?php if ($cabangSaya && $totalHariIni > 0): ?>
                <div class="mb-6 flex items-center gap-4 p-4 rounded-2xl bg-primary/5 border border-primary/20">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <i data-lucide="trending-up" class="size-5 text-primary"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-foreground text-sm">Omset hari ini (transaksi Selesai)</p>
                        <p class="text-lg font-bold text-primary">Rp <?= number_format($totalHariIni, 0, ',', '.') ?></p>
                    </div>
                    <button type="button" onclick="openAddModal()"
                        class="shrink-0 px-4 py-2 rounded-full bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors cursor-pointer flex items-center gap-2">
                        <i data-lucide="plus" class="size-3.5"></i>
                        Setor Sekarang
                    </button>
                </div>
            <?php endif; ?>

            <!-- Toolbar -->
            <form method="GET" id="filterForm">
                <div class="flex flex-col md:flex-row gap-4 justify-between items-center mb-6">
                    <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-1 max-w-2xl">
                        <div class="relative flex-1 group">
                            <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary group-focus-within:text-primary transition-colors"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Cari no. setoran / keterangan..."
                                class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300">
                        </div>
                        <select name="status" onchange="this.form.submit()"
                            class="h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none min-w-[160px]">
                            <option value="">Semua Status</option>
                            <option value="Menunggu" <?= $filterStat === 'Menunggu'  ? 'selected' : '' ?>>Menunggu</option>
                            <option value="Diterima" <?= $filterStat === 'Diterima'  ? 'selected' : '' ?>>Diterima</option>
                            <option value="Ditolak" <?= $filterStat === 'Ditolak'   ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                        <input type="month" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>"
                            onchange="this.form.submit()"
                            class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div class="flex gap-3 w-full md:w-auto">
                        <button type="button" onclick="openAddModal()" <?= !$cabangSaya ? 'disabled' : '' ?>
                            class="flex-1 md:flex-none px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 hover:shadow-primary/40 flex items-center justify-center gap-2 transition-all duration-300 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <i data-lucide="plus" class="size-5"></i>
                            <span>Tambah Setoran</span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Table -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[900px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No. Setoran</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jumlah Setoran</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total Omset</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Bukti</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keterangan</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="py-16 flex flex-col items-center justify-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="send" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Belum ada setoran</p>
                                            <p class="text-sm text-secondary">Klik "Tambah Setoran" untuk mencatat setoran harian.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r):
                                    $stCfg = [
                                        'Menunggu' => ['bg-warning/20',  'text-yellow-700', 'clock'],
                                        'Diterima' => ['bg-success/10',  'text-success',    'check-circle'],
                                        'Ditolak'  => ['bg-error/10',    'text-error',      'x-circle'],
                                    ];
                                    [$sBg, $sTx, $sIco] = $stCfg[$r['status']] ?? ['bg-secondary/10', 'text-secondary', 'circle'];
                                    $ext = strtolower(pathinfo($r['bukti_foto'] ?? '', PATHINFO_EXTENSION));
                                    $isPdf = ($ext === 'pdf');
                                ?>
                                    <tr class="table-row-hover border-b border-border transition-colors duration-150">
                                        <!-- No Setoran -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                                <?= htmlspecialchars($r['no_setoran']) ?>
                                            </span>
                                        </td>
                                        <!-- Tanggal -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm font-semibold text-foreground"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></p>
                                            <p class="text-xs text-secondary"><?= date('l', strtotime($r['tanggal'])) ?></p>
                                        </td>
                                        <!-- Jumlah Setoran -->
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-foreground text-sm font-mono">Rp <?= number_format($r['jumlah_setoran'], 0, ',', '.') ?></p>
                                        </td>
                                        <!-- Total Omset -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm text-secondary font-mono">Rp <?= number_format($r['total_transaksi'], 0, ',', '.') ?></p>
                                        </td>
                                        <!-- Bukti Foto -->
                                        <td class="px-5 py-4">
                                            <?php if ($r['bukti_foto']): ?>
                                                <?php if ($isPdf): ?>
                                                    <a href="../uploads/setoran/<?= htmlspecialchars($r['bukti_foto']) ?>" target="_blank"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-error/10 text-error text-xs font-semibold hover:bg-error/20 transition-colors">
                                                        <i data-lucide="file-text" class="size-3.5"></i> Lihat PDF
                                                    </a>
                                                <?php else: ?>
                                                    <button type="button"
                                                        onclick="previewFoto('../uploads/setoran/<?= htmlspecialchars($r['bukti_foto']) ?>')"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary/10 text-primary text-xs font-semibold hover:bg-primary/20 transition-colors cursor-pointer">
                                                        <i data-lucide="image" class="size-3.5"></i> Lihat Foto
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-secondary text-xs italic">Tidak ada</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Status -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full <?= $sBg ?> <?= $sTx ?> text-xs font-bold">
                                                <i data-lucide="<?= $sIco ?>" class="size-3"></i>
                                                <?= htmlspecialchars($r['status']) ?>
                                            </span>
                                            <?php if ($r['status'] === 'Ditolak' && $r['catatan_penolakan']): ?>
                                                <p class="text-xs text-error mt-1 max-w-[140px] truncate" title="<?= htmlspecialchars($r['catatan_penolakan']) ?>">
                                                    "<?= htmlspecialchars($r['catatan_penolakan']) ?>"
                                                </p>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Keterangan -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm text-secondary max-w-[160px] truncate" title="<?= htmlspecialchars($r['keterangan'] ?? '') ?>">
                                                <?= $r['keterangan'] ? htmlspecialchars($r['keterangan']) : '—' ?>
                                            </p>
                                        </td>
                                        <!-- Aksi -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <?php if ($r['status'] === 'Menunggu'): ?>
                                                    <button type="button"
                                                        onclick="confirmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['no_setoran'], ENT_QUOTES) ?>')"
                                                        title="Batalkan / Hapus"
                                                        class="size-9 flex items-center justify-center rounded-lg bg-error/10 hover:bg-error text-error hover:text-white transition-all duration-200 cursor-pointer">
                                                        <i data-lucide="trash-2" class="size-4"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-xs text-secondary italic px-2">—</span>
                                                <?php endif; ?>
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
                        dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> setoran
                    </p>
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>"
                                    class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>"
                                    class="size-9 flex items-center justify-center rounded-lg border <?= $i == $page ? 'bg-primary/10 border-primary/20 font-semibold text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?> text-sm transition-all cursor-pointer">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>"
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

    <!-- ===================== MODAL TAMBAH SETORAN ===================== -->
    <div id="add-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="font-bold text-xl text-foreground">Kirim Setoran Harian</h3>
                <button onclick="closeAddModal()" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>

            <form id="setoranForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create">

                <div class="p-6 space-y-4 overflow-y-auto max-h-[65vh]">

                    <!-- No Setoran (auto) -->
                    <div class="relative h-[60px] rounded-2xl ring-1 ring-border bg-muted/40">
                        <i data-lucide="hash" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input id="inputNoSetoran" type="text" readonly value="<?= htmlspecialchars($nextNo) ?>"
                            class="absolute inset-0 w-full h-full bg-transparent font-bold focus:outline-none pl-12 pt-5 pb-1 text-primary text-sm font-mono cursor-default">
                        <label class="absolute left-12 text-secondary text-xs font-medium top-2">No. Setoran <span class="text-success font-bold">(otomatis)</span></label>
                    </div>

                    <!-- Tanggal -->
                    <div class="float-wrap">
                        <span class="fi"><i data-lucide="calendar" class="size-5 text-secondary"></i></span>
                        <label for="inputTanggal">Tanggal Setoran *</label>
                        <input id="inputTanggal" name="tanggal" type="date" required>
                    </div>

                    <!-- Jumlah Setoran -->
                    <div class="float-wrap">
                        <span class="fi"><i data-lucide="banknote" class="size-5 text-secondary"></i></span>
                        <label for="inputJumlah">Jumlah Setoran (Rp) *</label>
                        <input id="inputJumlah" name="jumlah_setoran" type="number" min="1" required>
                    </div>

                    <!-- Total Omset (referensi, bisa diubah) -->
                    <div class="float-wrap">
                        <span class="fi"><i data-lucide="trending-up" class="size-5 text-secondary"></i></span>
                        <label for="inputOmset">Total Omset Hari Ini (Rp)</label>
                        <input id="inputOmset" name="total_transaksi" type="number" min="0" value="<?= $totalHariIni ?>">
                    </div>

                    <!-- Upload Bukti Foto -->
                    <div>
                        <label class="block text-xs font-semibold text-secondary mb-2 pl-1">Bukti Setoran (Foto / PDF) *</label>
                        <label for="inputFoto"
                            class="flex flex-col items-center justify-center gap-3 w-full h-36 border-2 border-dashed border-border rounded-2xl cursor-pointer hover:border-primary hover:bg-primary/5 transition-all duration-200 group">
                            <div id="uploadPlaceholder" class="flex flex-col items-center gap-2">
                                <div class="size-10 rounded-xl bg-muted flex items-center justify-center group-hover:bg-primary/10 transition-colors">
                                    <i data-lucide="upload-cloud" class="size-5 text-secondary group-hover:text-primary transition-colors"></i>
                                </div>
                                <p class="text-sm font-medium text-secondary group-hover:text-primary transition-colors">Klik untuk upload foto / PDF</p>
                                <p class="text-xs text-secondary/70">JPG, PNG, WEBP, PDF — Max 5 MB</p>
                            </div>
                            <div id="uploadPreview" class="hidden flex-col items-center gap-2">
                                <img id="previewImg" src="" alt="preview" class="h-16 rounded-xl object-contain">
                                <p id="previewName" class="text-xs font-semibold text-primary"></p>
                            </div>
                            <input id="inputFoto" name="bukti_foto" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="hidden" onchange="handleFileChange(this)">
                        </label>
                    </div>

                    <!-- Keterangan -->
                    <div class="relative rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white pt-6 pb-2">
                        <i data-lucide="file-text" class="absolute left-4 top-4 size-5 text-secondary"></i>
                        <label class="absolute left-12 text-secondary text-xs font-medium top-2">Keterangan</label>
                        <textarea id="inputKeterangan" name="keterangan" rows="2"
                            class="w-full bg-transparent font-medium focus:outline-none pl-12 pr-4 text-foreground text-sm resize-none" placeholder="Catatan tambahan..."></textarea>
                    </div>

                </div>

                <div class="p-6 border-t border-border flex gap-3">
                    <button type="button" onclick="closeAddModal()"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit"
                        class="flex-1 py-3.5 rounded-full bg-primary text-white font-bold hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all cursor-pointer text-sm flex items-center justify-center gap-2">
                        <i data-lucide="send" class="size-4"></i>
                        Kirim Setoran
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
                    <h3 class="font-bold text-xl text-foreground">Batalkan Setoran</h3>
                    <p class="text-sm text-secondary mt-2">Hapus setoran <strong id="deleteNo"></strong>? Tindakan ini tidak dapat dibatalkan.</p>
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
                        Ya, Batalkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL PREVIEW FOTO ===================== -->
    <div id="foto-modal" class="fixed inset-0 bg-black/80 z-[200] hidden items-center justify-center p-4 backdrop-blur-sm" onclick="closeFotoModal()">
        <div class="relative max-w-2xl w-full" onclick="event.stopPropagation()">
            <button onclick="closeFotoModal()" class="absolute -top-4 -right-4 size-10 bg-white rounded-full flex items-center justify-center shadow-lg cursor-pointer hover:bg-muted transition-colors">
                <i data-lucide="x" class="size-5 text-foreground"></i>
            </button>
            <img id="fotoPreviewSrc" src="" alt="Bukti Setoran" class="w-full rounded-2xl shadow-2xl object-contain max-h-[80vh]">
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

        // ── Sidebar ───────────────────────────────────────────────
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (!sidebar) return;
            sidebar.classList.contains('-translate-x-full') ?
                (sidebar.classList.remove('-translate-x-full'), overlay?.classList.remove('hidden'), document.body.style.overflow = 'hidden') :
                (sidebar.classList.add('-translate-x-full'), overlay?.classList.add('hidden'), document.body.style.overflow = '');
        }

        // ── Toast ─────────────────────────────────────────────────
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
            }, 4000);
        }

        // ── Upload Preview ────────────────────────────────────────
        function handleFileChange(input) {
            const file = input.files[0];
            if (!file) return;

            const placeholder = document.getElementById('uploadPlaceholder');
            const preview = document.getElementById('uploadPreview');
            const previewImg = document.getElementById('previewImg');
            const previewName = document.getElementById('previewName');
            const ext = file.name.split('.').pop().toLowerCase();

            previewName.textContent = file.name;
            placeholder.classList.add('hidden');
            preview.classList.remove('hidden');
            preview.classList.add('flex');

            if (ext === 'pdf') {
                previewImg.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="%23ED6B60" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>';
            } else {
                const reader = new FileReader();
                reader.onload = e => {
                    previewImg.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        }

        // ── Add Modal ─────────────────────────────────────────────
        function openAddModal() {
            document.getElementById('setoranForm').reset();
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            document.getElementById('inputTanggal').value = `${yyyy}-${mm}-${dd}`;
            document.getElementById('uploadPlaceholder').classList.remove('hidden');
            document.getElementById('uploadPreview').classList.add('hidden');
            document.getElementById('uploadPreview').classList.remove('flex');
            toggleModal('add-modal', true);
        }

        function closeAddModal() {
            toggleModal('add-modal', false);
        }

        // ── Delete Modal ──────────────────────────────────────────
        function confirmDelete(id, no) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteNo').textContent = no;
            toggleModal('delete-modal', true);
        }

        function closeDeleteModal() {
            toggleModal('delete-modal', false);
        }

        // ── Foto Preview Modal ────────────────────────────────────
        function previewFoto(src) {
            document.getElementById('fotoPreviewSrc').src = src;
            toggleModal('foto-modal', true);
        }

        function closeFotoModal() {
            toggleModal('foto-modal', false);
        }

        // ── Modal Helper ──────────────────────────────────────────
        function toggleModal(id, show) {
            const m = document.getElementById(id);
            show ? (m.classList.remove('hidden'), m.classList.add('flex'), setTimeout(() => lucide.createIcons(), 50)) :
                (m.classList.add('hidden'), m.classList.remove('flex'));
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeAddModal();
                closeDeleteModal();
                closeFotoModal();
            }
        });
    </script>
    <script src="../layout/index.js"></script>
</body>

</html>