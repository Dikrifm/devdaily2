<?= $this->extend('components/templates/BaseLayout') ?>

<?= $this->section('title') ?>
    Rekomendasi Gear & Setup Terbaik
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<nav class="bg-white border-b border-gray-200 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="flex-shrink-0 flex items-center">
                    <span class="text-2xl font-bold text-blue-600 tracking-tighter">DEVDAILY</span>
                </div>
                <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                    <a href="#" class="border-blue-500 text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                        Terbaru
                    </a>
                    <a href="#" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                        Kategori
                    </a>
                    <a href="#" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                        Populer
                    </a>
                </div>
            </div>
            <div class="flex items-center">
                <a href="<?= base_url('admin/login') ?>" class="text-gray-500 hover:text-blue-600 font-medium text-sm">
                    Masuk
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="bg-gray-50 border-b border-gray-200">
    <div class="max-w-7xl mx-auto py-16 px-4 sm:py-24 sm:px-6 lg:px-8 text-center">
        <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl md:text-6xl">
            <span class="block">Upgrade Setup Kamu</span>
            <span class="block text-blue-600">Harga Terbaik Hari Ini</span>
        </h1>
        <p class="mt-4 max-w-2xl mx-auto text-xl text-gray-500">
            Temukan rekomendasi laptop, keyboard, monitor, dan perlengkapan developer lainnya dengan perbandingan harga termurah.
        </p>
        
        <div class="mt-8 max-w-xl mx-auto">
            <form action="" method="get" class="sm:flex">
                <div class="min-w-0 flex-1">
                    <label for="search" class="sr-only">Cari produk</label>
                    <input id="search" type="text" name="q" class="block w-full px-4 py-3 rounded-md border border-gray-300 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm shadow-sm" placeholder="Cari: Mechanical Keyboard, Monitor 4K...">
                </div>
                <div class="mt-3 sm:mt-0 sm:ml-3">
                    <button type="submit" class="block w-full px-4 py-3 rounded-md border border-transparent text-base font-medium text-white bg-blue-600 shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:px-10">
                        Cari
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="bg-white">
    <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
        
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold tracking-tight text-gray-900">Rekomendasi Terbaru</h2>
            <a href="#" class="text-sm font-medium text-blue-600 hover:text-blue-500">Lihat semua &rarr;</a>
        </div>

        <?php if (empty($products)): ?>
            <div class="text-center py-12">
                <p class="text-gray-500">Belum ada produk yang ditampilkan.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-y-10 gap-x-6 sm:grid-cols-2 lg:grid-cols-4 xl:gap-x-8">
                <?php foreach ($products as $product): ?>
                    <div class="group relative flex flex-col h-full border border-gray-100 rounded-lg hover:shadow-lg transition-shadow duration-200">
                        
                        <div class="w-full min-h-60 bg-gray-200 aspect-w-1 aspect-h-1 rounded-t-lg overflow-hidden group-hover:opacity-90 lg:h-60 lg:aspect-none">
                            <?php if ($product->thumbnail): ?>
                                <img src="<?= base_url('uploads/products/' . $product->thumbnail) ?>" alt="<?= esc($product->name) ?>" class="w-full h-full object-center object-cover lg:w-full lg:h-full">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-gray-100 text-gray-400">
                                    <svg class="h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 flex flex-col flex-grow px-4 pb-4">
                            <div>
                                <h3 class="text-sm text-gray-700 font-medium">
                                    <a href="<?= base_url('p/' . $product->slug) ?>">
                                        <span aria-hidden="true" class="absolute inset-0"></span>
                                        <?= esc($product->name) ?>
                                    </a>
                                </h3>
                                <p class="mt-1 text-xs text-gray-500"><?= esc($product->category_name ?? 'Gadget') ?></p>
                            </div>
                            
                            <div class="mt-auto pt-4 flex items-end justify-between">
                                <p class="text-lg font-bold text-gray-900">
                                    Rp <?= number_format($product->price, 0, ',', '.') ?>
                                </p>
                                <span class="text-xs font-medium text-blue-600 bg-blue-50 px-2 py-1 rounded">
                                    Lihat Detail
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<footer class="bg-white border-t border-gray-200">
    <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 md:flex md:items-center md:justify-between lg:px-8">
        <div class="flex justify-center space-x-6 md:order-2">
            <a href="#" class="text-gray-400 hover:text-gray-500">
                <span class="sr-only">Instagram</span>
                <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12.315 2c2.43 0 2.784.013 3.808.06 1.064.049 1.791.218 2.427.465a4.902 4.902 0 011.772 1.153 4.902 4.902 0 011.153 1.772c.247.636.416 1.363.465 2.427.048 1.067.06 1.407.06 4.123v.08c0 2.643-.012 2.987-.06 4.043-.049 1.064-.218 1.791-.465 2.427a4.902 4.902 0 01-1.153 1.772 4.902 4.902 0 01-1.772 1.153c-.636.247-1.363.416-2.427.465-1.067.048-1.407.06-4.123.06h-.08c-2.643 0-2.987-.012-4.043-.06-1.064-.049-1.791-.218-2.427-.465a4.902 4.902 0 01-1.772-1.153 4.902 4.902 0 01-1.153-1.772c-.247-.636-.416-1.363-.465-2.427-.047-1.024-.06-1.379-.06-3.808v-.63c0-2.43.013-2.784.06-3.808.049-1.064.218-1.791.465-2.427a4.902 4.902 0 011.153-1.772 4.902 4.902 0 011.772-1.153c.636-.247 1.363-.416 2.427-.465C9.673 2.013 10.03 2 12.48 2h.165z" clip-rule="evenodd"/></svg>
            </a>
        </div>
        <div class="mt-8 md:mt-0 md:order-1">
            <p class="text-center text-base text-gray-400">
                &copy; <?= date('Y') ?> DevDaily Indonesia. All rights reserved.
            </p>
        </div>
    </div>
</footer>

<?= $this->endSection() ?>
