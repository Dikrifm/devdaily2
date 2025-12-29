<?= $this->extend('components/templates/BaseLayout') ?>

<?= $this->section('title') ?>
    Hasil Pencarian: <?= esc($searchQuery ?? '') ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<nav class="bg-white border-b border-gray-200 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="<?= base_url() ?>" class="flex items-center text-gray-500 hover:text-blue-600 transition-colors">
                    <span class="text-xl font-bold text-blue-600 tracking-tighter">DEVDAILY</span>
                </a>
            </div>
            <div class="flex-1 flex items-center justify-center px-2 lg:ml-6 lg:justify-end">
                <div class="max-w-lg w-full lg:max-w-xs">
                    <form action="<?= base_url('search') ?>" method="get">
                        <label for="search" class="sr-only">Search</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            </div>
                            <input id="search" name="q" value="<?= esc($searchQuery ?? '') ?>" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="Cari produk..." type="search">
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="bg-gray-50 min-h-screen">
    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        
        <div class="flex items-baseline justify-between border-b border-gray-200 pb-6 mb-6">
            <h1 class="text-3xl font-extrabold tracking-tight text-gray-900">
                Hasil Pencarian
                <?php if (!empty($searchQuery)): ?>
                    <span class="text-base font-normal text-gray-500 ml-2">"<?= esc($searchQuery) ?>"</span>
                <?php endif; ?>
            </h1>
            
            <div class="flex items-center">
                <span class="text-sm text-gray-500">
                    <?= isset($pager) ? number_format($pager->getTotal()) : count($products) ?> produk ditemukan
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-x-8 gap-y-10">
            
            <form class="hidden lg:block">
                <h3 class="sr-only">Categories</h3>
                <input type="hidden" name="q" value="<?= esc($searchQuery ?? '') ?>">
                
                <div class="border-b border-gray-200 py-6">
                    <h3 class="-my-3 flow-root">
                        <span class="font-medium text-gray-900">Kategori</span>
                    </h3>
                    <div class="pt-6">
                        <div class="space-y-4">
                            <div class="flex items-center">
                                <input id="cat-1" name="category" value="1" type="checkbox" class="h-4 w-4 border-gray-300 rounded text-blue-600 focus:ring-blue-500">
                                <label for="cat-1" class="ml-3 text-sm text-gray-600">Elektronik</label>
                            </div>
                            <div class="flex items-center">
                                <input id="cat-2" name="category" value="2" type="checkbox" class="h-4 w-4 border-gray-300 rounded text-blue-600 focus:ring-blue-500">
                                <label for="cat-2" class="ml-3 text-sm text-gray-600">Aksesoris</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="mt-4 w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none">
                    Terapkan Filter
                </button>
            </form>

            <div class="lg:col-span-3">
                <?php if (empty($products)): ?>
                    <div class="text-center py-20 bg-white rounded-lg border border-gray-200 border-dashed">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">Tidak ada hasil</h3>
                        <p class="mt-1 text-sm text-gray-500">Coba kata kunci lain atau periksa ejaan Anda.</p>
                        <a href="<?= base_url() ?>" class="mt-6 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200">
                            Kembali ke Beranda
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-y-10 gap-x-6 sm:grid-cols-2 lg:grid-cols-3 xl:gap-x-8">
                        <?php foreach ($products as $product): ?>
                            <div class="group relative bg-white border border-gray-200 rounded-lg flex flex-col overflow-hidden hover:shadow-lg transition-shadow">
                                <div class="aspect-w-3 aspect-h-4 bg-gray-200 group-hover:opacity-75 sm:aspect-none sm:h-56">
                                    <?php if ($product->thumbnail): ?>
                                        <img src="<?= base_url('uploads/products/' . $product->thumbnail) ?>" alt="<?= esc($product->name) ?>" class="w-full h-full object-center object-cover sm:w-full sm:h-full">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center bg-gray-100 text-gray-400">
                                            <svg class="h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex-1 p-4 flex flex-col justify-between">
                                    <div>
                                        <h3 class="text-sm font-medium text-gray-900">
                                            <a href="<?= base_url('p/' . $product->slug) ?>">
                                                <span aria-hidden="true" class="absolute inset-0"></span>
                                                <?= esc($product->name) ?>
                                            </a>
                                        </h3>
                                        <p class="mt-1 text-sm text-gray-500"><?= esc($product->category_name ?? 'Umum') ?></p>
                                    </div>
                                    <p class="text-lg font-bold text-gray-900 mt-2">Rp <?= number_format($product->price, 0, ',', '.') ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (isset($pager)): ?>
                        <div class="mt-8">
                            <?= $pager->links() ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
