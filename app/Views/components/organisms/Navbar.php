<?php
// Ambil data user dari session (diset oleh AdminSessionCheck filter)
// Jika tidak ada, gunakan placeholder
$userName = session()->get('admin_name') ?? 'Admin User';
$userRole = session()->get('admin_role') ?? 'Administrator';
?>

<header class="sticky top-0 z-10 flex flex-shrink-0 h-16 bg-white shadow-sm border-b border-gray-200">
    
    <div class="flex-1 flex px-4 sm:px-6 lg:px-8">
        
        <button type="button" 
                id="sidebar-toggle"
                class="md:hidden px-4 border-r border-gray-200 text-gray-500 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
            <span class="sr-only">Open sidebar</span>
            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7" />
            </svg>
        </button>

        <div class="flex-1 flex items-center justify-between pl-4 md:pl-0">
            <h1 class="text-lg font-semibold text-gray-800">
                <?= $this->renderSection('page-title') ?>
            </h1>
        </div>

        <div class="ml-4 flex items-center md:ml-6">
            
            <button class="bg-white p-1 rounded-full text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <span class="sr-only">View notifications</span>
                <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
            </button>

            <div class="ml-3 relative flex items-center space-x-3">
                <div class="hidden md:block text-right">
                    <p class="text-sm font-medium text-gray-700 leading-none"><?= esc($userName) ?></p>
                    <p class="text-xs text-gray-500 mt-1 capitalize"><?= esc(str_replace('_', ' ', $userRole)) ?></p>
                </div>
                
                <div class="relative">
                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold border border-blue-200">
                        <?= substr($userName, 0, 1) ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</header>
