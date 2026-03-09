<?php
$role        = $_SESSION['role'] ?? '';
$currentPath = $_SERVER['REQUEST_URI'] ?? '';

// Helper: cek apakah link aktif
function isActive(string $path): bool
{
    return strpos($_SERVER['REQUEST_URI'] ?? '', $path) !== false;
}

// Helper: class link aktif vs normal
function navLink(string $path, string $icon, string $label): string
{
    $active = isActive($path);
    $bg     = $active ? 'bg-primary/10' : 'hover:bg-muted';
    $text   = $active ? 'text-primary font-semibold' : 'text-secondary font-medium';
    $dot    = $active ? '<span class="ml-auto size-1.5 rounded-full bg-primary shrink-0"></span>' : '';
    return <<<HTML
        <a href="{$path}" class="group cursor-pointer">
            <div class="flex items-center rounded-xl p-3.5 gap-3 {$bg} transition-all duration-200">
                <i data-lucide="{$icon}" class="size-5 {$text} shrink-0"></i>
                <span class="{$text}">{$label}</span>
                {$dot}
            </div>
        </a>
    HTML;
}
?>

<!-- Mobile Overlay -->
<div id="sidebar-overlay" class="fixed inset-0 bg-black/80 z-40 lg:hidden hidden" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside id="sidebar"
    class="flex flex-col w-[280px] shrink-0 h-screen fixed inset-y-0 left-0 z-50 bg-white border-r border-border transform -translate-x-full lg:translate-x-0 transition-transform duration-300 overflow-hidden">

    <!-- Logo -->
    <div class="flex items-center border-b border-border h-[90px] px-6 gap-3 shrink-0">
        <div class="size-10 bg-primary rounded-xl flex items-center justify-center shadow-sm shrink-0">
            <i data-lucide="package" class="size-5 text-white"></i>
        </div>
        <h1 class="font-bold text-lg tracking-tight text-primary leading-tight">Sanjai Zivanez</h1>
    </div>

    <!-- Navigation -->
    <nav class="flex flex-col gap-1 px-4 py-5 overflow-y-auto flex-1 scrollbar-hide">

        <p class="text-xs font-semibold uppercase tracking-wider text-secondary px-2 mb-2">Menu Utama</p>

        <!-- ══════════════ ADMIN PUSAT ══════════════ -->
        <?php if ($role === 'AdminP'): ?>

            <?= navLink('/zivanes/AdminP/dashboard.php',           'layout-dashboard', 'Dashboard') ?>

            <!-- Master Data (Accordion) -->
            <?php
            $masterPages   = ['/zivanes/AdminP/kelola_user.php', '/zivanes/AdminP/kelola_cabang.php', '/zivanes/AdminP/kelola_karyawan.php'];
            $masterOpen    = count(array_filter($masterPages, 'isActive')) > 0;
            $masterChevron = $masterOpen ? 'rotate-180' : '';
            $masterHidden  = $masterOpen ? '' : 'hidden';
            $masterBtn     = $masterOpen ? 'bg-primary/5 text-primary' : 'text-secondary hover:bg-muted';
            ?>
            <div>
                <button data-accordion="master-menu"
                    class="flex items-center justify-between w-full rounded-xl px-3.5 py-3.5 cursor-pointer transition-all duration-200 <?= $masterBtn ?>">
                    <div class="flex items-center gap-3">
                        <i data-lucide="layers" class="size-5 shrink-0"></i>
                        <span class="font-medium text-sm">Master Data</span>
                    </div>
                    <i data-lucide="chevron-down" class="size-4 shrink-0 transition-transform duration-200 <?= $masterChevron ?>"></i>
                </button>
                <div id="master-menu" class="<?= $masterHidden ?> mt-1 ml-3 pl-3 border-l-2 border-border flex flex-col gap-0.5">
                    <?php
                    $subItems = [
                        ['/zivanes/AdminP/kelola_user.php',     'users',      'Kelola User'],
                        ['/zivanes/AdminP/kelola_cabang.php',   'store',      'Kelola Cabang'],
                        ['/zivanes/AdminP/kelola_karyawan.php', 'user-check', 'Kelola Karyawan'],
                    ];
                    foreach ($subItems as [$href, $icon, $lbl]):
                        $a = isActive($href);
                        $c = $a ? 'bg-primary/10 text-primary font-semibold' : 'text-secondary font-medium hover:bg-muted';
                    ?>
                        <a href="<?= $href ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm <?= $c ?> transition-all duration-200">
                            <i data-lucide="<?= $icon ?>" class="size-4 shrink-0"></i>
                            <?= $lbl ?>
                            <?php if ($a): ?><span class="ml-auto size-1.5 rounded-full bg-primary shrink-0"></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="my-1 border-t border-border"></div>

            <?= navLink('/zivanes/AdminP/penggajian.php',          'wallet',          'Penggajian') ?>
            <?= navLink('/zivanes/AdminP/validasi_setoran.php',    'badge-check',     'Validasi Setoran') ?>
            <?= navLink('/zivanes/AdminP/stok_gudang.php',         'package-search',  'Stok Gudang') ?>

            <div class="my-1 border-t border-border"></div>
            <p class="text-xs font-semibold uppercase tracking-wider text-secondary px-2 my-1">Laporan</p>

            <?= navLink('/zivanes/AdminP/laporan_setoran.php',     'file-bar-chart',  'Laporan Setoran') ?>
            <?= navLink('/zivanes/AdminP/laporan_karyawan.php',    'file-bar-chart',  'Laporan Karyawan') ?>
            <?= navLink('/zivanes/AdminP/laporan_penggajian.php',  'file-bar-chart',  'Laporan Penggajian') ?>
            <?= navLink('/zivanes/AdminP/laporan_stok.php',  'file-bar-chart',  'Laporan Stok') ?>
            <?= navLink('/zivanes/AdminP/laporan_keuangan.php',        'file-bar-chart',  'Laporan Keuangan') ?>

        <?php endif; ?>

        <!-- ══════════════ ADMIN CABANG ══════════════ -->
        <?php if ($role === 'AdminC'): ?>

            <?= navLink('/zivanes/AdminC/dashboard.php',           'layout-dashboard', 'Dashboard') ?>
            <?= navLink('/zivanes/AdminC/kelola_transaksi.php',    'receipt',          'Transaksi') ?>
            <?= navLink('/zivanes/AdminC/absensi.php',                'archive',          'Absensi') ?>
            <?= navLink('/zivanes/AdminC/stok.php',                'archive',          'Stok') ?>
            <?= navLink('/zivanes/AdminC/setoran.php',             'send',             'Setoran') ?>
            <div class="my-1 border-t border-border"></div>
            <p class="text-xs font-semibold uppercase tracking-wider text-secondary px-2 my-1">Laporan</p>
            <?= navLink('/zivanes/AdminC/laporan_penjualan.php',   'file-bar-chart',  'Laporan Penjualan') ?>
            <?= navLink('/zivanes/AdminC/laporan_stok.php',        'file-bar-chart',  'Laporan Stok') ?>
            <?= navLink('/zivanes/AdminC/laporan_keuangan.php',        'file-bar-chart',  'Laporan Keuangan') ?>

        <?php endif; ?>

        <!-- ══════════════ BAGIAN GUDANG ══════════════ -->
        <?php if ($role === 'BG'): ?>

            <?= navLink('/zivanes/BG/dashboard.php',               'layout-dashboard', 'Dashboard') ?>
            <?= navLink('/zivanes/BG/kelola_stok.php',             'boxes',            'Kelola Stok') ?>
            <?= navLink('/zivanes/BG/laporan_stok.php',             'file-bar-chart',            'Laporan Stok') ?>

        <?php endif; ?>

        <!-- ══════════════ KARYAWAN ══════════════ -->
        <?php if ($role === 'Karyawan'): ?>

            <?= navLink('/zivanes/Karyawan/dashboard.php',         'layout-dashboard', 'Dashboard') ?>
            <?= navLink('/zivanes/Karyawan/gaji.php',              'wallet',           'Gaji Saya') ?>

        <?php endif; ?>

        <!-- ══════════════ PEMILIK ══════════════ -->
        <?php if ($role === 'Pemilik'): ?>

            <?= navLink('/zivanes/Pemilik/dashboard.php',          'layout-dashboard', 'Dashboard') ?>
            <?= navLink('/zivanes/Pemilik/laporan_setoran.php',     'file-bar-chart',  'Laporan Setoran') ?>
            <?= navLink('/zivanes/Pemilik/laporan_karyawan.php',    'file-bar-chart',  'Laporan Karyawan') ?>
            <?= navLink('/zivanes/Pemilik/laporan_penggajian.php',  'file-bar-chart',  'Laporan Penggajian') ?>
            <?= navLink('/zivanes/Pemilik/laporan_stok.php',             'file-bar-chart',            'Laporan Stok') ?>
            <?= navLink('/zivanes/Pemilik/laporan_keuangan.php',        'file-bar-chart',  'Laporan Keuangan') ?>

        <?php endif; ?>

    </nav>

    <!-- Bottom Profile -->
    <div class="p-4 border-t border-border bg-white shrink-0">
        <div class="flex items-center gap-3 p-3 rounded-2xl border border-border bg-muted/40">
            <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                <i data-lucide="user" class="size-4 text-primary"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="font-bold text-sm text-foreground truncate">
                    <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?>
                </p>
                <p class="text-xs text-secondary truncate">
                    <?= htmlspecialchars($_SESSION['role'] ?? '') ?>
                </p>
            </div>
            <a href="/zivanes/logout.php"
                class="size-8 flex items-center justify-center rounded-lg text-secondary hover:text-error hover:bg-error/10 transition-all duration-200 cursor-pointer shrink-0"
                title="Logout">
                <i data-lucide="log-out" class="size-4"></i>
            </a>
        </div>
    </div>

</aside>

<script>
    // Jalankan accordion SETELAH Lucide selesai render icons
    document.addEventListener('DOMContentLoaded', function() {

        // Render Lucide dulu
        if (typeof lucide !== 'undefined') lucide.createIcons();

        document.querySelectorAll('[data-accordion]').forEach(function(btn) {
            var targetId = btn.getAttribute('data-accordion');
            var target = document.getElementById(targetId);
            if (!target) return;

            // Simpan referensi wrapper chevron (div terakhir di button)
            // Kita pakai data attribute untuk menyimpan state
            var isOpen = !target.classList.contains('hidden');
            btn.setAttribute('data-open', isOpen ? '1' : '0');

            btn.addEventListener('click', function() {
                var open = btn.getAttribute('data-open') === '1';

                // Toggle target
                target.classList.toggle('hidden', open);

                // Toggle chevron via transform pada SVG
                var svgs = btn.querySelectorAll('svg');
                var chevronSvg = svgs[svgs.length - 1]; // SVG terakhir = chevron
                if (chevronSvg) {
                    if (open) {
                        chevronSvg.style.transform = '';
                    } else {
                        chevronSvg.style.transform = 'rotate(180deg)';
                    }
                    chevronSvg.style.transition = 'transform 0.2s ease';
                }

                // Toggle warna button
                if (open) {
                    btn.classList.remove('bg-primary/5', 'text-primary');
                    btn.classList.add('text-secondary', 'hover:bg-muted');
                } else {
                    btn.classList.remove('text-secondary', 'hover:bg-muted');
                    btn.classList.add('bg-primary/5', 'text-primary');
                }

                btn.setAttribute('data-open', open ? '0' : '1');
            });

            // Set tampilan awal chevron jika sudah terbuka dari PHP
            if (isOpen) {
                var svgs = btn.querySelectorAll('svg');
                var chevronSvg = svgs[svgs.length - 1];
                if (chevronSvg) {
                    chevronSvg.style.transform = 'rotate(180deg)';
                    chevronSvg.style.transition = 'transform 0.2s ease';
                }
            }
        });
    });
</script>

<script>
    lucide.createIcons();
</script>