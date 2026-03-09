<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Sanjai Zivanes</title>
    <meta name="description" content="Halaman login sistem informasi Sanjai Zivanes">
    <link href="https://fonts.googleapis.com/css2?family=Lexend+Deca:wght@100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <style type="text/tailwindcss">
        :root {
            --primary: #165DFF;
            --primary-hover: #0E4BD9;
            --foreground: #080C1A;
            --secondary: #6A7686;
            --muted: #EFF2F7;
            --border: #F3F4F3;
            --error: #ED6B60;
            --font-sans: 'Lexend Deca', sans-serif;
        }
        @theme inline {
            --color-primary: var(--primary);
            --color-primary-hover: var(--primary-hover);
            --color-foreground: var(--foreground);
            --color-secondary: var(--secondary);
            --color-muted: var(--muted);
            --color-border: var(--border);
            --color-error: var(--error);
            --font-sans: var(--font-sans);
            --radius-card: 24px;
            --radius-button: 50px;
        }
    </style>
</head>

<body class="font-sans bg-muted min-h-screen flex items-center justify-center text-foreground">

    <div class="w-full max-w-md px-4">
        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-xl p-8 border border-border">

            <!-- Logo -->
            <div class="flex flex-col items-center mb-8">
                <div class="w-14 h-14 bg-primary rounded-2xl flex items-center justify-center mb-3">
                    <i data-lucide="building-2" class="w-7 h-7 text-white"></i>
                </div>
                <h1 class="text-2xl font-bold text-primary">Sanjai Zivanez</h1>
                <p class="text-sm text-secondary mt-1">Sistem Informasi Sanjai Zivanez</p>
            </div>

            <!-- Login Form -->
            <form action="proses_login.php" method="POST" class="space-y-5">

                <!-- Username -->
                <div class="relative">
                    <i data-lucide="user"
                        class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                    <input type="text" name="username" required
                        class="w-full h-12 pl-12 pr-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition"
                        placeholder="Username">
                </div>

                <!-- Password -->
                <div class="relative">
                    <i data-lucide="lock"
                        class="absolute left-4 top-1/2 -translate-y-1/2 size-5 text-secondary"></i>
                    <input type="password" name="password" required
                        class="w-full h-12 pl-12 pr-4 rounded-xl border border-border focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition"
                        placeholder="Password">
                </div>

                <!-- Button -->
                <button type="submit"
                    class="w-full h-12 rounded-full bg-primary hover:bg-primary-hover text-white font-bold shadow-lg shadow-primary/20 transition">
                    Masuk
                </button>
            </form>

            <!-- Footer -->
            <p class="text-center text-xs text-secondary mt-6">
                © <?= date('Y') ?> Sanjai Zivanes. All rights reserved.
            </p>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>
