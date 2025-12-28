<?php
// Mendapatkan segment URL ke-2 (misal: admin/products -> 'products')
$uri = service('uri');
// Jika di /admin, anggap segment 2 adalah 'dashboard'
$currentSegment = $uri->getTotalSegments() >= 2 ? $uri->getSegment(2) : 'dashboard';

// Definisi Menu Array (agar HTML lebih bersih)
$menus = [
    [
        'title' => 'Dashboard',
        'url'   => 'admin/dashboard',
        'key'   => 'dashboard',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />'
    ],
    [
        'title' => 'Produk',
        'url'   => 'admin/products',
        'key'   => 'products',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />'
    ],
    [
        'title' => 'Kategori',
        'url'   => 'admin/categories',
        'key'   => 'categories',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />'
    ],
    [
        'title' => 'Manajemen User',
        'url'   => 'admin/users',
        'key'   => 'users',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />'
    ],
];
?>

<aside class="hidden md:flex flex-col w-64 bg-gray-900 text-white min-h-screen transition-all duration-300 z-20">
    
    <div class="h-16 flex items-center justify-center border-b border-gray-800 bg-gray-900">
        <span class="text-xl font-bold tracking-wider text-blue-400">DEVDAILY</span>
    </div>

    <nav class="flex-grow py-6 px-4 space-y-2 overflow-y-auto">
        <?php foreach ($menus as $menu): ?>
            <?php 
                $isActive = $currentSegment === $menu['key']; 
                $activeClass = $isActive 
                    ? 'bg-blue-600 text-white shadow-lg' 
                    : 'text-gray-400 hover:bg-gray-800 hover:text-white';
            ?>
            <a href="<?= base_url($menu['url']) ?>" 
               class="flex items-center px-4 py-3 rounded-lg transition-colors duration-200 <?= $activeClass ?>">
                
                <svg class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <?= $menu['icon'] ?>
                </svg>
                
                <span class="font-medium text-sm">
                    <?= $menu['title'] ?>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="border-t border-gray-800 p-4">
        <a href="<?= base_url('admin/logout') ?>" class="flex items-center text-gray-400 hover:text-red-400 transition-colors">
            <svg class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
            <span class="text-sm font-medium">Logout</span>
        </a>
    </div>

</aside>
