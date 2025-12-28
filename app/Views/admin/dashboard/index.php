<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Dashboard Overview
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

    <div class="mb-8 bg-white rounded-lg p-6 shadow-sm border border-gray-100 flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">
                Halo, <?= esc(session()->get('admin_name') ?? 'Admin') ?>! 👋
            </h2>
            <p class="text-gray-500 mt-1">
                Berikut adalah ringkasan aktivitas toko Anda hari ini.
            </p>
        </div>
        <div class="hidden sm:block">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                <span class="w-2 h-2 mr-2 bg-green-500 rounded-full"></span>
                System Online
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        
        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-blue-50 text-blue-600 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <span class="text-xs font-medium text-green-600 bg-green-50 px-2 py-1 rounded-full">+2.5%</span>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Total Produk</h3>
            <p class="text-2xl font-bold text-gray-800 mt-1">
                <?= number_format($stats['total_products'] ?? 0) ?>
            </p>
        </div>

        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-indigo-50 text-indigo-600 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <span class="text-xs font-medium text-gray-500 bg-gray-50 px-2 py-1 rounded-full">Active</span>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Total Admin/Staff</h3>
            <p class="text-2xl font-bold text-gray-800 mt-1">
                <?= number_format($stats['total_users'] ?? 0) ?>
            </p>
        </div>

        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-purple-50 text-purple-600 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                </div>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Link Tergenerate</h3>
            <p class="text-2xl font-bold text-gray-800 mt-1">
                <?= number_format($stats['total_links'] ?? 0) ?>
            </p>
        </div>

        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-orange-50 text-orange-600 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 01-2 2v4a2 2 0 012 2h14a2 2 0 012-2v-4a2 2 0 01-2-2m-2-4h.01M17 16h.01" />
                    </svg>
                </div>
                <span class="text-xs font-medium text-green-600 bg-green-50 px-2 py-1 rounded-full">Good</span>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Kondisi Server</h3>
            <p class="text-2xl font-bold text-gray-800 mt-1">98%</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-800">Aktivitas Terakhir</h3>
        </div>
        <div class="p-6 text-center text-gray-500 py-12">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 mb-4">
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <p>Belum ada aktivitas yang tercatat hari ini.</p>
        </div>
    </div>

<?= $this->endSection() ?>
