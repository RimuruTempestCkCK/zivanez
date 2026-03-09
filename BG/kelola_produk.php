<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'BG') {
    header("Location: ../login.php");
    exit;
}

// ── Koneksi Database ──────────────────────────────────────
require_once __DIR__ . '/../config.php';

// ── Helper Message ────────────────────────────────────────
$msg     = '';
$msgType = '';

// ── Fetch semua cabang untuk dropdown filter & form ───────
$allCabang = $conn->query("SELECT id, kode_cabang, nama_cabang FROM cabang ORDER BY kode_cabang ASC")
    ->fetch_all(MYSQLI_ASSOC);

// ── Auto-generate kode stok ───────────────────────────────
function generateKodeStok($conn)
{
    $prefix  = 'STK-' . date('Ymd') . '-';
    $res     = $conn->query("SELECT COUNT(*) FROM stok WHERE kode_stok LIKE '{$prefix}%'");
    $count   = (int)$res->fetch_row()[0];
    $nextSeq = $count + 1;
    do {
        $candidate = $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
        $chk = $conn->prepare("SELECT id FROM stok WHERE kode_stok = ? LIMIT 1");
        $chk->bind_param("s", $candidate);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if ($exists) $nextSeq++;
    } while ($exists);
    return $candidate;
}
$nextKode = generateKodeStok($conn);

// ── CRUD HANDLER ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ===================== CREATE ===================== */
    if ($_POST['action'] === 'create') {
        $kode_stok   = generateKodeStok($conn);
        $cabang_id   = (int)($_POST['cabang_id']   ?? 0);
        $nama_produk = trim($_POST['nama_produk']  ?? '');
        $kategori    = trim($_POST['kategori']     ?? '');
        $satuan      = trim($_POST['satuan']       ?? '');
        $stok_masuk  = (int)($_POST['stok_masuk']  ?? 0);
        $harga_beli  = (int)($_POST['harga_beli']  ?? 0);
        $harga_jual  = (int)($_POST['harga_jual']  ?? 0);
        $keterangan  = trim($_POST['keterangan']   ?? '');

        if ($cabang_id === 0 || $nama_produk === '' || $satuan === '' || $stok_masuk < 0) {
            $msg = "Field Cabang, Nama Produk, Satuan, dan Stok wajib diisi";
            $msgType = 'error';
        } else {
            $stmtC = $conn->prepare("
                INSERT INTO stok
                    (kode_stok, cabang_id, nama_produk, kategori, satuan,
                     stok_masuk, stok_keluar, harga_beli, harga_jual, keterangan)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
            ");
            // s i s    s        s      i         i          i         s
            $stmtC->bind_param(
                "sisssiiss",
                $kode_stok,
                $cabang_id,
                $nama_produk,
                $kategori,
                $satuan,
                $stok_masuk,
                $harga_beli,
                $harga_jual,
                $keterangan
            );
            if ($stmtC->execute()) {
                $msg = "Stok berhasil ditambahkan";
                $msgType = 'success';
            } else {
                $msg = "Gagal menyimpan: " . $stmtC->error;
                $msgType = 'error';
            }
            $stmtC->close();
        }
    }

    /* ===================== TAMBAH STOK KELUAR ===================== */
    if ($_POST['action'] === 'keluar') {
        $id         = (int)($_POST['id']      ?? 0);
        $jml_keluar = (int)($_POST['jumlah']  ?? 0);
        $ket        = trim($_POST['keterangan'] ?? '');

        if ($id === 0 || $jml_keluar <= 0) {
            $msg = "Jumlah keluar tidak valid";
            $msgType = 'error';
        } else {
            // Cek stok tersedia
            $cek = $conn->prepare("SELECT stok_tersedia FROM stok WHERE id = ?");
            $cek->bind_param("i", $id);
            $cek->execute();
            $tersedia = (int)($cek->get_result()->fetch_assoc()['stok_tersedia'] ?? 0);
            $cek->close();

            if ($jml_keluar > $tersedia) {
                $msg = "Stok keluar ($jml_keluar) melebihi stok tersedia ($tersedia)";
                $msgType = 'error';
            } else {
                $up = $conn->prepare("UPDATE stok SET stok_keluar = stok_keluar + ?, keterangan = ? WHERE id = ?");
                $up->bind_param("isi", $jml_keluar, $ket, $id);
                if ($up->execute()) {
                    $msg = "Stok keluar berhasil dicatat";
                    $msgType = 'success';
                } else {
                    $msg = "Gagal update stok";
                    $msgType = 'error';
                }
                $up->close();
            }
        }
    }

    /* ===================== UPDATE ===================== */
    if ($_POST['action'] === 'update') {
        $id          = (int)($_POST['id']          ?? 0);
        $cabang_id   = (int)($_POST['cabang_id']   ?? 0);
        $nama_produk = trim($_POST['nama_produk']  ?? '');
        $kategori    = trim($_POST['kategori']     ?? '');
        $satuan      = trim($_POST['satuan']       ?? '');
        $stok_masuk  = (int)($_POST['stok_masuk']  ?? 0);
        $stok_keluar = (int)($_POST['stok_keluar'] ?? 0);
        $harga_beli  = (int)($_POST['harga_beli']  ?? 0);
        $harga_jual  = (int)($_POST['harga_jual']  ?? 0);
        $keterangan  = trim($_POST['keterangan']   ?? '');

        if ($id === 0 || $cabang_id === 0 || $nama_produk === '') {
            $msg = "Data tidak valid";
            $msgType = 'error';
        } else {
            $up = $conn->prepare("
                UPDATE stok SET cabang_id=?, nama_produk=?, kategori=?, satuan=?,
                    stok_masuk=?, stok_keluar=?, harga_beli=?, harga_jual=?, keterangan=?
                WHERE id=?
            ");
            $up->bind_param(
                "isssiiiisi",
                $cabang_id,
                $nama_produk,
                $kategori,
                $satuan,
                $stok_masuk,
                $stok_keluar,
                $harga_beli,
                $harga_jual,
                $keterangan,
                $id
            );
            if ($up->execute()) {
                $msg = "Stok berhasil diperbarui";
                $msgType = 'success';
            } else {
                $msg = "Gagal update: " . $up->error;
                $msgType = 'error';
            }
            $up->close();
        }
    }

    /* ===================== DELETE ===================== */
    if ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $dl = $conn->prepare("DELETE FROM stok WHERE id = ?");
        $dl->bind_param("i", $id);
        if ($dl->execute()) {
            $msg = "Stok berhasil dihapus";
            $msgType = 'success';
        } else {
            $msg = "Gagal menghapus stok";
            $msgType = 'error';
        }
        $dl->close();
    }

    /* ===================== BULK DELETE ===================== */
    if ($_POST['action'] === 'bulk_delete') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $conn->query("DELETE FROM stok WHERE id IN (" . implode(',', $ids) . ")");
            $msg = count($ids) . " stok berhasil dihapus";
            $msgType = 'success';
        }
    }
}

/* ===================== FETCH DATA ===================== */
$search      = trim($_GET['search']    ?? '');
$filterCabang = (int)($_GET['cabang_id'] ?? 0);
$filterStok  = trim($_GET['stok_status'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 10;
$offset      = ($page - 1) * $perPage;

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($filterCabang > 0) {
    $where   .= " AND s.cabang_id = ?";
    $params[] = $filterCabang;
    $types .= "i";
}
if ($search !== '') {
    $where   .= " AND (s.kode_stok LIKE ? OR s.nama_produk LIKE ? OR s.kategori LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}
if ($filterStok === 'habis') {
    $where .= " AND s.stok_tersedia <= 0";
} elseif ($filterStok === 'menipis') {
    $where .= " AND s.stok_tersedia > 0 AND s.stok_tersedia <= 10";
} elseif ($filterStok === 'aman') {
    $where .= " AND s.stok_tersedia > 10";
}

// count
$cntStmt = $conn->prepare("SELECT COUNT(*) total FROM stok s $where");
if ($params) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = $cntStmt->get_result()->fetch_assoc()['total'];
$cntStmt->close();

// data
$sql = "
    SELECT s.*, c.nama_cabang, c.kode_cabang
    FROM stok s
    JOIN cabang c ON c.id = s.cabang_id
    $where
    ORDER BY s.created_at DESC
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
$statTotal   = (int)$conn->query("SELECT COUNT(*) FROM stok")->fetch_row()[0];
$statHabis   = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia <= 0")->fetch_row()[0];
$statMenipis = (int)$conn->query("SELECT COUNT(*) FROM stok WHERE stok_tersedia > 0 AND stok_tersedia <= 10")->fetch_row()[0];
$statNilai   = (int)$conn->query("SELECT COALESCE(SUM(stok_tersedia * harga_jual),0) FROM stok")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Stok - Sanjai Zivanes</title>
    <meta name="description" content="Kelola stok barang semua cabang Sanjai Zivanes.">
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
            --radius-card: 24px; --radius-button: 50px;
        }
        select { @apply appearance-none bg-no-repeat cursor-pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-position: right 10px center; padding-right: 40px; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .table-row-hover:hover { background-color: #F8FAFF; }
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
                    <h2 class="font-bold text-2xl text-foreground">Kelola Stok</h2>
                    <p class="hidden sm:block text-sm text-secondary">Manajemen stok barang seluruh cabang</p>
                </div>
            </div>
        </div>

        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <!-- Actions Toolbar -->
            <form method="GET" id="filterForm">
                <div class="flex flex-col md:flex-row gap-4 justify-between items-center mb-6">
                    <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-1 max-w-3xl">
                        <!-- Search -->
                        <div class="relative flex-1 group">
                            <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary group-focus-within:text-primary transition-colors"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Cari kode / nama produk..."
                                class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300">
                        </div>
                        <!-- Filter Cabang -->
                        <select name="cabang_id" onchange="this.form.submit()"
                            class="h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none min-w-[160px]">
                            <option value="0">Semua Cabang</option>
                            <?php foreach ($allCabang as $cb): ?>
                                <option value="<?= $cb['id'] ?>" <?= $filterCabang == $cb['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cb['kode_cabang'] . ' - ' . $cb['nama_cabang']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Filter Stok -->
                        <select name="stok_status" onchange="this.form.submit()"
                            class="h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none min-w-[140px]">
                            <option value="">Semua Stok</option>
                            <option value="aman" <?= $filterStok === 'aman'     ? 'selected' : '' ?>>Stok Aman</option>
                            <option value="menipis" <?= $filterStok === 'menipis'  ? 'selected' : '' ?>>Menipis (≤10)</option>
                            <option value="habis" <?= $filterStok === 'habis'    ? 'selected' : '' ?>>Habis</option>
                        </select>
                    </div>
                    <div class="flex gap-3 w-full md:w-auto">
                        <button type="button" onclick="openAddModal()"
                            class="flex-1 md:flex-none px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 hover:shadow-primary/40 flex items-center justify-center gap-2 transition-all duration-300 cursor-pointer">
                            <i data-lucide="plus" class="size-5"></i>
                            <span>Tambah Stok</span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Table Container -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1100px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider w-10">
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="size-4 rounded cursor-pointer accent-primary">
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kode Stok</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Nama Produk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Kategori</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Masuk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Keluar</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tersedia</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Harga Jual</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="py-16 flex flex-col items-center justify-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="package-search" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Belum ada data stok</p>
                                            <p class="text-sm text-secondary">Klik "Tambah Stok" untuk mulai mencatat stok barang.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r):
                                    $tersedia = (int)$r['stok_tersedia'];
                                    if ($tersedia <= 0) {
                                        $sBg = 'bg-error/10';
                                        $sTx = 'text-error';
                                        $sLabel = 'Habis';
                                    } elseif ($tersedia <= 10) {
                                        $sBg = 'bg-warning/20';
                                        $sTx = 'text-yellow-700';
                                        $sLabel = 'Menipis';
                                    } else {
                                        $sBg = 'bg-success/10';
                                        $sTx = 'text-success';
                                        $sLabel = 'Aman';
                                    }
                                ?>
                                    <tr class="table-row-hover border-b border-border transition-colors duration-150" data-id="<?= (int)$r['id'] ?>">
                                        <td class="px-5 py-4">
                                            <input type="checkbox" class="row-check size-4 rounded cursor-pointer accent-primary" value="<?= (int)$r['id'] ?>">
                                        </td>
                                        <!-- Kode Stok -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                                <?= htmlspecialchars($r['kode_stok']) ?>
                                            </span>
                                        </td>
                                        <!-- Cabang -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-secondary/10 text-secondary text-xs font-bold font-mono">
                                                    <?= htmlspecialchars($r['kode_cabang']) ?>
                                                </span>
                                                <span class="text-sm text-foreground font-medium"><?= htmlspecialchars($r['nama_cabang']) ?></span>
                                            </div>
                                        </td>
                                        <!-- Nama Produk -->
                                        <td class="px-5 py-4">
                                            <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_produk']) ?></p>
                                            <p class="text-xs text-secondary"><?= htmlspecialchars($r['satuan']) ?></p>
                                        </td>
                                        <!-- Kategori -->
                                        <td class="px-5 py-4 text-secondary text-sm">
                                            <?= htmlspecialchars($r['kategori'] ?: '-') ?>
                                        </td>
                                        <!-- Masuk -->
                                        <td class="px-5 py-4 text-sm font-mono text-foreground font-medium">
                                            <?= number_format($r['stok_masuk'], 0, ',', '.') ?>
                                        </td>
                                        <!-- Keluar -->
                                        <td class="px-5 py-4 text-sm font-mono text-error font-medium">
                                            <?= number_format($r['stok_keluar'], 0, ',', '.') ?>
                                        </td>
                                        <!-- Tersedia -->
                                        <td class="px-5 py-4">
                                            <span class="text-sm font-bold font-mono <?= $sTx ?>">
                                                <?= number_format($tersedia, 0, ',', '.') ?>
                                            </span>
                                        </td>
                                        <!-- Harga Jual -->
                                        <td class="px-5 py-4 text-sm font-mono text-foreground">
                                            Rp <?= number_format($r['harga_jual'], 0, ',', '.') ?>
                                        </td>
                                        <!-- Status -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $sBg ?> <?= $sTx ?> text-xs font-bold">
                                                <?= $sLabel ?>
                                            </span>
                                        </td>
                                        <!-- Aksi -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button type="button"
                                                    onclick="openKeluarModal(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['nama_produk'], ENT_QUOTES) ?>', <?= $tersedia ?>)"
                                                    title="Stok Keluar"
                                                    class="size-9 flex items-center justify-center rounded-lg bg-warning/20 hover:bg-warning text-yellow-700 hover:text-white transition-all duration-200 cursor-pointer">
                                                    <i data-lucide="arrow-right-from-line" class="size-4"></i>
                                                </button>
                                                <button type="button"
                                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"
                                                    title="Edit"
                                                    class="size-9 flex items-center justify-center rounded-lg bg-primary/10 hover:bg-primary text-primary hover:text-white transition-all duration-200 cursor-pointer">
                                                    <i data-lucide="pencil" class="size-4"></i>
                                                </button>
                                                <button type="button"
                                                    onclick="confirmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['nama_produk'], ENT_QUOTES) ?>')"
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

                <!-- Table Footer -->
                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3">
                    <div class="flex items-center gap-3">
                        <p class="text-sm text-secondary">
                            Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                            dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> data
                        </p>
                        <div id="bulkActions" class="hidden items-center gap-2">
                            <span class="text-secondary">|</span>
                            <button type="button" onclick="bulkDelete()"
                                class="text-xs font-semibold text-error hover:underline cursor-pointer flex items-center gap-1">
                                <i data-lucide="trash-2" class="size-3"></i> Hapus Terpilih
                            </button>
                        </div>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&cabang_id=<?= $filterCabang ?>&stok_status=<?= urlencode($filterStok) ?>"
                                    class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&cabang_id=<?= $filterCabang ?>&stok_status=<?= urlencode($filterStok) ?>"
                                    class="size-9 flex items-center justify-center rounded-lg border <?= $i == $page ? 'bg-primary/10 border-primary/20 font-semibold text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?> text-sm transition-all cursor-pointer">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&cabang_id=<?= $filterCabang ?>&stok_status=<?= urlencode($filterStok) ?>"
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

    <!-- ===================== MODAL TAMBAH STOK ===================== -->
    <div id="add-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="font-bold text-xl text-foreground" id="modalTitle">Tambah Stok</h3>
                <button onclick="closeAddModal()" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>

            <form id="crudForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formId" value="">

                <div class="p-6 space-y-4 overflow-y-auto max-h-[65vh]">
                    <div class="grid grid-cols-2 gap-4">

                        <!-- Kode Stok (auto, readonly) -->
                        <div class="col-span-2 relative h-[60px] rounded-2xl ring-1 ring-border bg-muted/40">
                            <i data-lucide="hash" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputKodeStok" name="kode_stok" type="text" readonly
                                class="absolute inset-0 w-full h-full bg-transparent font-bold focus:outline-none pl-12 pt-5 pb-1 text-primary text-sm font-mono cursor-default">
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">Kode Stok <span class="text-success font-bold">(otomatis)</span></label>
                        </div>

                        <!-- Cabang -->
                        <div class="col-span-2 float-select-wrap">
                            <span class="icon"><i data-lucide="git-branch" class="size-5 text-secondary"></i></span>
                            <label for="inputCabang">Cabang *</label>
                            <select id="inputCabang" name="cabang_id" required>
                                <option value="">— Pilih Cabang —</option>
                                <?php foreach ($allCabang as $cb): ?>
                                    <option value="<?= $cb['id'] ?>">
                                        <?= htmlspecialchars($cb['kode_cabang'] . ' - ' . $cb['nama_cabang']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Nama Produk -->
                        <div class="col-span-2 relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="package" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputNamaProduk" name="nama_produk" type="text" required
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label for="inputNamaProduk" class="absolute left-12 text-secondary text-xs font-medium top-2">Nama Produk *</label>
                        </div>

                        <!-- Kategori -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="tag" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputKategori" name="kategori" type="text"
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label for="inputKategori" class="absolute left-12 text-secondary text-xs font-medium top-2">Kategori</label>
                        </div>

                        <!-- Satuan -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="ruler" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputSatuan" name="satuan" type="text" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm">
                            <label for="inputSatuan" class="absolute left-12 text-secondary text-xs font-medium top-2">Satuan * (pcs, kg, lusin...)</label>
                        </div>

                        <!-- Stok Masuk -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="arrow-down-to-line" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputStokMasuk" name="stok_masuk" type="number" min="0" required placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm">
                            <label for="inputStokMasuk" class="absolute left-12 text-secondary text-xs font-medium top-2">Stok Masuk *</label>
                        </div>

                        <!-- Stok Keluar (hanya saat edit) -->
                        <div id="stokKeluarField" class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white hidden">
                            <i data-lucide="arrow-right-from-line" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputStokKeluar" name="stok_keluar" type="number" min="0" placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm">
                            <label for="inputStokKeluar" class="absolute left-12 text-secondary text-xs font-medium top-2">Stok Keluar</label>
                        </div>

                        <!-- Harga Beli -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="circle-dollar-sign" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputHargaBeli" name="harga_beli" type="number" min="0" placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm">
                            <label for="inputHargaBeli" class="absolute left-12 text-secondary text-xs font-medium top-2">Harga Beli (Rp)</label>
                        </div>

                        <!-- Harga Jual -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="badge-dollar-sign" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputHargaJual" name="harga_jual" type="number" min="0" placeholder=" "
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm">
                            <label for="inputHargaJual" class="absolute left-12 text-secondary text-xs font-medium top-2">Harga Jual (Rp)</label>
                        </div>

                        <!-- Keterangan -->
                        <div class="col-span-2 relative rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white pt-6 pb-2">
                            <i data-lucide="file-text" class="absolute left-4 top-4 size-5 text-secondary"></i>
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">Keterangan</label>
                            <textarea id="inputKeterangan" name="keterangan" rows="2"
                                class="w-full bg-transparent font-medium focus:outline-none pl-12 pr-4 text-foreground text-sm resize-none" placeholder="Catatan tambahan..."></textarea>
                        </div>

                    </div>
                </div>

                <div class="p-6 border-t border-border flex gap-3">
                    <button type="button" onclick="closeAddModal()"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit" id="submitBtn"
                        class="flex-1 py-3.5 rounded-full bg-primary text-white font-bold hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all cursor-pointer text-sm">
                        Simpan Stok
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL STOK KELUAR ===================== -->
    <div id="keluar-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-sm shadow-2xl">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="font-bold text-xl text-foreground">Catat Stok Keluar</h3>
                <button onclick="closeKeluarModal()" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="keluar">
                <input type="hidden" name="id" id="keluarId">
                <div class="p-6 space-y-4">
                    <div class="p-4 rounded-2xl bg-muted/60 flex items-center gap-3">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                            <i data-lucide="package" class="size-5 text-primary"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-foreground text-sm" id="keluarNama"></p>
                            <p class="text-xs text-secondary">Stok tersedia: <strong id="keluarTersedia" class="text-foreground"></strong></p>
                        </div>
                    </div>
                    <!-- Jumlah Keluar -->
                    <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                        <i data-lucide="arrow-right-from-line" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input id="inputKeluarJumlah" name="jumlah" type="number" min="1" required placeholder=" "
                            class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm">
                        <label class="absolute left-12 text-secondary text-xs font-medium top-2">Jumlah Keluar *</label>
                    </div>
                    <!-- Keterangan -->
                    <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                        <i data-lucide="file-text" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input name="keterangan" type="text" placeholder=" "
                            class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm">
                        <label class="absolute left-12 text-secondary text-xs font-medium top-2">Keterangan</label>
                    </div>
                </div>
                <div class="px-6 pb-6 flex gap-3">
                    <button type="button" onclick="closeKeluarModal()"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit"
                        class="flex-1 py-3.5 rounded-full bg-warning text-white font-bold hover:bg-yellow-400 shadow-lg shadow-warning/20 transition-all cursor-pointer text-sm">
                        Catat Keluar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL DELETE ===================== -->
    <div id="delete-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-sm shadow-2xl">
            <div class="p-8 flex flex-col items-center gap-4 text-center">
                <div class="size-16 rounded-2xl bg-error/10 flex items-center justify-center">
                    <i data-lucide="trash-2" class="size-8 text-error"></i>
                </div>
                <div>
                    <h3 class="font-bold text-xl text-foreground">Hapus Stok</h3>
                    <p class="text-sm text-secondary mt-2">Hapus stok <strong id="deleteNama"></strong>? Tindakan ini tidak dapat dibatalkan.</p>
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

    <!-- Bulk Form -->
    <form id="bulkForm" method="POST">
        <input type="hidden" name="action" id="bulkAction" value="">
        <div id="bulkIdsContainer"></div>
    </form>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] hidden items-center gap-3 px-5 py-4 rounded-2xl shadow-xl border border-border bg-white max-w-xs transition-all duration-300">
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
        const NEXT_KODE = <?= json_encode($nextKode) ?>;

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
            }, 3500);
        }

        // ── Bulk ──────────────────────────────────────────────────
        function toggleSelectAll(m) {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = m.checked);
            updateBulkBar();
        }
        document.addEventListener('change', e => {
            if (e.target.classList.contains('row-check')) updateBulkBar();
        });

        function updateBulkBar() {
            const n = document.querySelectorAll('.row-check:checked').length;
            const b = document.getElementById('bulkActions');
            n > 0 ? (b.classList.remove('hidden'), b.classList.add('flex')) : (b.classList.add('hidden'), b.classList.remove('flex'));
        }

        function bulkDelete() {
            const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
            if (!ids.length) return;
            if (!confirm(`Hapus ${ids.length} stok? Tidak dapat dibatalkan.`)) return;
            document.getElementById('bulkAction').value = 'bulk_delete';
            const c = document.getElementById('bulkIdsContainer');
            c.innerHTML = '';
            ids.forEach(id => {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = 'ids[]';
                i.value = id;
                c.appendChild(i);
            });
            document.getElementById('bulkForm').submit();
        }

        // ── Add Modal ─────────────────────────────────────────────
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Stok';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
            document.getElementById('crudForm').reset();
            document.getElementById('inputKodeStok').value = NEXT_KODE;
            document.getElementById('stokKeluarField').classList.add('hidden');
            document.getElementById('submitBtn').textContent = 'Simpan Stok';
            toggleModal('add-modal', true);
        }

        // ── Edit Modal ────────────────────────────────────────────
        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Edit Stok';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formId').value = data.id;

            document.getElementById('inputKodeStok').value = data.kode_stok || '';
            document.getElementById('inputCabang').value = data.cabang_id || '';
            document.getElementById('inputNamaProduk').value = data.nama_produk || '';
            document.getElementById('inputKategori').value = data.kategori || '';
            document.getElementById('inputSatuan').value = data.satuan || '';
            document.getElementById('inputStokMasuk').value = data.stok_masuk || 0;
            document.getElementById('inputHargaBeli').value = data.harga_beli || 0;
            document.getElementById('inputHargaJual').value = data.harga_jual || 0;
            document.getElementById('inputKeterangan').value = data.keterangan || '';

            document.getElementById('stokKeluarField').classList.remove('hidden');
            document.getElementById('inputStokKeluar').value = data.stok_keluar || 0;
            document.getElementById('submitBtn').textContent = 'Simpan Perubahan';
            toggleModal('add-modal', true);
        }

        function closeAddModal() {
            toggleModal('add-modal', false);
        }

        // ── Stok Keluar Modal ─────────────────────────────────────
        function openKeluarModal(id, nama, tersedia) {
            document.getElementById('keluarId').value = id;
            document.getElementById('keluarNama').textContent = nama;
            document.getElementById('keluarTersedia').textContent = tersedia;
            document.getElementById('inputKeluarJumlah').value = '';
            document.getElementById('inputKeluarJumlah').max = tersedia;
            toggleModal('keluar-modal', true);
        }

        function closeKeluarModal() {
            toggleModal('keluar-modal', false);
        }

        // ── Delete Modal ──────────────────────────────────────────
        function confirmDelete(id, nama) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteNama').textContent = nama;
            toggleModal('delete-modal', true);
        }

        function closeDeleteModal() {
            toggleModal('delete-modal', false);
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
                closeKeluarModal();
            }
        });
    </script>
    <script src="../layout/index.js"></script>
</body>

</html>