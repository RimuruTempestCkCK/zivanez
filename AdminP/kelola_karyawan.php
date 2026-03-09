<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'AdminP') {
    header("Location: ../login.php");
    exit;
}

// ── Koneksi Database ──────────────────────────────────────
require_once __DIR__ . '/../config.php';

// ── Helper Message ────────────────────────────────────────
$msg = '';
$msgType = '';

// ── CRUD HANDLER ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ===================== CREATE ===================== */
    if ($_POST['action'] === 'create') {

        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $nik          = trim($_POST['nik'] ?? '');
        $jabatan      = trim($_POST['jabatan'] ?? '');
        $cabang_id    = !empty($_POST['cabang_id']) ? (int)$_POST['cabang_id'] : null;
        $username     = trim($_POST['username'] ?? '');
        $password     = trim($_POST['password'] ?? '');

        if ($nama_lengkap === '' || $nik === '' || $jabatan === '' || $username === '' || $password === '') {
            $msg = "Semua field wajib diisi";
            $msgType = 'error';
        } elseif (strlen($username) < 3) {
            $msg = "Username minimal 3 karakter";
            $msgType = 'error';
        } elseif (strlen($password) < 3) {
            $msg = "Password minimal 3 karakter";
            $msgType = 'error';
        } else {

            // cek username
            $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();

            if ($check->get_result()->num_rows > 0) {
                $msg = "Username sudah terdaftar";
                $msgType = 'error';
            } else {

                $stmt = $conn->prepare("
                    INSERT INTO users 
                    (nama_lengkap, nik, jabatan, cabang_id, username, password, role)
                    VALUES (?, ?, ?, ?, ?, ?, 'Karyawan')
                ");
                $stmt->bind_param(
                    "sssiss",
                    $nama_lengkap,
                    $nik,
                    $jabatan,
                    $cabang_id,
                    $username,
                    $password
                );

                if ($stmt->execute()) {
                    $msg = "Data karyawan berhasil ditambahkan";
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

        $id           = (int)($_POST['id'] ?? 0);
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $nik          = trim($_POST['nik'] ?? '');
        $jabatan      = trim($_POST['jabatan'] ?? '');
        $cabang_id    = !empty($_POST['cabang_id']) ? (int)$_POST['cabang_id'] : null;
        $username     = trim($_POST['username'] ?? '');
        $password     = trim($_POST['password'] ?? '');

        if ($id === 0) {
            $msg = "ID tidak valid";
            $msgType = 'error';
        } elseif ($nama_lengkap === '' || $nik === '' || $jabatan === '' || $username === '' || $password === '') {
            $msg = "Semua field wajib diisi";
            $msgType = 'error';
        } else {

            // cek username kecuali diri sendiri
            $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check->bind_param("si", $username, $id);
            $check->execute();

            if ($check->get_result()->num_rows > 0) {
                $msg = "Username sudah digunakan user lain";
                $msgType = 'error';
            } else {

                $stmt = $conn->prepare("
                    UPDATE users 
                    SET nama_lengkap=?, nik=?, jabatan=?, cabang_id=?, username=?, password=? 
                    WHERE id=?
                ");
                $stmt->bind_param(
                    "ssssssi",
                    $nama_lengkap,
                    $nik,
                    $jabatan,
                    $cabang_id,
                    $username,
                    $password,
                    $id
                );

                if ($stmt->execute()) {
                    $msg = "Data karyawan berhasil diperbarui";
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

        $id = (int)($_POST['id'] ?? 0);

        if ($id === ($_SESSION['user_id'] ?? -1)) {
            $msg = "Tidak dapat menghapus akun sendiri";
            $msgType = 'error';
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $msg = "Data berhasil dihapus";
                $msgType = 'success';
            } else {
                $msg = "Gagal menghapus data";
                $msgType = 'error';
            }
            $stmt->close();
        }
    }

    /* ===================== BULK DELETE ===================== */
    if ($_POST['action'] === 'bulk_delete') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $idList = implode(',', $ids);
            $conn->query("DELETE FROM users WHERE id IN ($idList)");
            $msg = count($ids) . " data berhasil dihapus";
            $msgType = 'success';
        }
    }
}

// ── Fetch Cabang untuk Dropdown ───────────────────────────
$cabangList = $conn->query("SELECT id, kode_cabang, nama_cabang FROM cabang WHERE status='Aktif' ORDER BY nama_cabang ASC")->fetch_all(MYSQLI_ASSOC);

/* ===================== FETCH DATA ===================== */
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$where  = "WHERE u.role='Karyawan'";
$params = [];
$types  = "";

if ($search !== '') {
    $where .= " AND (u.username LIKE ? OR u.nama_lengkap LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

// count
$count = $conn->prepare("SELECT COUNT(*) total FROM users u $where");
if ($params) $count->bind_param($types, ...$params);
$count->execute();
$totalRows = $count->get_result()->fetch_assoc()['total'];
$count->close();

// data — JOIN cabang untuk dapat nama_cabang
$sql = "SELECT u.*, c.nama_cabang, c.kode_cabang 
        FROM users u 
        LEFT JOIN cabang c ON u.cabang_id = c.id
        $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$params[] = $perPage;
$params[] = $offset;
$types   .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages = max(1, ceil($totalRows / $perPage));

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola User - Sanjai Zivanes</title>
    <meta name="description" content="Kelola data user dan akses Sanjai Zivanes.">
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
        .status-badge { @apply inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold; }
        .badge-adminp   { @apply bg-primary/10 text-primary; }
        .badge-adminc { @apply bg-success/10 text-success; }
        .badge-bg { @apply bg-warning/20 text-yellow-700; }
        .badge-karyawan { @apply bg-secondary/10 text-secondary; }
        .badge-pemilik { @apply bg-primary/20 text-primary; }
    </style>
</head>

<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">

        <!-- Top Header -->
        <div class="sticky top-0 z-30 flex items-center justify-between w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" aria-label="Open menu"
                    class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all duration-300 cursor-pointer">
                    <i data-lucide="menu" class="size-6 text-foreground"></i>
                </button>
                <div>
                    <h2 class="font-bold text-2xl text-foreground">Kelola Karyawan</h2>
                    <p class="hidden sm:block text-sm text-secondary">Manajemen akun karyawan dan akses</p>
                </div>
            </div>
        </div>

        <!-- Page Content -->
        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <!-- Actions Toolbar -->
            <form method="GET" id="filterForm">
                <div class="flex flex-col md:flex-row gap-4 justify-between items-center mb-6">
                    <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-1 max-w-2xl">
                        <div class="relative flex-1 group">
                            <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary group-focus-within:text-primary transition-colors"></i>
                            <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Cari username..."
                                class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300">
                        </div>
                    </div>
                    <div class="flex gap-3 w-full md:w-auto">
                        <button type="button" onclick="openAddModal()"
                            class="flex-1 md:flex-none px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 hover:shadow-primary/40 flex items-center justify-center gap-2 transition-all duration-300 cursor-pointer">
                            <i data-lucide="plus" class="size-5"></i>
                            <span>Tambah Karyawan</span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Table Container -->
            <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full min-w-[1000px]" id="userTable">
                        <thead>
                            <tr class="border-b border-border bg-muted/60">
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider w-10">
                                    <input type="checkbox" id="selectAll"
                                        onchange="toggleSelectAll(this)"
                                        class="size-4 rounded cursor-pointer accent-primary">
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Nama Lengkap
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    NIK
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Jabatan
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Cabang
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Username
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Password
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Dibuat
                                </th>
                                <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Status
                                </th>
                                <th class="text-center px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">
                                    Aksi
                                </th>
                            </tr>
                        </thead>

                        <tbody id="tableBody">
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="py-16 flex flex-col items-center justify-center gap-3 text-center">
                                            <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                                <i data-lucide="search-x" class="size-8 text-secondary"></i>
                                            </div>
                                            <p class="font-semibold text-foreground">Data tidak ditemukan</p>
                                            <p class="text-sm text-secondary">
                                                Belum ada karyawan. Klik "Tambah Karyawan" untuk mulai.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr class="table-row-hover border-b border-border transition-colors duration-150"
                                        data-id="<?= (int)$r['id'] ?>">

                                        <!-- Checkbox -->
                                        <td class="px-5 py-4">
                                            <input type="checkbox"
                                                class="row-check size-4 rounded cursor-pointer accent-primary"
                                                value="<?= (int)$r['id'] ?>">
                                        </td>

                                        <!-- Nama Lengkap -->
                                        <td class="px-5 py-4">
                                            <p class="font-semibold text-foreground text-sm">
                                                <?= htmlspecialchars($r['nama_lengkap'] ?: '-') ?>
                                            </p>
                                        </td>

                                        <!-- NIK -->
                                        <td class="px-5 py-4 font-mono text-sm text-secondary">
                                            <?= htmlspecialchars($r['nik'] ?: '-') ?>
                                        </td>

                                        <!-- Jabatan -->
                                        <td class="px-5 py-4 text-secondary text-sm">
                                            <?= htmlspecialchars($r['jabatan'] ?: '-') ?>
                                        </td>

                                        <!-- Cabang -->
                                        <td class="px-5 py-4">
                                            <?php if (!empty($r['nama_cabang'])): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold">
                                                    <i data-lucide="map-pin" class="size-3"></i>
                                                    <?= htmlspecialchars($r['nama_cabang']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-secondary text-sm">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Username -->
                                        <td class="px-5 py-4 text-secondary text-sm">
                                            <?= htmlspecialchars($r['username']) ?>
                                        </td>

                                        <!-- Password -->
                                        <td class="px-5 py-4">
                                            <p class="font-mono text-xs text-secondary font-semibold">
                                                <?= str_repeat('•', strlen($r['password'])) ?>
                                            </p>
                                        </td>

                                        <!-- Dibuat -->
                                        <td class="px-5 py-4">
                                            <p class="text-sm font-medium text-secondary">
                                                <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                                            </p>
                                        </td>

                                        <!-- Status -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-success/10 text-success text-xs font-bold">
                                                Aktif
                                            </span>
                                        </td>

                                        <!-- Aksi -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button type="button"
                                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"
                                                    title="Edit"
                                                    class="size-9 flex items-center justify-center rounded-lg bg-primary/10 hover:bg-primary text-primary hover:text-white transition-all duration-200 cursor-pointer">
                                                    <i data-lucide="pencil" class="size-4"></i>
                                                </button>
                                                <button type="button"
                                                    onclick="confirmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['username'], ENT_QUOTES) ?>')"
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

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-2">

                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>"
                                    class="p-2 rounded-lg border border-border bg-white hover:ring-1 hover:ring-primary transition-all cursor-pointer">
                                    <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                                </a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                                    class="size-9 flex items-center justify-center rounded-lg border
                                    <?= $i == $page
                                    ? 'bg-primary/10 border-primary/20 font-semibold text-primary'
                                    : 'border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold' ?>
                                    text-sm transition-all cursor-pointer">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>"
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

    <!-- ===================== MODALS ===================== -->

    <!-- Add / Edit Modal -->
    <div id="add-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="font-bold text-xl text-foreground" id="modalTitle">Tambah Karyawan</h3>
                <button onclick="closeAddModal()" class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>

            <form id="crudForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formId" value="">

                <div class="p-6 space-y-4 overflow-y-auto max-h-[65vh]">

                    <div class="grid grid-cols-1 gap-4">

                        <!-- Nama Lengkap -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="user" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputNama" name="nama_lengkap" type="text" required
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label for="inputNama" class="absolute left-12 text-secondary text-xs font-medium top-2">Nama Lengkap *</label>
                        </div>

                        <!-- NIK -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="credit-card" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputNIK" name="nik" type="text" required
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label for="inputNIK" class="absolute left-12 text-secondary text-xs font-medium top-2">NIK *</label>
                        </div>

                        <!-- Jabatan -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="briefcase" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputJabatan" name="jabatan" type="text" required
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label for="inputJabatan" class="absolute left-12 text-secondary text-xs font-medium top-2">Jabatan *</label>
                        </div>

                        <!-- Cabang -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="map-pin" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary pointer-events-none z-10"></i>
                            <label for="inputCabang" class="absolute left-12 text-secondary text-xs font-medium top-2 z-10 pointer-events-none">Cabang</label>
                            <select id="inputCabang" name="cabang_id"
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm rounded-2xl">
                                <option value="">-- Pilih Cabang --</option>
                                <?php foreach ($cabangList as $cab): ?>
                                    <option value="<?= $cab['id'] ?>">
                                        <?= htmlspecialchars($cab['kode_cabang'] . ' - ' . $cab['nama_cabang']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Username -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="at-sign" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputUsername" name="username" type="text" required
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label for="inputUsername" class="absolute left-12 text-secondary text-xs font-medium top-2">Username *</label>
                        </div>

                        <!-- Password -->
                        <div class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all bg-white">
                            <i data-lucide="lock" class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputPassword" name="password" type="password" required
                                class="absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground text-sm" placeholder=" ">
                            <label for="inputPassword" class="absolute left-12 text-secondary text-xs font-medium top-2">Password *</label>
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
                        Simpan Karyawan
                    </button>
                </div>
            </form>
        </div>
    </div>


    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-sm shadow-2xl">
            <div class="p-8 flex flex-col items-center gap-4 text-center">
                <div class="size-16 rounded-2xl bg-error/10 flex items-center justify-center">
                    <i data-lucide="trash-2" class="size-8 text-error"></i>
                </div>
                <div>
                    <h3 class="font-bold text-xl text-foreground">Hapus User</h3>
                    <p class="text-sm text-secondary mt-2">Hapus user <strong id="deleteUsername"></strong>? Tindakan ini tidak dapat dibatalkan.</p>
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

    <!-- Bulk Action Form (hidden) -->
    <form id="bulkForm" method="POST">
        <input type="hidden" name="action" id="bulkAction" value="">
        <div id="bulkIdsContainer"></div>
    </form>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] hidden items-center gap-3 px-5 py-4 rounded-2xl shadow-xl border border-border bg-white max-w-xs transition-all duration-300">
        <div id="toastIcon" class="size-9 rounded-xl flex items-center justify-center shrink-0"></div>
        <p id="toastMsg" class="font-semibold text-sm text-foreground"></p>
    </div>

    <!-- PHP flash message → JS toast trigger -->
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

        // ── Toast ─────────────────────────────────────────────────
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            const msg = document.getElementById('toastMsg');
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
            setTimeout(() => {
                toast.classList.add('hidden');
                toast.classList.remove('flex');
            }, 3500);
        }

        // ── Select All / Bulk ─────────────────────────────────────
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
            checked > 0
                ? (bulk.classList.remove('hidden'), bulk.classList.add('flex'))
                : (bulk.classList.add('hidden'), bulk.classList.remove('flex'));
        }

        function getCheckedIds() {
            return [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
        }

        function bulkDelete() {
            const ids = getCheckedIds();
            if (!ids.length) return;
            if (!confirm(`Hapus ${ids.length} user? Tindakan ini tidak dapat dibatalkan.`)) return;
            submitBulk('bulk_delete', ids);
        }

        function submitBulk(action, ids) {
            document.getElementById('bulkAction').value = action;
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

        // ── Add / Edit Modal ──────────────────────────────────────
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Karyawan';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
            document.getElementById('crudForm').reset();
            toggleModal('add-modal', true);
        }

        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Edit Karyawan';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formId').value = data.id;

            document.getElementById('inputNama').value     = data.nama_lengkap || '';
            document.getElementById('inputNIK').value      = data.nik || '';
            document.getElementById('inputJabatan').value  = data.jabatan || '';
            document.getElementById('inputCabang').value   = data.cabang_id || '';
            document.getElementById('inputUsername').value = data.username || '';
            document.getElementById('inputPassword').value = data.password || '';

            toggleModal('add-modal', true);
        }

        function closeAddModal() {
            toggleModal('add-modal', false);
        }

        // ── Delete Modal ──────────────────────────────────────────
        function confirmDelete(id, username) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteUsername').textContent = username;
            toggleModal('delete-modal', true);
        }

        function closeDeleteModal() {
            toggleModal('delete-modal', false);
        }

        // ── Modal Helper ──────────────────────────────────────────
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

        // ── Keyboard Shortcuts ─────────────────────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeDeleteModal();
            }
        });
    </script>
    <script src="../layout/index.js"></script>
</body>

</html>