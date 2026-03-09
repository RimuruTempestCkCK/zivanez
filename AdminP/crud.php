<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kepala Rumah - DesaKita Dashboard</title>
    <meta name="description" content="Kelola data Kepala Rumah Tangga dan kependudukan DesaKita.">
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
    --primary: #165DFF;
    --primary-hover: #0E4BD9;
    --foreground: #080C1A;
    --secondary: #6A7686;
    --muted: #EFF2F7;
    --border: #F3F4F3;
    --card-grey: #F1F3F6;
    --success: #30B22D;
    --success-light: #DCFCE7;
    --error: #ED6B60;
    --error-light: #FEE2E2;
    --warning: #FED71F;
    --warning-light: #FEF9C3;
    --font-sans: 'Lexend Deca', sans-serif;
  }
  @theme inline {
    --color-primary: var(--primary);
    --color-primary-hover: var(--primary-hover);
    --color-foreground: var(--foreground);
    --color-secondary: var(--secondary);
    --color-muted: var(--muted);
    --color-border: var(--border);
    --color-card-grey: var(--card-grey);
    --color-success: var(--success);
    --color-success-light: var(--success-light);
    --color-error: var(--error);
    --color-error-light: var(--error-light);
    --color-warning: var(--warning);
    --color-warning-light: var(--warning-light);
    --font-sans: var(--font-sans);
    --radius-card: 24px;
    --radius-button: 50px;
  }
  select {
    @apply appearance-none bg-no-repeat cursor-pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-position: right 10px center;
    padding-right: 40px;
  }
  .scrollbar-hide::-webkit-scrollbar { display: none; }
  .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
</style>
</head>

<body class="font-sans bg-white min-h-screen overflow-x-hidden text-foreground">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="flex-1 lg:ml-[280px] flex flex-col min-h-screen overflow-x-hidden relative">
        <!-- Top Header Bar -->
        <div class="sticky top-0 z-30 flex items-center justify-between w-full h-[90px] shrink-0 border-b border-border bg-white/80 backdrop-blur-md px-5 md:px-8">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" aria-label="Open menu"
                    class="lg:hidden size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all duration-300 cursor-pointer">
                    <i data-lucide="menu" class="size-6 text-foreground"></i>
                </button>
                <div>
                    <h2 class="font-bold text-2xl text-foreground">Kepala Rumah</h2>
                    <p class="hidden sm:block text-sm text-secondary">Kelola data kepala keluarga dan anggota</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="openSearchModal()"
                    class="size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all duration-300 cursor-pointer bg-white"
                    aria-label="Search">
                    <i data-lucide="search" class="size-5 text-secondary"></i>
                </button>
                <button
                    class="size-11 flex items-center justify-center rounded-xl ring-1 ring-border hover:ring-primary transition-all duration-300 cursor-pointer bg-white relative"
                    aria-label="Notifications">
                    <i data-lucide="bell" class="size-5 text-secondary"></i>
                    <span
                        class="absolute top-2 right-2 size-2.5 rounded-full bg-error border-2 border-white"></span>
                </button>
            </div>
        </div>

        <!-- Page Content -->
        <div class="flex-1 p-5 md:p-8 overflow-y-auto">

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                        <i data-lucide="users" class="size-6"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-secondary">Total KK</p>
                        <p class="text-2xl font-bold text-foreground">1,245</p>
                    </div>
                </div>
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-success/10 flex items-center justify-center text-success">
                        <i data-lucide="user-check" class="size-6"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-secondary">Warga Tetap</p>
                        <p class="text-2xl font-bold text-foreground">3,892</p>
                    </div>
                </div>
                <div class="flex items-center gap-4 p-5 rounded-2xl bg-white border border-border">
                    <div class="size-12 rounded-xl bg-warning/10 flex items-center justify-center text-warning">
                        <i data-lucide="home" class="size-6"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-secondary">Rumah Tangga</p>
                        <p class="text-2xl font-bold text-foreground">856</p>
                    </div>
                </div>
            </div>

            <!-- Actions Toolbar -->
            <div class="flex flex-col md:flex-row gap-4 justify-between items-center mb-6">
                <!-- Search & Filter -->
                <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-1 max-w-2xl">
                    <div class="relative flex-1 group">
                        <i data-lucide="search"
                            class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary group-focus-within:text-primary transition-colors"></i>
                        <input type="text" placeholder="Cari nama kepala rumah atau NIK..."
                            class="w-full h-12 pl-12 pr-4 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300">
                    </div>

                    <div class="flex gap-2">
                        <div class="relative min-w-[140px]">
                            <select
                                class="w-full h-12 pl-4 pr-10 rounded-xl border border-border bg-white text-sm font-medium focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all duration-300 text-secondary">
                                <option value="">Semua RW</option>
                                <option value="01">RW 01</option>
                                <option value="02">RW 02</option>
                                <option value="03">RW 03</option>
                            </select>
                        </div>
                        <button
                            class="size-12 flex items-center justify-center rounded-xl bg-white border border-border hover:border-primary text-secondary hover:text-primary transition-all duration-300 cursor-pointer">
                            <i data-lucide="filter" class="size-5"></i>
                        </button>
                    </div>
                </div>

                <!-- Add Button -->
                <button onclick="openAddModal()"
                    class="w-full md:w-auto px-6 h-12 bg-primary hover:bg-primary-hover text-white rounded-full font-bold shadow-lg shadow-primary/20 hover:shadow-primary/40 flex items-center justify-center gap-2 transition-all duration-300 cursor-pointer shrink-0">
                    <i data-lucide="plus" class="size-5"></i>
                    <span>Tambah KK</span>
                </button>
            </div>

            <!-- Data Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 mb-8">

                <!-- Card 1 -->
                <div
                    class="group bg-white rounded-3xl p-5 border border-border hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300 flex flex-col gap-5 relative">
                    <div class="flex items-start justify-between">
                        <div class="flex gap-4">
                            <div class="relative">
                                <img src="https://images.unsplash.com/photo-1542909168-82c3e7fdca5c?w=200&h=200&fit=crop"
                                    alt="Budi Santoso"
                                    class="size-16 rounded-2xl object-cover ring-2 ring-border group-hover:ring-primary transition-all duration-300">
                                <span
                                    class="absolute -bottom-1 -right-1 size-5 bg-success border-2 border-white rounded-full flex items-center justify-center">
                                    <i data-lucide="check" class="size-3 text-white"></i>
                                </span>
                            </div>
                            <div class="flex flex-col pt-1">
                                <h3 class="font-bold text-foreground text-lg leading-tight">Budi Santoso</h3>
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold w-fit mt-1.5">
                                    <i data-lucide="briefcase" class="size-3"></i>
                                    Wiraswasta
                                </span>
                            </div>
                        </div>
                        <button
                            class="p-2 rounded-xl hover:bg-muted text-secondary transition-colors cursor-pointer">
                            <i data-lucide="more-vertical" class="size-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 py-4 border-y border-dashed border-border">
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Nomor NIK</p>
                            <p class="font-semibold text-foreground font-mono text-sm">3201284405920001</p>
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Anggota</p>
                            <div class="flex items-center gap-2">
                                <div class="flex -space-x-2 overflow-hidden">
                                    <div class="size-6 rounded-full bg-gray-200 ring-2 ring-white"></div>
                                    <div class="size-6 rounded-full bg-gray-300 ring-2 ring-white"></div>
                                    <div class="size-6 rounded-full bg-gray-400 ring-2 ring-white"></div>
                                </div>
                                <span class="text-sm font-bold text-foreground">4 Orang</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="manageMember('1')"
                            class="flex-1 h-11 rounded-xl border border-border hover:border-primary text-secondary hover:text-primary font-semibold text-sm transition-all duration-300 cursor-pointer">
                            Lihat Detail
                        </button>
                        <button onclick="manageMember('1')"
                            class="flex-1 h-11 rounded-xl bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/20 transition-all duration-300 cursor-pointer">
                            Kelola
                        </button>
                    </div>
                </div>

                <!-- Card 2 -->
                <div
                    class="group bg-white rounded-3xl p-5 border border-border hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300 flex flex-col gap-5 relative">
                    <div class="flex items-start justify-between">
                        <div class="flex gap-4">
                            <div class="relative">
                                <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=200&h=200&fit=crop"
                                    alt="Siti Aminah"
                                    class="size-16 rounded-2xl object-cover ring-2 ring-border group-hover:ring-primary transition-all duration-300">
                                <span
                                    class="absolute -bottom-1 -right-1 size-5 bg-success border-2 border-white rounded-full flex items-center justify-center">
                                    <i data-lucide="check" class="size-3 text-white"></i>
                                </span>
                            </div>
                            <div class="flex flex-col pt-1">
                                <h3 class="font-bold text-foreground text-lg leading-tight">Siti Aminah</h3>
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-accent-teal/20 text-teal-700 text-xs font-bold w-fit mt-1.5">
                                    <i data-lucide="store" class="size-3"></i>
                                    Pedagang
                                </span>
                            </div>
                        </div>
                        <button
                            class="p-2 rounded-xl hover:bg-muted text-secondary transition-colors cursor-pointer">
                            <i data-lucide="more-vertical" class="size-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 py-4 border-y border-dashed border-border">
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Nomor NIK</p>
                            <p class="font-semibold text-foreground font-mono text-sm">3201285501880002</p>
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Anggota</p>
                            <div class="flex items-center gap-2">
                                <div class="flex -space-x-2 overflow-hidden">
                                    <div class="size-6 rounded-full bg-gray-200 ring-2 ring-white"></div>
                                    <div class="size-6 rounded-full bg-gray-300 ring-2 ring-white"></div>
                                </div>
                                <span class="text-sm font-bold text-foreground">3 Orang</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="manageMember('2')"
                            class="flex-1 h-11 rounded-xl border border-border hover:border-primary text-secondary hover:text-primary font-semibold text-sm transition-all duration-300 cursor-pointer">
                            Lihat Detail
                        </button>
                        <button onclick="manageMember('2')"
                            class="flex-1 h-11 rounded-xl bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/20 transition-all duration-300 cursor-pointer">
                            Kelola
                        </button>
                    </div>
                </div>

                <!-- Card 3 -->
                <div
                    class="group bg-white rounded-3xl p-5 border border-border hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300 flex flex-col gap-5 relative">
                    <div class="flex items-start justify-between">
                        <div class="flex gap-4">
                            <div class="relative">
                                <img src="https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=200&h=200&fit=crop"
                                    alt="Ahmad Rizky"
                                    class="size-16 rounded-2xl object-cover ring-2 ring-border group-hover:ring-primary transition-all duration-300">
                                <span
                                    class="absolute -bottom-1 -right-1 size-5 bg-warning border-2 border-white rounded-full flex items-center justify-center">
                                    <i data-lucide="clock" class="size-3 text-white"></i>
                                </span>
                            </div>
                            <div class="flex flex-col pt-1">
                                <h3 class="font-bold text-foreground text-lg leading-tight">Ahmad Rizky</h3>
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-600 text-xs font-bold w-fit mt-1.5">
                                    <i data-lucide="graduation-cap" class="size-3"></i>
                                    Guru
                                </span>
                            </div>
                        </div>
                        <button
                            class="p-2 rounded-xl hover:bg-muted text-secondary transition-colors cursor-pointer">
                            <i data-lucide="more-vertical" class="size-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 py-4 border-y border-dashed border-border">
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Nomor NIK</p>
                            <p class="font-semibold text-foreground font-mono text-sm">3201281109950004</p>
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Anggota</p>
                            <div class="flex items-center gap-2">
                                <div class="flex -space-x-2 overflow-hidden">
                                    <div class="size-6 rounded-full bg-gray-200 ring-2 ring-white"></div>
                                </div>
                                <span class="text-sm font-bold text-foreground">2 Orang</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="manageMember('3')"
                            class="flex-1 h-11 rounded-xl border border-border hover:border-primary text-secondary hover:text-primary font-semibold text-sm transition-all duration-300 cursor-pointer">
                            Lihat Detail
                        </button>
                        <button onclick="manageMember('3')"
                            class="flex-1 h-11 rounded-xl bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/20 transition-all duration-300 cursor-pointer">
                            Kelola
                        </button>
                    </div>
                </div>

                <!-- Card 4 -->
                <div
                    class="group bg-white rounded-3xl p-5 border border-border hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300 flex flex-col gap-5 relative">
                    <div class="flex items-start justify-between">
                        <div class="flex gap-4">
                            <div class="relative">
                                <img src="https://images.unsplash.com/photo-1580489944761-15a19d654956?w=200&h=200&fit=crop"
                                    alt="Dewi Sartika"
                                    class="size-16 rounded-2xl object-cover ring-2 ring-border group-hover:ring-primary transition-all duration-300">
                                <span
                                    class="absolute -bottom-1 -right-1 size-5 bg-success border-2 border-white rounded-full flex items-center justify-center">
                                    <i data-lucide="check" class="size-3 text-white"></i>
                                </span>
                            </div>
                            <div class="flex flex-col pt-1">
                                <h3 class="font-bold text-foreground text-lg leading-tight">Dewi Sartika</h3>
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-pink-50 text-pink-600 text-xs font-bold w-fit mt-1.5">
                                    <i data-lucide="heart-pulse" class="size-3"></i>
                                    Perawat
                                </span>
                            </div>
                        </div>
                        <button
                            class="p-2 rounded-xl hover:bg-muted text-secondary transition-colors cursor-pointer">
                            <i data-lucide="more-vertical" class="size-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 py-4 border-y border-dashed border-border">
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Nomor NIK</p>
                            <p class="font-semibold text-foreground font-mono text-sm">3201286603900003</p>
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Anggota</p>
                            <div class="flex items-center gap-2">
                                <div class="flex -space-x-2 overflow-hidden">
                                    <div class="size-6 rounded-full bg-gray-200 ring-2 ring-white"></div>
                                    <div class="size-6 rounded-full bg-gray-300 ring-2 ring-white"></div>
                                    <div class="size-6 rounded-full bg-gray-400 ring-2 ring-white"></div>
                                    <div
                                        class="size-6 rounded-full bg-gray-500 ring-2 ring-white text-[10px] flex items-center justify-center text-white">
                                        +1</div>
                                </div>
                                <span class="text-sm font-bold text-foreground">5 Orang</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="manageMember('4')"
                            class="flex-1 h-11 rounded-xl border border-border hover:border-primary text-secondary hover:text-primary font-semibold text-sm transition-all duration-300 cursor-pointer">
                            Lihat Detail
                        </button>
                        <button onclick="manageMember('4')"
                            class="flex-1 h-11 rounded-xl bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/20 transition-all duration-300 cursor-pointer">
                            Kelola
                        </button>
                    </div>
                </div>

                <!-- Card 5 -->
                <div
                    class="group bg-white rounded-3xl p-5 border border-border hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300 flex flex-col gap-5 relative">
                    <div class="flex items-start justify-between">
                        <div class="flex gap-4">
                            <div class="relative">
                                <img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=200&h=200&fit=crop"
                                    alt="Hendra Wijaya"
                                    class="size-16 rounded-2xl object-cover ring-2 ring-border group-hover:ring-primary transition-all duration-300">
                                <span
                                    class="absolute -bottom-1 -right-1 size-5 bg-success border-2 border-white rounded-full flex items-center justify-center">
                                    <i data-lucide="check" class="size-3 text-white"></i>
                                </span>
                            </div>
                            <div class="flex flex-col pt-1">
                                <h3 class="font-bold text-foreground text-lg leading-tight">Hendra Wijaya</h3>
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-orange-50 text-orange-600 text-xs font-bold w-fit mt-1.5">
                                    <i data-lucide="tractor" class="size-3"></i>
                                    Petani
                                </span>
                            </div>
                        </div>
                        <button
                            class="p-2 rounded-xl hover:bg-muted text-secondary transition-colors cursor-pointer">
                            <i data-lucide="more-vertical" class="size-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 py-4 border-y border-dashed border-border">
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Nomor NIK</p>
                            <p class="font-semibold text-foreground font-mono text-sm">3201283307850005</p>
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Anggota</p>
                            <div class="flex items-center gap-2">
                                <div class="flex -space-x-2 overflow-hidden">
                                    <div class="size-6 rounded-full bg-gray-200 ring-2 ring-white"></div>
                                    <div class="size-6 rounded-full bg-gray-300 ring-2 ring-white"></div>
                                    <div class="size-6 rounded-full bg-gray-400 ring-2 ring-white"></div>
                                </div>
                                <span class="text-sm font-bold text-foreground">6 Orang</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="manageMember('5')"
                            class="flex-1 h-11 rounded-xl border border-border hover:border-primary text-secondary hover:text-primary font-semibold text-sm transition-all duration-300 cursor-pointer">
                            Lihat Detail
                        </button>
                        <button onclick="manageMember('5')"
                            class="flex-1 h-11 rounded-xl bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/20 transition-all duration-300 cursor-pointer">
                            Kelola
                        </button>
                    </div>
                </div>

                <!-- Card 6 -->
                <div
                    class="group bg-white rounded-3xl p-5 border border-border hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300 flex flex-col gap-5 relative">
                    <div class="flex items-start justify-between">
                        <div class="flex gap-4">
                            <div class="relative">
                                <img src="https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=200&h=200&fit=crop"
                                    alt="Rina Wati"
                                    class="size-16 rounded-2xl object-cover ring-2 ring-border group-hover:ring-primary transition-all duration-300">
                                <span
                                    class="absolute -bottom-1 -right-1 size-5 bg-secondary border-2 border-white rounded-full flex items-center justify-center">
                                    <i data-lucide="minus" class="size-3 text-white"></i>
                                </span>
                            </div>
                            <div class="flex flex-col pt-1">
                                <h3 class="font-bold text-foreground text-lg leading-tight">Rina Wati</h3>
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-purple-50 text-purple-600 text-xs font-bold w-fit mt-1.5">
                                    <i data-lucide="scissors" class="size-3"></i>
                                    Penjahit
                                </span>
                            </div>
                        </div>
                        <button
                            class="p-2 rounded-xl hover:bg-muted text-secondary transition-colors cursor-pointer">
                            <i data-lucide="more-vertical" class="size-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 py-4 border-y border-dashed border-border">
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Nomor NIK</p>
                            <p class="font-semibold text-foreground font-mono text-sm">3201287702880006</p>
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="text-xs font-medium text-secondary uppercase tracking-wide">Anggota</p>
                            <div class="flex items-center gap-2">
                                <div class="flex -space-x-2 overflow-hidden">
                                    <div class="size-6 rounded-full bg-gray-200 ring-2 ring-white"></div>
                                </div>
                                <span class="text-sm font-bold text-foreground">2 Orang</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="manageMember('6')"
                            class="flex-1 h-11 rounded-xl border border-border hover:border-primary text-secondary hover:text-primary font-semibold text-sm transition-all duration-300 cursor-pointer">
                            Lihat Detail
                        </button>
                        <button onclick="manageMember('6')"
                            class="flex-1 h-11 rounded-xl bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/20 transition-all duration-300 cursor-pointer">
                            Kelola
                        </button>
                    </div>
                </div>

            </div>

            <!-- Pagination -->
            <div class="flex items-center justify-center gap-4 mt-12 pb-8">
                <button
                    class="p-[10px] rounded-xl border border-border bg-white hover:ring-1 hover:ring-primary transition-all duration-300 cursor-pointer disabled:opacity-50"
                    aria-label="Previous">
                    <i data-lucide="chevron-left" class="size-6 text-secondary"></i>
                </button>
                <div class="flex items-center gap-2">
                    <button
                        class="size-11 flex items-center justify-center rounded-xl bg-primary/10 border border-primary/20 font-semibold text-primary cursor-pointer">1</button>
                    <button
                        class="size-11 flex items-center justify-center rounded-xl border border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold transition-all duration-300 cursor-pointer">2</button>
                    <button
                        class="size-11 flex items-center justify-center rounded-xl border border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold transition-all duration-300 cursor-pointer">3</button>
                    <span class="text-secondary font-medium">...</span>
                    <button
                        class="size-11 flex items-center justify-center rounded-xl border border-border bg-white hover:bg-primary/10 hover:text-primary font-semibold transition-all duration-300 cursor-pointer">12</button>
                </div>
                <button
                    class="p-[10px] rounded-xl border border-border bg-white hover:ring-1 hover:ring-primary transition-all duration-300 cursor-pointer"
                    aria-label="Next">
                    <i data-lucide="chevron-right" class="size-6 text-secondary"></i>
                </button>
            </div>

        </div>
    </main>
    </div>

    <!-- Add New Modal -->
    <div id="add-modal"
        class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm transition-all">
        <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl scale-100 transition-transform duration-300">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="font-bold text-xl text-foreground">Tambah Kepala Keluarga</h3>
                <button onclick="closeAddModal()"
                    class="size-10 rounded-xl hover:bg-muted flex items-center justify-center transition-colors cursor-pointer">
                    <i data-lucide="x" class="size-5 text-secondary"></i>
                </button>
            </div>

            <div class="p-6 space-y-5">
                <!-- Upload Photo -->
                <div class="flex items-center justify-center">
                    <div class="relative group cursor-pointer">
                        <div
                            class="size-24 rounded-full bg-muted flex items-center justify-center border-2 border-dashed border-secondary/30 hover:border-primary transition-colors">
                            <i data-lucide="camera"
                                class="size-8 text-secondary group-hover:text-primary transition-colors"></i>
                        </div>
                        <div
                            class="absolute bottom-0 right-0 size-8 bg-primary text-white rounded-full flex items-center justify-center border-2 border-white">
                            <i data-lucide="plus" class="size-4"></i>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <!-- Floating Label Inputs -->
                    <div
                        class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all duration-300 bg-white">
                        <i data-lucide="user"
                            class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input id="inputNama" type="text"
                            class="peer absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground"
                            placeholder=" ">
                        <label for="inputNama"
                            class="absolute left-12 text-secondary text-xs font-medium transition-all duration-300 top-2">Nama
                            Lengkap</label>
                    </div>

                    <div
                        class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all duration-300 bg-white">
                        <i data-lucide="credit-card"
                            class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input id="inputNik" type="text"
                            class="peer absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground"
                            placeholder=" ">
                        <label for="inputNik"
                            class="absolute left-12 text-secondary text-xs font-medium transition-all duration-300 top-2">Nomor
                            NIK</label>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all duration-300 bg-white">
                            <i data-lucide="briefcase"
                                class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputPekerjaan" type="text"
                                class="peer absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground"
                                placeholder=" ">
                            <label for="inputPekerjaan"
                                class="absolute left-12 text-secondary text-xs font-medium transition-all duration-300 top-2">Pekerjaan</label>
                        </div>
                        <div
                            class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all duration-300 bg-white">
                            <i data-lucide="users"
                                class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                            <input id="inputAnggota" type="number"
                                class="peer absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground"
                                placeholder=" ">
                            <label for="inputAnggota"
                                class="absolute left-12 text-secondary text-xs font-medium transition-all duration-300 top-2">Jml
                                Anggota</label>
                        </div>
                    </div>

                    <div
                        class="relative h-[60px] rounded-2xl ring-1 ring-border focus-within:ring-2 focus-within:ring-primary transition-all duration-300 bg-white">
                        <i data-lucide="map-pin"
                            class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                        <input id="inputAlamat" type="text"
                            class="peer absolute inset-0 w-full h-full bg-transparent font-medium focus:outline-none pl-12 pt-5 pb-1 text-foreground"
                            placeholder=" ">
                        <label for="inputAlamat"
                            class="absolute left-12 text-secondary text-xs font-medium transition-all duration-300 top-2">Alamat
                            Lengkap</label>
                    </div>
                </div>
            </div>

            <div class="p-6 border-t border-border flex gap-3">
                <button onclick="closeAddModal()"
                    class="flex-1 py-3.5 rounded-full border border-border font-semibold text-secondary hover:bg-muted transition-colors cursor-pointer">Batal</button>
                <button onclick="saveNewMember()"
                    class="flex-1 py-3.5 rounded-full bg-primary text-white font-bold hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all cursor-pointer">Simpan
                    Data</button>
            </div>
        </div>
    </div>

    <!-- Search Modal -->
    <div id="search-modal" class="fixed inset-0 bg-black/60 z-[100] hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl">
            <div class="p-4 border-b border-border">
                <div class="flex items-center gap-3 bg-muted rounded-xl px-4">
                    <i data-lucide="search" class="size-5 text-secondary"></i>
                    <input type="text" id="modal-search-input" placeholder="Cari warga, NIK, atau alamat..."
                        class="flex-1 py-3.5 bg-transparent outline-none text-foreground placeholder:text-secondary font-medium">
                    <button onclick="closeSearchModal()"
                        class="size-8 flex items-center justify-center rounded-lg bg-white shadow-sm text-secondary hover:text-foreground">
                        <span class="text-xs font-bold">ESC</span>
                    </button>
                </div>
            </div>
            <div class="p-2 overflow-y-auto max-h-[60vh]">
                <div class="p-2 text-sm text-secondary font-medium">Pencarian Terakhir</div>
                <a href="#"
                    class="flex items-center gap-4 p-3 rounded-xl hover:bg-muted transition-all group cursor-pointer">
                    <div class="size-10 bg-primary/10 rounded-full flex items-center justify-center text-primary">
                        <i data-lucide="history" class="size-5"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-medium text-foreground group-hover:text-primary transition-colors">Budi Santoso
                        </p>
                        <p class="text-xs text-secondary">RW 03 / RT 01</p>
                    </div>
                    <i data-lucide="arrow-right"
                        class="size-4 text-secondary group-hover:text-primary transition-colors"></i>
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            // Accordion toggle
            document.querySelectorAll('[data-accordion]').forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.dataset.accordion;
                    const target = document.getElementById(targetId);
                    const chevron = this.querySelector('[data-lucide="chevron-down"]');

                    if (target.classList.contains('hidden')) {
                        target.classList.remove('hidden');
                        chevron.style.transform = 'rotate(180deg)';
                        this.classList.add('bg-muted');
                    } else {
                        target.classList.add('hidden');
                        chevron.style.transform = 'rotate(0deg)';
                        this.classList.remove('bg-muted');
                    }
                });
            });
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
                document.body.style.overflow = '';
            }
        }

        function openAddModal() {
            const modal = document.getElementById('add-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('div').classList.remove('scale-95');
                modal.querySelector('div').classList.add('scale-100');
            }, 10);
        }

        function closeAddModal() {
            const modal = document.getElementById('add-modal');
            modal.querySelector('div').classList.remove('scale-100');
            modal.querySelector('div').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 200);
        }

        function saveNewMember() {
            // Demo functionality
            closeAddModal();

            // Show simple toast/notification logic here if needed
            const btn = document.querySelector('button[onclick="openAddModal()"]');
            const originalContent = btn.innerHTML;
            btn.innerHTML = '<i data-lucide="check" class="size-5"></i><span>Tersimpan!</span>';
            btn.classList.replace('bg-primary', 'bg-success');
            lucide.createIcons();

            setTimeout(() => {
                btn.innerHTML = originalContent;
                btn.classList.replace('bg-success', 'bg-primary');
                lucide.createIcons();
            }, 2000);
        }

        function manageMember(id) {
            console.log('Managing member ID:', id);
            // Implementation for manage/detail view
        }

        // Search Modal Logic
        function openSearchModal() {
            const modal = document.getElementById('search-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('modal-search-input').focus();
        }

        function closeSearchModal() {
            const modal = document.getElementById('search-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('search-modal').addEventListener('click', function(e) {
            if (e.target === this) closeSearchModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeSearchModal();
            }
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                openSearchModal();
            }
        });
    </script>
</body>

</html>