<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'AdminC') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// ── Resolve admin ID ──────────────────────────────────────
$adminId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userId'] ?? 0);
if ($adminId === 0 && !empty($_SESSION['username'])) {
    $uStmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $uStmt->bind_param("s", $_SESSION['username']);
    $uStmt->execute();
    $uRow = $uStmt->get_result()->fetch_assoc();
    $adminId = (int)($uRow['id'] ?? 0);
    $uStmt->close();
    if ($adminId > 0) $_SESSION['user_id'] = $adminId;
}

// ── Cabang milik admin ────────────────────────────────────
$cabangSaya = null;
if ($adminId > 0) {
    $cStmt = $conn->prepare("SELECT id, nama_cabang, kode_cabang FROM cabang WHERE admin_id = ? LIMIT 1");
    $cStmt->bind_param("i", $adminId);
    $cStmt->execute();
    $cabangSaya = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();
}
$cabangId = (int)($cabangSaya['id'] ?? 0);

// ── Daftar produk cabang ──────────────────────────────────
function getProdukList($conn, $cabangId)
{
    if ($cabangId <= 0) return [];
    $s = $conn->prepare("SELECT id, nama_produk, satuan, harga_jual, stok_tersedia FROM stok WHERE cabang_id = ? AND stok_tersedia > 0 ORDER BY nama_produk ASC");
    $s->bind_param("i", $cabangId);
    $s->execute();
    $r = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $r;
}
$produkList = getProdukList($conn, $cabangId);

$msg = '';
$msgType = '';

// ── Generate no transaksi ─────────────────────────────────
function generateNoTransaksi($conn)
{
    $prefix = 'TRX-' . date('Ymd') . '-';
    $res    = $conn->query("SELECT COUNT(*) FROM transaksi WHERE no_transaksi LIKE '" . $conn->real_escape_string($prefix) . "%'");
    $seq    = (int)$res->fetch_row()[0] + 1;
    do {
        $no  = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
        $chk = $conn->prepare("SELECT id FROM transaksi WHERE no_transaksi = ? LIMIT 1");
        $chk->bind_param("s", $no);
        $chk->execute();
        $ex  = $chk->get_result()->num_rows > 0;
        $chk->close();
        if ($ex) $seq++;
    } while ($ex);
    return $no;
}
$nextNo = generateNoTransaksi($conn);
$today = date('Y-m-d'); // Tanggal hari ini otomatis

// ── CRUD ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ===== CREATE — multi-produk ===== */
    if ($_POST['action'] === 'create') {
        $no_transaksi    = generateNoTransaksi($conn);
        $nama_pelanggan  = trim($_POST['nama_pelanggan']  ?? '');
        $keterangan      = trim($_POST['keterangan']      ?? '');
        $tanggal         = trim($_POST['tanggal']         ?? date('Y-m-d'));
        $jenis_transaksi = 'Penjualan';
        $status          = 'Selesai';

        $item_stok_ids = array_map('intval', $_POST['item_stok_id']     ?? []);
        $item_jumlahs  = array_map('intval', $_POST['item_jumlah']       ?? []);
        $item_hargas   = array_map('intval', $_POST['item_harga_satuan'] ?? []);

        if ($nama_pelanggan === '') {
            $msg = "Nama pelanggan wajib diisi";
            $msgType = 'error';
        } elseif ($cabangId === 0) {
            $msg = "Anda belum terhubung ke cabang manapun";
            $msgType = 'error';
        } elseif (empty($item_stok_ids)) {
            $msg = "Tambahkan minimal 1 produk";
            $msgType = 'error';
        } else {
            $conn->begin_transaction();
            $ok = true;
            $errMsg = '';
            $itemsValid = [];

            foreach ($item_stok_ids as $idx => $sid) {
                $qty   = $item_jumlahs[$idx] ?? 0;
                $harga = $item_hargas[$idx]  ?? 0;
                if ($sid <= 0 || $qty <= 0 || $harga <= 0) {
                    $ok = false;
                    $errMsg = "Data produk ke-" . ($idx + 1) . " tidak valid";
                    break;
                }
                $cek = $conn->prepare("SELECT nama_produk, satuan, stok_tersedia FROM stok WHERE id = ? AND cabang_id = ? LIMIT 1 FOR UPDATE");
                $cek->bind_param("ii", $sid, $cabangId);
                $cek->execute();
                $rs = $cek->get_result()->fetch_assoc();
                $cek->close();
                if (!$rs) {
                    $ok = false;
                    $errMsg = "Produk ke-" . ($idx + 1) . " tidak ditemukan di cabang ini";
                    break;
                }
                if ($qty > (int)$rs['stok_tersedia']) {
                    $ok = false;
                    $errMsg = "Stok tidak cukup untuk " . $rs['nama_produk'] . "! Tersedia: " . number_format($rs['stok_tersedia'], 0, ',', '.') . " " . $rs['satuan'];
                    break;
                }
                $itemsValid[] = ['sid' => $sid, 'qty' => $qty, 'harga' => $harga, 'nama' => $rs['nama_produk'], 'satuan' => $rs['satuan']];
            }

            if (!$ok) {
                $conn->rollback();
                $msg = $errMsg;
                $msgType = 'error';
            } else {
                try {
                    foreach ($itemsValid as $item) {
                        $total = $item['qty'] * $item['harga'];
                        $ins = $conn->prepare("INSERT INTO transaksi (no_transaksi, cabang_id, stok_id, nama_pelanggan, jenis_transaksi, jumlah, harga_satuan, total, keterangan, tanggal, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                        $ins->bind_param("siissiiisss", $no_transaksi, $cabangId, $item['sid'], $nama_pelanggan, $jenis_transaksi, $item['qty'], $item['harga'], $total, $keterangan, $tanggal, $status);
                        $ins->execute();
                        $ins->close();

                        $up = $conn->prepare("UPDATE stok SET stok_keluar = stok_keluar + ? WHERE id = ? AND cabang_id = ?");
                        $up->bind_param("iii", $item['qty'], $item['sid'], $cabangId);
                        $up->execute();
                        if ($up->affected_rows === 0) throw new Exception("Gagal update stok " . $item['nama']);
                        $up->close();
                    }
                    $conn->commit();
                    $produkList = getProdukList($conn, $cabangId);
                    $nextNo     = generateNoTransaksi($conn);
                    $msg        = "Penjualan berhasil ditambahkan (" . count($itemsValid) . " produk)";
                    $msgType    = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $msg = "Gagal: " . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    }

    /* ===== UPDATE — multi-produk per no_transaksi ===== */
    if ($_POST['action'] === 'update') {
        $no_transaksi    = trim($_POST['no_transaksi']   ?? '');
        $nama_pelanggan  = trim($_POST['nama_pelanggan'] ?? '');
        $keterangan      = trim($_POST['keterangan']     ?? '');
        $tanggal         = trim($_POST['tanggal']        ?? date('Y-m-d'));
        $jenis_transaksi = 'Penjualan';
        $status          = 'Selesai';

        $item_stok_ids = array_map('intval', $_POST['item_stok_id']     ?? []);
        $item_jumlahs  = array_map('intval', $_POST['item_jumlah']       ?? []);
        $item_hargas   = array_map('intval', $_POST['item_harga_satuan'] ?? []);

        if ($no_transaksi === '' || $nama_pelanggan === '') {
            $msg = "Semua field wajib harus diisi";
            $msgType = 'error';
        } elseif (empty($item_stok_ids)) {
            $msg = "Tambahkan minimal 1 produk";
            $msgType = 'error';
        } else {
            $conn->begin_transaction();
            try {
                // Ambil semua baris lama, kembalikan stok, lalu hapus
                $oldStmt = $conn->prepare("SELECT id, stok_id, jumlah FROM transaksi WHERE no_transaksi = ? AND cabang_id = ? FOR UPDATE");
                $oldStmt->bind_param("si", $no_transaksi, $cabangId);
                $oldStmt->execute();
                $oldRows = $oldStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $oldStmt->close();

                if (empty($oldRows)) throw new Exception("Transaksi tidak ditemukan");

                // Kembalikan stok lama
                foreach ($oldRows as $or) {
                    if ($or['stok_id'] > 0 && $or['jumlah'] > 0) {
                        $rst = $conn->prepare("UPDATE stok SET stok_keluar = stok_keluar - ? WHERE id = ? AND cabang_id = ?");
                        $rst->bind_param("iii", $or['jumlah'], $or['stok_id'], $cabangId);
                        $rst->execute();
                        $rst->close();
                    }
                }

                // Hapus baris lama
                $del = $conn->prepare("DELETE FROM transaksi WHERE no_transaksi = ? AND cabang_id = ?");
                $del->bind_param("si", $no_transaksi, $cabangId);
                $del->execute();
                $del->close();

                // Validasi & insert baru
                $itemsValid = [];
                foreach ($item_stok_ids as $idx => $sid) {
                    $qty   = $item_jumlahs[$idx] ?? 0;
                    $harga = $item_hargas[$idx]  ?? 0;
                    if ($sid <= 0 || $qty <= 0 || $harga <= 0) throw new Exception("Data produk ke-" . ($idx + 1) . " tidak valid");

                    $cek = $conn->prepare("SELECT nama_produk, satuan, stok_tersedia FROM stok WHERE id = ? AND cabang_id = ? LIMIT 1 FOR UPDATE");
                    $cek->bind_param("ii", $sid, $cabangId);
                    $cek->execute();
                    $rs = $cek->get_result()->fetch_assoc();
                    $cek->close();
                    if (!$rs) throw new Exception("Produk ke-" . ($idx + 1) . " tidak ditemukan");
                    if ($qty > (int)$rs['stok_tersedia']) throw new Exception("Stok tidak cukup untuk " . $rs['nama_produk'] . "! Tersedia: " . $rs['stok_tersedia'] . " " . $rs['satuan']);
                    $itemsValid[] = ['sid' => $sid, 'qty' => $qty, 'harga' => $harga, 'nama' => $rs['nama_produk']];
                }

                foreach ($itemsValid as $item) {
                    $total = $item['qty'] * $item['harga'];
                    $ins = $conn->prepare("INSERT INTO transaksi (no_transaksi, cabang_id, stok_id, nama_pelanggan, jenis_transaksi, jumlah, harga_satuan, total, keterangan, tanggal, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("siissiiisss", $no_transaksi, $cabangId, $item['sid'], $nama_pelanggan, $jenis_transaksi, $item['qty'], $item['harga'], $total, $keterangan, $tanggal, $status);
                    $ins->execute();
                    $ins->close();

                    $up = $conn->prepare("UPDATE stok SET stok_keluar = stok_keluar + ? WHERE id = ? AND cabang_id = ?");
                    $up->bind_param("iii", $item['qty'], $item['sid'], $cabangId);
                    $up->execute();
                    $up->close();
                }

                $conn->commit();
                $produkList = getProdukList($conn, $cabangId);
                $msg     = "Penjualan berhasil diperbarui (" . count($itemsValid) . " produk)";
                $msgType = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $msg     = "Gagal: " . $e->getMessage();
                $msgType = 'error';
            }
        }
    }
}

/* ── FETCH — Group by no_transaksi ──────────────────────── */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

// Hitung jumlah no_transaksi unik
$cntS = $conn->prepare("SELECT COUNT(DISTINCT no_transaksi) total FROM transaksi WHERE cabang_id = ?");
$cntS->bind_param("i", $cabangId);
$cntS->execute();
$totalRows = (int)$cntS->get_result()->fetch_assoc()['total'];
$cntS->close();

// Ambil no_transaksi unik dengan pagination (urut terbaru)
$noStmt = $conn->prepare("
    SELECT no_transaksi,
           MIN(tanggal)         AS tanggal,
           MIN(nama_pelanggan)  AS nama_pelanggan,
           MIN(status)          AS status,
           MIN(created_at)      AS created_at,
           SUM(total)           AS grand_total
    FROM transaksi
    WHERE cabang_id = ?
    GROUP BY no_transaksi
    ORDER BY MIN(tanggal) DESC, MIN(created_at) DESC
    LIMIT ? OFFSET ?
");
$noStmt->bind_param("iii", $cabangId, $perPage, $offset);
$noStmt->execute();
$groupedRows = $noStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$noStmt->close();

// Ambil detail item per no_transaksi
$rows = [];
foreach ($groupedRows as $gr) {
    $no = $gr['no_transaksi'];
    $dStmt = $conn->prepare("
        SELECT t.id, t.no_transaksi, t.stok_id, t.jumlah, t.harga_satuan, t.total, t.keterangan,
               s.nama_produk, s.satuan
        FROM transaksi t
        LEFT JOIN stok s ON s.id = t.stok_id
        WHERE t.no_transaksi = ? AND t.cabang_id = ?
        ORDER BY t.id ASC
    ");
    $dStmt->bind_param("si", $no, $cabangId);
    $dStmt->execute();
    $items = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dStmt->close();

    $rows[] = [
        'no_transaksi'   => $no,
        'tanggal'        => $gr['tanggal'],
        'nama_pelanggan' => $gr['nama_pelanggan'],
        'status'         => $gr['status'],
        'grand_total'    => $gr['grand_total'],
        'keterangan'     => $items[0]['keterangan'] ?? '',
        'items'          => $items,
    ];
}

$totalPages = max(1, ceil($totalRows / $perPage));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Penjualan - Sanjai Zivanes</title>
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
        .float-select-wrap { @apply relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white; }
        .float-select-wrap label { @apply absolute left-12 text-secondary text-xs font-medium top-2 pointer-events-none; }
        .float-select-wrap select { @apply absolute bottom-0 inset-x-0 h-[38px] bg-transparent font-medium focus:outline-none pl-12 pr-10 text-foreground text-sm border-none w-full; }
        .float-select-wrap .icon { @apply absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary pointer-events-none; }
    </style>
</head>

<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">

        <!-- Header -->
        <div class="sticky top-0 z-30 flex items-center w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all cursor-pointer">
                    <i data-lucide="menu" class="size-6 text-foreground"></i>
                </button>
                <div>
                    <h2 class="font-bold text-2xl text-foreground">Kelola Penjualan</h2>
                    <p class="hidden sm:block text-sm text-secondary">
                        <?php if ($cabangSaya): ?>
                            <span class="font-semibold text-primary"><?= htmlspecialchars($cabangSaya['nama_cabang']) ?></span>
                            &mdash; <?= htmlspecialchars($cabangSaya['kode_cabang']) ?>
                            <?php else: ?>Anda belum ditugaskan ke cabang manapun<?php endif; ?>
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

            <div class="flex justify-end mb-6">
                <button type="button" onclick="openAddModal()" <?= !$cabangSaya ? 'disabled' : '' ?>
                    class="px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 hover:shadow-primary/40 flex items-center gap-2 transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                    <i data-lucide="plus" class="size-5"></i>
                    <span>Tambah Penjualan</span>
                </button>
            </div>

            <!-- Tabel -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[900px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No. Transaksi</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Pelanggan</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Produk</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Grand Total</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="py-16 flex flex-col items-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="receipt" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Belum ada penjualan</p>
                                            <p class="text-sm text-secondary">Klik "Tambah Penjualan" untuk mencatat penjualan baru.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: foreach ($rows as $r):
                                    $sc = ['Pending' => ['bg-warning/20', 'text-yellow-700'], 'Proses' => ['bg-primary/10', 'text-primary'], 'Selesai' => ['bg-success/10', 'text-success'], 'Dibatalkan' => ['bg-error/10', 'text-error']];
                                    [$sBg, $sTx] = $sc[$r['status']] ?? ['bg-secondary/10', 'text-secondary'];
                                ?>
                                    <tr class="table-row-hover border-b border-border transition-colors">
                                        <!-- No Transaksi -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                                <?= htmlspecialchars($r['no_transaksi']) ?>
                                            </span>
                                            <?php if (count($r['items']) > 1): ?>
                                                <br><span class="mt-1 inline-flex items-center px-1.5 py-0.5 rounded-md bg-secondary/10 text-secondary text-[10px] font-bold">
                                                    <?= count($r['items']) ?> produk
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Pelanggan -->
                                        <td class="px-5 py-4">
                                            <p class="font-semibold text-foreground text-sm"><?= htmlspecialchars($r['nama_pelanggan']) ?></p>
                                        </td>
                                        <!-- Produk (semua item) -->
                                        <td class="px-5 py-4">
                                            <div class="space-y-1.5">
                                                <?php foreach ($r['items'] as $item): ?>
                                                    <div>
                                                        <p class="text-sm text-foreground font-medium leading-tight">
                                                            <?= htmlspecialchars($item['nama_produk'] ?? '—') ?>
                                                        </p>
                                                        <p class="text-xs text-secondary font-mono">
                                                            <?= number_format($item['jumlah'], 0, ',', '.') ?>
                                                            <?= htmlspecialchars($item['satuan'] ?? '') ?>
                                                            &times; Rp <?= number_format($item['harga_satuan'], 0, ',', '.') ?>
                                                            = <span class="text-foreground font-semibold">Rp <?= number_format($item['total'], 0, ',', '.') ?></span>
                                                        </p>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <!-- Grand Total -->
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-foreground text-sm font-mono">Rp <?= number_format($r['grand_total'], 0, ',', '.') ?></p>
                                            <?php if (count($r['items']) > 1): ?>
                                                <p class="text-xs text-secondary"><?= count($r['items']) ?> item</p>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Tanggal -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm font-medium text-secondary"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></p>
                                        </td>
                                        <!-- Status -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $sBg ?> <?= $sTx ?> text-xs font-bold">
                                                <?= htmlspecialchars($r['status']) ?>
                                            </span>
                                        </td>
                                        <!-- Aksi -->
                                        <td class="px-5 py-4">
                                            <div class="flex justify-center">
                                                <button type="button"
                                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"
                                                    title="Edit"
                                                    class="size-9 flex items-center justify-center rounded-lg bg-primary/10 hover:bg-primary text-primary hover:text-white transition-all cursor-pointer">
                                                    <i data-lucide="pencil" class="size-4"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3">
                    <p class="text-sm text-secondary">
                        Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                        dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> transaksi
                    </p>
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>" class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>" class="size-9 flex items-center justify-center rounded-lg border <?= $i == $page ? 'bg-primary/10 border-primary/20 font-semibold text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?> text-sm transition-all cursor-pointer"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>" class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-right" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- ====== MODAL ====== -->
    <div id="add-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl flex flex-col" style="max-height:92vh">
            <div class="flex items-center justify-between p-6 border-b border-border shrink-0">
                <h3 class="font-bold text-xl text-foreground" id="modalTitle">Tambah Penjualan</h3>
                <button onclick="closeModal()" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>

            <form id="crudForm" method="POST" class="flex flex-col flex-1 overflow-hidden">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="no_transaksi" id="formNoTransaksi" value="">

                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <div class="grid grid-cols-2 gap-4">

                        <!-- No. Transaksi -->
                        <div class="col-span-2 relative h-[60px] rounded-2xl ring-1 ring-border bg-muted/40">
                            <i data-lucide="hash" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputNoTrx" type="text" readonly
                                class="absolute inset-0 w-full h-full bg-transparent font-bold focus:outline-none pl-12 pt-5 pb-1 text-primary text-sm font-mono cursor-default" placeholder=" ">
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">No. Transaksi <span class="text-success font-bold">(otomatis)</span></label>
                        </div>

                        <!-- Nama Pelanggan -->
                        <div class="col-span-2 relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="user" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputPelanggan" name="nama_pelanggan" type="text" required
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">Nama Pelanggan *</label>
                        </div>

                        <!-- Tanggal (otomatis, tersembunyi) -->
                        <input type="hidden" id="inputTanggal" name="tanggal" value="<?= date('Y-m-d') ?>">

                        <!-- Keterangan -->
                        <div class="col-span-2 relative rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white pt-6 pb-2">
                            <i data-lucide="file-text" class="absolute left-4 top-4 size-5 text-secondary"></i>
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">Keterangan</label>
                            <textarea id="inputKeterangan" name="keterangan" rows="2"
                                class="w-full bg-transparent font-medium focus:outline-none pl-12 pr-4 text-foreground text-sm resize-none" placeholder="Catatan tambahan..."></textarea>
                        </div>
                    </div>

                    <!-- ══ MULTI-PRODUK ══ -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <p class="font-bold text-sm text-foreground">Produk yang Dijual *</p>
                            <button type="button" onclick="tambahItemBaru()"
                                class="flex items-center gap-1.5 px-3 h-8 bg-primary/10 hover:bg-primary text-primary hover:text-white rounded-xl font-semibold text-xs transition-all cursor-pointer shrink-0">
                                <i data-lucide="plus" class="size-3.5"></i> Tambah Produk
                            </button>
                        </div>
                        <div id="itemContainer" class="space-y-3"></div>
                        <!-- Grand Total -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border bg-muted/50">
                            <i data-lucide="trending-up" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="grandTotalPreview" type="text" readonly value="Rp 0"
                                class="absolute inset-0 w-full h-full bg-transparent font-bold focus:outline-none pl-12 pt-5 pb-1 text-primary text-sm cursor-default" placeholder=" ">
                            <label class="absolute left-12 text-secondary text-xs font-medium top-2">Grand Total (otomatis)</label>
                        </div>
                    </div>
                </div>

                <div class="p-6 border-t border-border flex gap-3 shrink-0">
                    <button type="button" onclick="closeModal()"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit" id="btnSubmit"
                        class="flex-1 py-3.5 rounded-full bg-primary text-white font-bold hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all cursor-pointer text-sm">
                        Simpan Penjualan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] hidden items-center gap-3 px-5 py-4 rounded-2xl shadow-xl border border-border bg-white max-w-xs">
        <div id="toastIcon" class="size-9 rounded-xl flex items-center justify-center shrink-0"></div>
        <p id="toastMsg" class="font-semibold text-sm text-foreground"></p>
    </div>

    <?php if ($msg): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast(<?= json_encode(html_entity_decode($msg)) ?>, <?= json_encode($msgType) ?>);
            });
        </script>
    <?php endif; ?>

    <script>
        const NEXT_NO = <?= json_encode($nextNo) ?>;
        const PRODUK_DATA = <?= json_encode(array_column($produkList, null, 'id')) ?>;
        const PRODUK_LIST = <?= json_encode(array_values($produkList)) ?>;

        let _itemCount = 0;

        document.addEventListener('DOMContentLoaded', () => lucide.createIcons());

        function toggleSidebar() {
            const s = document.getElementById('sidebar'),
                o = document.getElementById('sidebar-overlay');
            if (!s) return;
            s.classList.contains('-translate-x-full') ?
                (s.classList.remove('-translate-x-full'), o?.classList.remove('hidden'), document.body.style.overflow = 'hidden') :
                (s.classList.add('-translate-x-full'), o?.classList.add('hidden'), document.body.style.overflow = '');
        }

        function showToast(message, type = 'success') {
            const t = document.getElementById('toast');
            const i = document.getElementById('toastIcon');
            const m = document.getElementById('toastMsg');
            const c = {
                success: {
                    bg: 'bg-success/10',
                    tx: 'text-success',
                    ic: 'check-circle'
                },
                error: {
                    bg: 'bg-error/10',
                    tx: 'text-error',
                    ic: 'x-circle'
                },
                info: {
                    bg: 'bg-primary/10',
                    tx: 'text-primary',
                    ic: 'info'
                }
            } [type] || {
                bg: 'bg-success/10',
                tx: 'text-success',
                ic: 'check-circle'
            };
            i.className = `size-9 rounded-xl flex items-center justify-center shrink-0 ${c.bg} ${c.tx}`;
            i.innerHTML = `<i data-lucide="${c.ic}" class="size-5"></i>`;
            m.textContent = message;
            t.classList.remove('hidden');
            t.classList.add('flex');
            lucide.createIcons();
            setTimeout(() => {
                t.classList.add('hidden');
                t.classList.remove('flex');
            }, 4500);
        }

        // ── Build <option> HTML ───────────────────────────────────
        function _buildOptions(selectedId = 0) {
            let h = '<option value="">— Pilih Produk —</option>';
            PRODUK_LIST.forEach(p => {
                const sel = (p.id == selectedId) ? 'selected' : '';
                h += `<option value="${p.id}" ${sel}
                    data-harga="${p.harga_jual}"
                    data-stok="${p.stok_tersedia}"
                    data-nama="${p.nama_produk.replace(/"/g,'&quot;')}"
                    data-satuan="${p.satuan}">
                    ${p.nama_produk} (Stok: ${p.stok_tersedia} ${p.satuan}) — Rp ${parseInt(p.harga_jual).toLocaleString('id-ID')}
                </option>`;
            });
            return h;
        }

        // ── Tambah baris item ─────────────────────────────────────
        function tambahItemBaru(prefill) {
            _itemCount++;
            const idx = _itemCount;
            const el = document.createElement('div');
            el.id = `irow-${idx}`;
            el.className = 'p-4 rounded-2xl border border-border bg-muted/30 space-y-3';

            const qVal = prefill?.jumlah ?? '';
            const hVal = prefill?.harga_satuan ?? '';
            const sVal = prefill ? parseInt(prefill.total || 0).toLocaleString('id-ID') : '0';

            el.innerHTML = `
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-secondary uppercase tracking-wider">Produk #${idx}</span>
                    <button type="button" onclick="hapusItem(${idx})"
                        class="size-7 flex items-center justify-center rounded-lg bg-error/10 hover:bg-error text-error hover:text-white transition-all cursor-pointer">
                        <i data-lucide="trash-2" class="size-3.5"></i>
                    </button>
                </div>
                <div class="float-select-wrap">
                    <span class="icon"><i data-lucide="package" class="size-5"></i></span>
                    <label>Pilih Produk *</label>
                    <select name="item_stok_id[]" id="isel-${idx}" onchange="onItemChange(${idx})" required>
                        ${_buildOptions(prefill?.stok_id ?? 0)}
                    </select>
                </div>
                <div id="ibar-${idx}" class="hidden items-center gap-2 p-2.5 rounded-xl bg-success/10 border border-success/20">
                    <i data-lucide="package-check" class="size-4 text-success shrink-0"></i>
                    <p id="itxt-${idx}" class="text-xs font-semibold text-success"></p>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                        <i data-lucide="layers" class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-secondary"></i>
                        <input id="iqty-${idx}" name="item_jumlah[]" type="number" min="1" required value="${qVal}"
                            oninput="hitungItemSub(${idx})"
                            class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-9 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                        <label class="absolute left-9 text-secondary text-[10px] font-medium top-2">Jumlah *</label>
                    </div>
                    <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                        <i data-lucide="circle-dollar-sign" class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-secondary"></i>
                        <input id="iharga-${idx}" name="item_harga_satuan[]" type="number" min="0" required value="${hVal}"
                            readonly
                            class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-9 pt-5 pb-1 text-foreground text-sm cursor-default" placeholder=" ">
                        <label class="absolute left-9 text-secondary text-[10px] font-medium top-2">Harga (Rp)</label>
                    </div>
                    <div class="relative h-[60px] rounded-2xl ring-1 ring-border bg-muted/50">
                        <i data-lucide="trending-up" class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-secondary"></i>
                        <input id="isub-${idx}" type="text" readonly value="Rp ${sVal}"
                            class="absolute inset-0 w-full h-full bg-transparent font-bold focus:outline-none pl-9 pt-5 pb-1 text-primary text-sm cursor-default" placeholder=" ">
                        <label class="absolute left-9 text-secondary text-[10px] font-medium top-2">Subtotal</label>
                    </div>
                </div>`;

            document.getElementById('itemContainer').appendChild(el);
            lucide.createIcons();

            // Jika prefill ada produk, tampilkan stok bar
            if (prefill?.stok_id) {
                setTimeout(() => onItemChange(idx, true), 80);
            }
        }

        function hapusItem(idx) {
            document.getElementById(`irow-${idx}`)?.remove();
            if (!document.getElementById('itemContainer').children.length) tambahItemBaru();
            hitungGrandTotal();
        }

        // keepHarga = true: jangan overwrite nilai harga (mode prefill edit)
        function onItemChange(idx, keepHarga) {
            const sel = document.getElementById(`isel-${idx}`);
            const opt = sel?.options[sel.selectedIndex];
            const sid = parseInt(sel?.value) || 0;
            const bar = document.getElementById(`ibar-${idx}`);
            const txt = document.getElementById(`itxt-${idx}`);

            if (sid > 0 && opt) {
                const harga = parseInt(opt.dataset.harga) || 0;
                const stok = parseInt(opt.dataset.stok) || 0;
                const satuan = opt.dataset.satuan || '';
                const nama = opt.dataset.nama || '';

                if (!keepHarga) document.getElementById(`iharga-${idx}`).value = harga;
                document.getElementById(`iqty-${idx}`).max = stok;

                if (bar && txt) {
                    bar.className = 'flex items-center gap-2 p-2.5 rounded-xl bg-success/10 border border-success/20';
                    txt.className = 'text-xs font-semibold text-success';
                    txt.textContent = `${nama} — Stok: ${stok} ${satuan} | Rp ${harga.toLocaleString('id-ID')}`;
                    bar.classList.remove('hidden');
                }
            } else {
                bar?.classList.add('hidden');
                if (!keepHarga) {
                    const hEl = document.getElementById(`iharga-${idx}`);
                    if (hEl) hEl.value = '';
                }
            }
            hitungItemSub(idx);
        }

        function hitungItemSub(idx) {
            const q = parseInt(document.getElementById(`iqty-${idx}`)?.value) || 0;
            const h = parseInt(document.getElementById(`iharga-${idx}`)?.value) || 0;
            const sub = document.getElementById(`isub-${idx}`);
            if (sub) sub.value = 'Rp ' + (q * h).toLocaleString('id-ID');

            const sel = document.getElementById(`isel-${idx}`);
            const opt = sel?.options[sel?.selectedIndex];
            const stok = parseInt(opt?.dataset?.stok) || 0;
            const bar = document.getElementById(`ibar-${idx}`);
            const txt = document.getElementById(`itxt-${idx}`);

            if (stok > 0 && bar && txt && parseInt(sel?.value)) {
                const nama = opt?.dataset?.nama || '';
                const satuan = opt?.dataset?.satuan || '';
                if (q > stok) {
                    bar.className = 'flex items-center gap-2 p-2.5 rounded-xl bg-error/10 border border-error/20';
                    txt.className = 'text-xs font-semibold text-error';
                    txt.textContent = `⚠ Jumlah melebihi stok (${stok} ${satuan})`;
                    bar.classList.remove('hidden');
                } else {
                    bar.className = 'flex items-center gap-2 p-2.5 rounded-xl bg-success/10 border border-success/20';
                    txt.className = 'text-xs font-semibold text-success';
                    txt.textContent = `${nama} — Stok: ${stok} ${satuan}`;
                    bar.classList.remove('hidden');
                }
            }
            hitungGrandTotal();
        }

        function hitungGrandTotal() {
            let total = 0;
            document.querySelectorAll('[id^="isub-"]').forEach(el => {
                total += parseInt(el.value.replace(/[^0-9]/g, '')) || 0;
            });
            document.getElementById('grandTotalPreview').value = 'Rp ' + total.toLocaleString('id-ID');
        }

        // ── Modal Tambah ──────────────────────────────────────────
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Penjualan';
            document.getElementById('btnSubmit').textContent = 'Simpan Penjualan';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formNoTransaksi').value = NEXT_NO;
            document.getElementById('inputNoTrx').value = NEXT_NO;
            document.getElementById('inputPelanggan').value = '';
            document.getElementById('inputKeterangan').value = '';
            document.getElementById('grandTotalPreview').value = 'Rp 0';
            document.getElementById('itemContainer').innerHTML = '';
            _itemCount = 0;
            tambahItemBaru();
            toggleModal(true);
        }

        // ── Modal Edit (multi-produk) ─────────────────────────────
        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Edit Penjualan';
            document.getElementById('btnSubmit').textContent = 'Simpan Perubahan';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formNoTransaksi').value = data.no_transaksi;
            document.getElementById('inputNoTrx').value = data.no_transaksi;
            document.getElementById('inputPelanggan').value = data.nama_pelanggan || '';
            document.getElementById('inputTanggal').value = <?= json_encode(date('Y-m-d')) ?>;
            document.getElementById('inputKeterangan').value = (data.keterangan && data.keterangan !== '0') ? data.keterangan : '';
            document.getElementById('grandTotalPreview').value = 'Rp 0';
            document.getElementById('itemContainer').innerHTML = '';
            _itemCount = 0;

            const items = data.items || [];
            if (items.length === 0) {
                tambahItemBaru();
            } else {
                items.forEach(item => tambahItemBaru({
                    stok_id: item.stok_id,
                    jumlah: item.jumlah,
                    harga_satuan: item.harga_satuan,
                    total: item.total,
                }));
            }

            setTimeout(hitungGrandTotal, 120);
            toggleModal(true);
        }

        function closeModal() {
            toggleModal(false);
        }

        function toggleModal(show) {
            const m = document.getElementById('add-modal');
            if (show) {
                m.classList.remove('hidden');
                m.classList.add('flex');
                setTimeout(() => lucide.createIcons(), 60);
            } else {
                m.classList.add('hidden');
                m.classList.remove('flex');
            }
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });
    </script>
    <script src="../layout/index.js"></script>
</body>

</html>