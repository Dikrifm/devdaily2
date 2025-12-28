<?= $this->extend('components/templates/BaseLayout') ?>

<?= $this->section('title') ?>
    <?= esc($product->name) ?> - Spesifikasi & Harga Termurah
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<nav class="bg-white border-b border-gray-200 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="<?= base_url() ?>" class="flex items-center text-gray-500 hover:text-blue-600 transition-colors">
                    <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    <span class="font-medium">Kembali ke Beranda</span>
                </a>
            </div>
            <div class="flex items-center">
                <span class="text-xl font-bold text-blue-600 tracking-tighter">DEVDAILY</span>
            </div>
        </div>
    </div>
</nav>

<div class="bg-gray-50 min-h-screen py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="lg:grid lg:grid-cols-2 lg:gap-x-12 lg:items-start">
            
            <div class="flex flex-col-reverse">
                <div class="w-full aspect-w-1 aspect-h-1 bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
                    <?php if ($product->thumbnail): ?>
                        <img src="<?= base_url('uploads/products/' . $product->thumbnail) ?>" alt="<?= esc($product->name) ?>" class="w-full h-full object-center object-cover hover:scale-105 transition-transform duration-500">
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center bg-gray-100 text-gray-400">
                            <svg class="h-24 w-24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-10 px-2 sm:px-0 sm:mt-16 lg:mt-0">
                
                <div class="mb-4">
                    <span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                        <?= esc($product->category_name ?? 'Gadget') ?>
                    </span>
                </div>

                <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">
                    <?= esc($product->name) ?>
                </h1>

                <div class="mt-6">
                    <h3 class="sr-only">Description</h3>
                    <div class="text-base text-gray-700 space-y-4 prose prose-blue">
                        <?= nl2br(esc($product->description)) ?>
                    </div>
                </div>
                
                <hr class="my-8 border-gray-200">

                <div>
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Bandingkan Harga & Beli</h3>
                    
                    <?php if (empty($prices)): ?>
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                            <p class="text-sm text-yellow-700">
                                Maaf, saat ini stok atau link pembelian belum tersedia untuk produk ini.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($prices as $price): ?>
                                <?php 
                                    // Simple logic untuk warna tombol berdasarkan marketplace
                                    $mp = strtolower($price->marketplace);
                                    $btnClass = 'bg-gray-800 hover:bg-gray-900'; // Default
                                    
                                    if (strpos($mp, 'shopee') !== false) $btnClass = 'bg-orange-500 hover:bg-orange-600';
                                    if (strpos($mp, 'tokopedia') !== false) $btnClass = 'bg-green-500 hover:bg-green-600';
                                    if (strpos($mp, 'lazada') !== false) $btnClass = 'bg-blue-500 hover:bg-blue-600';
                                    if (strpos($mp, 'tiktok') !== false) $btnClass = 'bg-black hover:bg-gray-800';
                                ?>

                                <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm flex items-center justify-between transition-shadow hover:shadow-md">
                                    <div class="flex items-center">
                                        <div class="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-600 uppercase mr-4">
                                            <?= substr($mp, 0, 2) ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-900 capitalize"><?= esc($price->marketplace) ?></p>
                                            <p class="text-xs text-gray-500">Diupdate: <?= date('d M Y', strtotime($price->updated_at ?? date('Y-m-d'))) ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="text-right">
                                        <p class="text-lg font-bold text-gray-900 mb-1">
                                            Rp <?= number_format($price->price_value, 0, ',', '.') ?>
                                        </p>
                                        <a href="<?= esc($price->product_url) ?>" target="_blank" rel="nofollow noopener" 
                                           class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white shadow-sm <?= $btnClass ?> focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 w-full md:w-auto">
                                            Beli Sekarang
                                            <svg class="ml-2 -mr-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-8 border-t border-gray-200 pt-6">
                    <p class="text-xs text-gray-400 text-center">
                        *Harga dapat berubah sewaktu-waktu tergantung kebijakan penjual di masing-masing marketplace. DevDaily mendapatkan komisi dari link afiliasi yang Anda klik.
                    </p>
                </div>

            </div>
        </div>

    </div>
</div>

<?= $this->endSection() ?>
