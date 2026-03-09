<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'AdminP') {
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

// ── Helper ────────────────────────────────────────────────
$msg     = '';
$msgType = '';

// ── CRUD HANDLER ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ===================== TERIMA ===================== */
    if ($_POST['action'] === 'terima') {
        $id = (int)($_POST['id'] ?? 0);

        $chk = $conn->prepare("SELECT id, status FROM setoran WHERE id = ? LIMIT 1");
        $chk->bind_param("i", $id);
        $chk->execute();
        $rowChk = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$rowChk) {
            $msg = "Data setoran tidak ditemukan.";
            $msgType = 'error';
        } elseif ($rowChk['status'] !== 'Menunggu') {
            $msg = "Setoran ini sudah diverifikasi sebelumnya.";
            $msgType = 'error';
        } else {
            $now  = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                UPDATE setoran
                SET status = 'Diterima', verified_by = ?, verified_at = ?, catatan_penolakan = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("isi", $adminId, $now, $id);
            if ($stmt->execute()) {
                $msg = "Setoran berhasil diterima.";
                $msgType = 'success';
            } else {
                $msg = "Gagal memperbarui status: " . $stmt->error;
                $msgType = 'error';
            }
            $stmt->close();
        }
    }

    /* ===================== TOLAK ===================== */
    if ($_POST['action'] === 'tolak') {
        $id      = (int)($_POST['id'] ?? 0);
        $catatan = trim($_POST['catatan_penolakan'] ?? '');

        if (!$catatan) {
            $msg = "Harap isi catatan alasan penolakan.";
            $msgType = 'error';
        } else {
            $chk = $conn->prepare("SELECT id, status FROM setoran WHERE id = ? LIMIT 1");
            $chk->bind_param("i", $id);
            $chk->execute();
            $rowChk = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$rowChk) {
                $msg = "Data setoran tidak ditemukan.";
                $msgType = 'error';
            } elseif ($rowChk['status'] !== 'Menunggu') {
                $msg = "Setoran ini sudah diverifikasi sebelumnya.";
                $msgType = 'error';
            } else {
                $now  = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("
                    UPDATE setoran
                    SET status = 'Ditolak', catatan_penolakan = ?, verified_by = ?, verified_at = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("sisi", $catatan, $adminId, $now, $id);
                if ($stmt->execute()) {
                    $msg = "Setoran berhasil ditolak.";
                    $msgType = 'success';
                } else {
                    $msg = "Gagal memperbarui status: " . $stmt->error;
                    $msgType = 'error';
                }
                $stmt->close();
            }
        }
    }
}

/* ===================== FETCH DATA ===================== */
$search      = trim($_GET['search']   ?? '');
$filterStat  = trim($_GET['status']   ?? '');
$filterBulan = trim($_GET['bulan']    ?? '');
$filterCabang = (int)($_GET['cabang'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 10;
$offset      = ($page - 1) * $perPage;

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $like     = "%$search%";
    $where   .= " AND (s.no_setoran LIKE ? OR s.keterangan LIKE ? OR c.nama_cabang LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}
if ($filterStat !== '') {
    $where   .= " AND s.status = ?";
    $params[] = $filterStat;
    $types   .= "s";
}
if ($filterBulan !== '') {
    $where   .= " AND DATE_FORMAT(s.tanggal, '%Y-%m') = ?";
    $params[] = $filterBulan;
    $types   .= "s";
}
if ($filterCabang > 0) {
    $where   .= " AND s.cabang_id = ?";
    $params[] = $filterCabang;
    $types   .= "i";
}

$cntSql   = "SELECT COUNT(*) FROM setoran s JOIN cabang c ON c.id = s.cabang_id $where";
$cntStmt  = $conn->prepare($cntSql);
if ($types) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$totalRows = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

$sql = "
    SELECT s.*,
           c.nama_cabang, c.kode_cabang,
           u.username AS admin_username,
           v.username AS verifier_username
    FROM setoran s
    JOIN cabang c ON c.id = s.cabang_id
    JOIN users u  ON u.id = s.admin_id
    LEFT JOIN users v ON v.id = s.verified_by
    $where
    ORDER BY FIELD(s.status,'Menunggu','Ditolak','Diterima'), s.tanggal DESC, s.created_at DESC
    LIMIT ? OFFSET ?
";
$pArr   = $params;
$pArr[] = $perPage;
$pArr[] = $offset;
$tStr   = $types . "ii";
$dStmt  = $conn->prepare($sql);
$dStmt->bind_param($tStr, ...$pArr);
$dStmt->execute();
$rows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dStmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

// ── Stats ─────────────────────────────────────────────────
$statMenunggu = (int)$conn->query("SELECT COUNT(*) FROM setoran WHERE status='Menunggu'")->fetch_row()[0];
$statDiterima = (int)$conn->query("SELECT COUNT(*) FROM setoran WHERE status='Diterima'")->fetch_row()[0];
$statDitolak  = (int)$conn->query("SELECT COUNT(*) FROM setoran WHERE status='Ditolak'")->fetch_row()[0];
$statNominal  = (int)$conn->query("SELECT COALESCE(SUM(jumlah_setoran),0) FROM setoran WHERE status='Diterima'")->fetch_row()[0];

// ── Cabang list for filter ────────────────────────────────
$cabangList = $conn->query("SELECT id, kode_cabang, nama_cabang FROM cabang ORDER BY nama_cabang")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validasi Setoran - Sanjai Zivanes</title>
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
                    <h2 class="font-bold text-2xl text-foreground">Validasi Setoran</h2>
                    <p class="hidden sm:block text-sm text-secondary">Verifikasi setoran harian dari seluruh cabang</p>
                </div>
            </div>
        </div>

        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <!-- Toolbar / Filter -->
            <form method="GET" id="filterForm">
                <div class="flex flex-col md:flex-row gap-4 justify-between items-center mb-6">
                    <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-1 max-w-3xl flex-wrap">
                        <!-- Search -->
                        <div class="relative flex-1 min-w-[200px] group">
                            <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary group-focus-within:text-primary transition-colors"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Cari no. setoran / cabang..."
                                class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300">
                        </div>
                        <!-- Status -->
                        <select name="status" onchange="this.form.submit()"
                            class="h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none min-w-[150px]">
                            <option value="">Semua Status</option>
                            <option value="Menunggu" <?= $filterStat === 'Menunggu' ? 'selected' : '' ?>>Menunggu</option>
                            <option value="Diterima" <?= $filterStat === 'Diterima' ? 'selected' : '' ?>>Diterima</option>
                            <option value="Ditolak"  <?= $filterStat === 'Ditolak'  ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                        <!-- Cabang -->
                        <select name="cabang" onchange="this.form.submit()"
                            class="h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none min-w-[160px]">
                            <option value="">Semua Cabang</option>
                            <?php foreach ($cabangList as $cb): ?>
                                <option value="<?= $cb['id'] ?>" <?= $filterCabang === (int)$cb['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cb['nama_cabang']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Bulan -->
                        <input type="month" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>"
                            onchange="this.form.submit()"
                            class="h-12 px-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <!-- Search Button -->
                    <button type="submit"
                        class="px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 flex items-center gap-2 transition-all duration-300 cursor-pointer shrink-0">
                        <i data-lucide="search" class="size-4"></i>
                        Cari
                    </button>
                </div>
            </form>

            <!-- Menunggu alert banner -->
            <?php if ($statMenunggu > 0): ?>
                <div class="mb-6 flex items-center gap-4 p-4 rounded-2xl bg-warning/10 border border-warning/40">
                    <div class="size-10 rounded-xl bg-warning/30 flex items-center justify-center shrink-0">
                        <i data-lucide="bell" class="size-5 text-yellow-700"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-yellow-800 text-sm">
                            Ada <strong><?= $statMenunggu ?></strong> setoran menunggu verifikasi Anda.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Table -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1050px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">No. Setoran</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Cabang</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tanggal</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jumlah Setoran</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total Omset</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Bukti</th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="py-16 flex flex-col items-center justify-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="inbox" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Tidak ada setoran ditemukan</p>
                                            <p class="text-sm text-secondary">Coba ubah filter pencarian Anda.</p>
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
                                    $ext   = strtolower(pathinfo($r['bukti_foto'] ?? '', PATHINFO_EXTENSION));
                                    $isPdf = ($ext === 'pdf');
                                ?>
                                    <tr class="table-row-hover border-b border-border transition-colors duration-150">
                                        <!-- No Setoran -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold font-mono">
                                                <?= htmlspecialchars($r['no_setoran']) ?>
                                            </span>
                                            <p class="text-xs text-secondary mt-1"><?= htmlspecialchars($r['admin_username']) ?></p>
                                        </td>
                                        <!-- Cabang -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm font-semibold text-foreground"><?= htmlspecialchars($r['nama_cabang']) ?></p>
                                            <p class="text-xs text-secondary font-mono"><?= htmlspecialchars($r['kode_cabang']) ?></p>
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
                                            <?php if ($r['status'] !== 'Menunggu'): ?>
                                                <p class="text-xs text-secondary mt-1">
                                                    <?= $r['verifier_username'] ? htmlspecialchars($r['verifier_username']) : '—' ?>
                                                    <?php if ($r['verified_at']): ?>
                                                        <br><?= date('d/m/Y H:i', strtotime($r['verified_at'])) ?>
                                                    <?php endif; ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($r['status'] === 'Ditolak' && $r['catatan_penolakan']): ?>
                                                <p class="text-xs text-error mt-1 max-w-[160px] truncate" title="<?= htmlspecialchars($r['catatan_penolakan']) ?>">
                                                    "<?= htmlspecialchars($r['catatan_penolakan']) ?>"
                                                </p>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Aksi -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <?php if ($r['status'] === 'Menunggu'): ?>
                                                    <!-- Terima -->
                                                    <button type="button"
                                                        onclick="confirmTerima(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['no_setoran'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['nama_cabang'], ENT_QUOTES) ?>')"
                                                        title="Terima Setoran"
                                                        class="size-9 flex items-center justify-center rounded-lg bg-success/10 hover:bg-success text-success hover:text-white transition-all duration-200 cursor-pointer">
                                                        <i data-lucide="check" class="size-4"></i>
                                                    </button>
                                                    <!-- Tolak -->
                                                    <button type="button"
                                                        onclick="openTolakModal(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['no_setoran'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['nama_cabang'], ENT_QUOTES) ?>')"
                                                        title="Tolak Setoran"
                                                        class="size-9 flex items-center justify-center rounded-lg bg-error/10 hover:bg-error text-error hover:text-white transition-all duration-200 cursor-pointer">
                                                        <i data-lucide="x" class="size-4"></i>
                                                    </button>
                                                    <!-- Detail -->
                                                    <button type="button"
                                                        onclick="openDetailModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"
                                                        title="Detail"
                                                        class="size-9 flex items-center justify-center rounded-lg bg-primary/10 hover:bg-primary text-primary hover:text-white transition-all duration-200 cursor-pointer">
                                                        <i data-lucide="eye" class="size-4"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <!-- Detail only -->
                                                    <button type="button"
                                                        onclick="openDetailModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"
                                                        title="Detail"
                                                        class="size-9 flex items-center justify-center rounded-lg bg-primary/10 hover:bg-primary text-primary hover:text-white transition-all duration-200 cursor-pointer">
                                                        <i data-lucide="eye" class="size-4"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer / Pagination -->
                <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-border gap-3">
                    <p class="text-sm text-secondary">
                        Menampilkan <span class="font-semibold text-foreground"><?= count($rows) ?></span>
                        dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> setoran
                    </p>
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>&cabang=<?= $filterCabang ?>"
                                    class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>&cabang=<?= $filterCabang ?>"
                                    class="size-9 flex items-center justify-center rounded-lg border <?= $i == $page ? 'bg-primary/10 border-primary/20 font-semibold text-primary' : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?> text-sm transition-all cursor-pointer">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&bulan=<?= urlencode($filterBulan) ?>&cabang=<?= $filterCabang ?>"
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

    <!-- ===================== MODAL KONFIRMASI TERIMA ===================== -->
    <div id="terima-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-sm shadow-2xl">
            <div class="p-8 flex flex-col items-center gap-4 text-center">
                <div class="size-16 rounded-2xl bg-success/10 flex items-center justify-center">
                    <i data-lucide="check-circle" class="size-8 text-success"></i>
                </div>
                <div>
                    <h3 class="font-bold text-xl text-foreground">Terima Setoran</h3>
                    <p class="text-sm text-secondary mt-2">Terima setoran <strong id="terimaNo"></strong> dari <strong id="terimaCabang"></strong>?</p>
                    <p class="text-xs text-secondary mt-1">Status akan diubah menjadi <span class="text-success font-semibold">Diterima</span>.</p>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="terima">
                <input type="hidden" name="id" id="terimaId">
                <div class="px-6 pb-6 flex gap-3">
                    <button type="button" onclick="closeModal('terima-modal')"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit"
                        class="flex-1 py-3.5 rounded-full bg-success text-white font-bold hover:bg-green-600 shadow-lg shadow-success/20 transition-all cursor-pointer text-sm">
                        Ya, Terima
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL TOLAK ===================== -->
    <div id="tolak-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-md shadow-2xl">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <div class="flex items-center gap-3">
                    <div class="size-10 rounded-xl bg-error/10 flex items-center justify-center">
                        <i data-lucide="x-circle" class="size-5 text-error"></i>
                    </div>
                    <h3 class="font-bold text-xl text-foreground">Tolak Setoran</h3>
                </div>
                <button type="button" onclick="closeModal('tolak-modal')" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>
            <form id="tolakForm" method="POST" onsubmit="return submitTolakForm(event)">
                <input type="hidden" name="action" value="tolak">
                <input type="hidden" name="id" id="tolakId">
                <div class="p-6 space-y-4">
                    <p class="text-sm text-secondary">Tolak setoran <strong id="tolakNo"></strong> dari <strong id="tolakCabang"></strong>?</p>
                    <!-- Catatan Penolakan -->
                    <div class="relative rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-error transition-all bg-white pt-6 pb-2">
                        <i data-lucide="message-square" class="absolute left-4 top-4 size-5 text-secondary"></i>
                        <label class="absolute left-12 text-secondary text-xs font-medium top-2">Alasan Penolakan *</label>
                        <textarea id="catatanPenolakan" name="catatan_penolakan" rows="3"
                            class="w-full bg-transparent font-medium focus:outline-none pl-12 pr-4 text-foreground text-sm resize-none"
                            placeholder="Tuliskan alasan penolakan setoran ini..."></textarea>
                        <div id="catatanError" class="text-error text-xs mt-1 hidden">Harap isi alasan penolakan</div>
                    </div>
                </div>
                <div class="p-6 border-t border-border flex gap-3">
                    <button type="button" onclick="closeModal('tolak-modal')"
                        class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                        Batal
                    </button>
                    <button type="submit" id="tolakSubmitBtn"
                        class="flex-1 py-3.5 rounded-full bg-error text-white font-bold hover:bg-red-500 shadow-lg shadow-error/20 transition-all cursor-pointer text-sm flex items-center justify-center gap-2">
                        <i data-lucide="x-circle" class="size-4"></i>
                        <span id="tolakSubmitText">Tolak Setoran</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL DETAIL ===================== -->
    <div id="detail-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <div class="flex items-center gap-3">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i data-lucide="file-search" class="size-5 text-primary"></i>
                    </div>
                    <h3 class="font-bold text-xl text-foreground">Detail Setoran</h3>
                </div>
                <button onclick="closeModal('detail-modal')" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[65vh]">
                <div id="detailContent" class="space-y-3"></div>
            </div>
            <div class="p-6 border-t border-border">
                <button onclick="closeModal('detail-modal')"
                    class="w-full py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer text-sm">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL PREVIEW FOTO ===================== -->
    <div id="foto-modal" class="fixed inset-0 bg-black/80 z-[200] hidden items-center justify-center p-4 backdrop-blur-sm" onclick="closeModal('foto-modal')">
        <div class="relative max-w-2xl w-full" onclick="event.stopPropagation()">
            <button onclick="closeModal('foto-modal')" class="absolute -top-4 -right-4 size-10 bg-white rounded-full flex items-center justify-center shadow-lg cursor-pointer hover:bg-muted transition-colors">
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
            const icon  = document.getElementById('toastIcon');
            const msg   = document.getElementById('toastMsg');
            const cfg   = {
                success: { bg: 'bg-success/10', text: 'text-success', icon: 'check-circle' },
                error:   { bg: 'bg-error/10',   text: 'text-error',   icon: 'x-circle' },
                info:    { bg: 'bg-primary/10',  text: 'text-primary', icon: 'info' },
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

        // ── Generic Modal ─────────────────────────────────────────
        function openModal(id) {
            const m = document.getElementById(id);
            m.classList.remove('hidden');
            m.classList.add('flex');
            setTimeout(() => lucide.createIcons(), 50);
        }
        function closeModal(id) {
            const m = document.getElementById(id);
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        // ── Terima ────────────────────────────────────────────────
        function confirmTerima(id, no, cabang) {
            document.getElementById('terimaId').value    = id;
            document.getElementById('terimaNo').textContent  = no;
            document.getElementById('terimaCabang').textContent = cabang;
            openModal('terima-modal');
        }

        // ── Tolak ─────────────────────────────────────────────────
        function openTolakModal(id, no, cabang) {
            document.getElementById('tolakId').value    = id;
            document.getElementById('tolakNo').textContent  = no;
            document.getElementById('tolakCabang').textContent = cabang;
            document.getElementById('catatanPenolakan').value = '';
            document.getElementById('catatanError').classList.add('hidden');
            document.getElementById('tolakSubmitBtn').disabled = false;
            document.getElementById('tolakSubmitText').textContent = 'Tolak Setoran';
            openModal('tolak-modal');
        }

        function submitTolakForm(event) {
            event.preventDefault();
            
            const catatan = document.getElementById('catatanPenolakan').value.trim();
            const errorEl = document.getElementById('catatanError');
            const submitBtn = document.getElementById('tolakSubmitBtn');
            
            // Validasi client-side
            if (!catatan) {
                errorEl.classList.remove('hidden');
                document.getElementById('catatanPenolakan').focus();
                return false;
            }
            
            // Sembunyikan error jika valid
            errorEl.classList.add('hidden');
            
            // Disable button dan tampilkan loading state
            submitBtn.disabled = true;
            document.getElementById('tolakSubmitText').textContent = 'Memproses...';
            
            // Submit form
            document.getElementById('tolakForm').submit();
        }

        // ── Detail ────────────────────────────────────────────────
        function openDetailModal(data) {
            const stCfg = {
                'Menunggu': ['bg-yellow-100 text-yellow-700'],
                'Diterima': ['bg-green-100 text-green-700'],
                'Ditolak':  ['bg-red-100 text-red-600'],
            };
            const stClass = (stCfg[data.status] || ['bg-gray-100 text-gray-600'])[0];

            const rows = [
                ['No. Setoran',   `<span class="font-mono font-bold text-blue-600">${data.no_setoran}</span>`],
                ['Cabang',        `${data.nama_cabang} <span class="text-xs text-gray-400">(${data.kode_cabang})</span>`],
                ['Admin Cabang',  data.admin_username || '—'],
                ['Tanggal',       data.tanggal],
                ['Jumlah Setoran',`<span class="font-bold font-mono">Rp ${parseInt(data.jumlah_setoran).toLocaleString('id-ID')}</span>`],
                ['Total Omset',   `<span class="font-mono">Rp ${parseInt(data.total_transaksi).toLocaleString('id-ID')}</span>`],
                ['Status',        `<span class="px-2.5 py-0.5 rounded-full text-xs font-bold ${stClass}">${data.status}</span>`],
                ['Keterangan',    data.keterangan || '—'],
            ];

            if (data.status !== 'Menunggu') {
                rows.push(['Diverifikasi oleh', data.verifier_username || '—']);
                rows.push(['Waktu Verifikasi',  data.verified_at || '—']);
            }
            if (data.status === 'Ditolak' && data.catatan_penolakan) {
                rows.push(['Alasan Penolakan',  `<span class="text-red-500">${data.catatan_penolakan}</span>`]);
            }

            let html = '';
            rows.forEach(([label, value]) => {
                html += `
                    <div class="flex items-start justify-between gap-4 py-2.5 border-b border-gray-100 last:border-0">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider shrink-0 w-36">${label}</p>
                        <p class="text-sm font-medium text-gray-800 text-right">${value}</p>
                    </div>`;
            });

            if (data.bukti_foto) {
                const ext = data.bukti_foto.split('.').pop().toLowerCase();
                if (ext === 'pdf') {
                    html += `<a href="../uploads/setoran/${data.bukti_foto}" target="_blank"
                        class="mt-3 flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-red-50 text-red-500 text-sm font-semibold hover:bg-red-100 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Lihat PDF Bukti Setoran</a>`;
                } else {
                    html += `<div class="mt-3">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Bukti Foto</p>
                        <img src="../uploads/setoran/${data.bukti_foto}" alt="Bukti" 
                             class="w-full rounded-xl object-contain max-h-48 cursor-pointer border border-gray-100 hover:opacity-90 transition-opacity"
                             onclick="previewFoto('../uploads/setoran/${data.bukti_foto}')">
                    </div>`;
                }
            }

            document.getElementById('detailContent').innerHTML = html;
            openModal('detail-modal');
        }

        // ── Foto Preview ──────────────────────────────────────────
        function previewFoto(src) {
            document.getElementById('fotoPreviewSrc').src = src;
            openModal('foto-modal');
        }

        // ── Keyboard ──────────────────────────────────────────────
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                ['terima-modal','tolak-modal','detail-modal','foto-modal'].forEach(closeModal);
            }
        });
    </script>
    <script src="../layout/index.js"></script>
</body>

</html>