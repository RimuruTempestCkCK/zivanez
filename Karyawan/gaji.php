<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'Karyawan') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// Ambil data karyawan yang sedang login
$userId = (int)$_SESSION['id'];
$user   = $conn->query("SELECT nama_lengkap, nik, jabatan FROM users WHERE id = $userId")->fetch_assoc();
$nik    = $user['nik'] ?? '';

// Filter
$filterBulan  = trim($_GET['bulan']  ?? '');
$filterTahun  = trim($_GET['tahun']  ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

$where  = "WHERE nik = ?";
$params = [$nik];
$types  = "s";

if ($filterBulan  !== '') { $where .= " AND bulan = ?";  $params[] = $filterBulan;  $types .= "s"; }
if ($filterTahun  !== '') { $where .= " AND tahun = ?";  $params[] = $filterTahun;  $types .= "i"; }
if ($filterStatus !== '') { $where .= " AND status = ?"; $params[] = $filterStatus; $types .= "s"; }

// Total rows
$count = $conn->prepare("SELECT COUNT(*) total FROM penggajian $where");
$count->bind_param($types, ...$params);
$count->execute();
$totalRows = $count->get_result()->fetch_assoc()['total'];
$count->close();

// Data rows
$stmt = $conn->prepare("
    SELECT * FROM penggajian $where
    ORDER BY tahun DESC,
             FIELD(bulan,'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember') DESC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$types   .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages   = max(1, ceil($totalRows / $perPage));
$bulanOptions = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// Hitung ringkasan
$summary = $conn->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status='Sudah Dibayar' THEN 1 ELSE 0 END) AS sudah,
        SUM(CASE WHEN status='Menunggu'      THEN 1 ELSE 0 END) AS menunggu,
        SUM(CASE WHEN status='Belum Dibayar' THEN 1 ELSE 0 END) AS belum,
        SUM(CASE WHEN status='Sudah Dibayar' THEN total_gaji ELSE 0 END) AS total_diterima
    FROM penggajian WHERE nik = '" . $conn->real_escape_string($nik) . "'
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gaji Saya - Sanjai Zivanes</title>
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
            <h2 class="font-bold text-2xl">Gaji Saya</h2>
            <p class="hidden sm:block text-sm text-secondary">Riwayat dan status pembayaran gaji</p>
        </div>
    </div>

    <div class="flex-1 p-5 md:p-8">

        <!-- Info Karyawan -->
        <div class="bg-gradient-to-br from-primary to-blue-600 rounded-2xl p-6 mb-6 text-white">
            <div class="flex items-center gap-4">
                <div class="size-14 rounded-2xl bg-white/20 flex items-center justify-center shrink-0">
                    <i data-lucide="user" class="size-7 text-white"></i>
                </div>
                <div>
                    <p class="text-white/70 text-sm font-medium">Halo,</p>
                    <p class="font-bold text-xl"><?= htmlspecialchars($user['nama_lengkap'] ?? $_SESSION['username']) ?></p>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="text-white/80 text-xs font-mono"><?= htmlspecialchars($nik ?: '—') ?></span>
                        <?php if ($user['jabatan']): ?>
                            <span class="text-white/60">•</span>
                            <span class="text-white/80 text-xs"><?= htmlspecialchars($user['jabatan']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter -->
        <form method="GET" id="filterForm">
            <div class="flex flex-col sm:flex-row gap-3 mb-6">
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
                    <option value="Sudah Dibayar" <?= $filterStatus === 'Sudah Dibayar' ? 'selected':'' ?>>Sudah Dibayar</option>
                    <option value="Menunggu"      <?= $filterStatus === 'Menunggu'      ? 'selected':'' ?>>Menunggu</option>
                    <option value="Belum Dibayar" <?= $filterStatus === 'Belum Dibayar' ? 'selected':'' ?>>Belum Dibayar</option>
                </select>
                <?php if ($filterBulan || $filterTahun || $filterStatus): ?>
                    <a href="gaji.php" class="h-12 px-5 rounded-xl border border-border bg-white text-sm font-medium text-secondary hover:bg-muted flex items-center gap-2 transition-all">
                        <i data-lucide="x" class="size-4"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Tabel -->
        <div class="bg-white rounded-2xl border border-border overflow-hidden mb-8">
            <div class="overflow-x-auto scrollbar-hide">
                <table class="w-full min-w-[700px]">
                    <thead>
                        <tr class="border-b border-border bg-muted/60">
                            <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Periode</th>
                            <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Jabatan</th>
                            <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Gaji Pokok</th>
                            <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Tunjangan</th>
                            <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Total Gaji</th>
                            <th class="text-left px-5 py-4 text-xs font-bold text-secondary uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="6">
                                <div class="py-16 flex flex-col items-center gap-3 text-center">
                                    <div class="size-16 rounded-2xl bg-muted flex items-center justify-center">
                                        <i data-lucide="wallet" class="size-8 text-secondary"></i>
                                    </div>
                                    <p class="font-semibold">Belum ada data gaji</p>
                                    <p class="text-sm text-secondary">Data gaji Anda akan muncul di sini setelah diinput oleh Admin.</p>
                                </div>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                            <tr class="row-hover border-b border-border transition-colors">
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-sm"><?= htmlspecialchars($r['bulan']) ?> <?= htmlspecialchars($r['tahun']) ?></p>
                                </td>
                                <td class="px-5 py-4 text-secondary text-sm"><?= htmlspecialchars($r['jabatan']) ?></td>
                                <td class="px-5 py-4 font-mono text-sm text-secondary">
                                    Rp <?= number_format($r['gaji_pokok'], 0, ',', '.') ?>
                                </td>
                                <td class="px-5 py-4 font-mono text-sm text-secondary">
                                    Rp <?= number_format($r['tunjangan'], 0, ',', '.') ?>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="font-bold text-primary font-mono text-sm">
                                        Rp <?= number_format($r['total_gaji'], 0, ',', '.') ?>
                                    </span>
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
                    dari <span class="font-semibold text-foreground"><?= $totalRows ?></span> data
                </p>
                <?php if ($totalPages > 1): ?>
                <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?=$page-1?>&bulan=<?=urlencode($filterBulan)?>&tahun=<?=urlencode($filterTahun)?>&status=<?=urlencode($filterStatus)?>"
                            class="p-2 rounded-lg border border-border hover:ring-1 hover:ring-primary transition-all">
                            <i data-lucide="chevron-left" class="size-4 text-secondary"></i>
                        </a>
                    <?php endif; ?>
                    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                        <a href="?page=<?=$i?>&bulan=<?=urlencode($filterBulan)?>&tahun=<?=urlencode($filterTahun)?>&status=<?=urlencode($filterStatus)?>"
                            class="size-9 flex items-center justify-center rounded-lg border text-sm font-semibold transition-all
                            <?= $i==$page ? 'bg-primary/10 border-primary/20 text-primary' : 'border-border hover:bg-primary/10 hover:text-primary' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?=$page+1?>&bulan=<?=urlencode($filterBulan)?>&tahun=<?=urlencode($filterTahun)?>&status=<?=urlencode($filterStatus)?>"
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

<script>
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
</script>
<script src="../layout/index.js"></script>
</body>
</html>