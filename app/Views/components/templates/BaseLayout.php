<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'DevDaily') ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-50 text-slate-800 flex flex-col min-h-screen">

    <nav class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-8">
                    <a href="<?= base_url() ?>" class="flex items-center gap-2">
                        <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold">D</div>
                        <span class="font-bold text-xl tracking-tight text-gray-900">DevDaily</span>
                    </a>
                    
                    <div class="hidden sm:flex sm:space-x-8">
                        <a href="<?= base_url() ?>" class="border-blue-500 text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">Home</a>
                        <a href="#" class="border-transparent text-gray-500 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">Kategori</a>
                    </div>
                </div>

                <div class="flex items-center">
                    <?php if (function_exists('auth') && auth()->user()): ?>
                        <a href="<?= url_to('admin.dashboard') ?>" class="text-sm font-medium text-blue-600">Dashboard</a>
                    <?php else: ?>
                        <a href="<?= url_to('auth.login') ?>" class="text-sm font-medium text-gray-500 hover:text-gray-900 mr-4">Masuk</a>
                        <a href="<?= url_to('auth.register') ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">Daftar</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <main class="flex-grow">
        <?= $this->renderSection('content') ?>
    </main>

    <footer class="bg-white border-t border-gray-200 mt-12 py-8">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <p class="text-sm text-gray-400">&copy; <?= date('Y') ?> DevDaily. All rights reserved.</p>
        </div>
    </footer>

</body>
</html>
