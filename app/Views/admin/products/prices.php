<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Kelola Harga & Link
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="max-w-6xl mx-auto">
    
    <div class="mb-6">
        <a href="<?= base_url('admin/products') ?>" class="text-sm text-gray-500 hover:text-blue-600 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
            Kembali ke Daftar Produk
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-1">
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6 sticky top-24">
                
                <div class="flex justify-center mb-6">
                    <?php if ($product->thumbnail): ?>
                        <img src="<?= base_url('uploads/products/' . $product->thumbnail) ?>" alt="<?= esc($product->name) ?>" class="h-40 w-40 object-cover rounded-lg border border-gray-100 shadow-sm">
                    <?php else: ?>
                        <div class="h-40 w-40 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400">
                            <svg class="h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                        </div>
                    <?php endif; ?>
                </div>

                <h2 class="text-xl font-bold text-gray-900 text-center mb-2"><?= esc($product->name) ?></h2>
                <p class="text-sm text-gray-500 text-center mb-4">SKU: <?= esc($product->sku) ?></p>
                
                <div class="border-t border-gray-100 pt-4">
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">Harga Dasar</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">Rp <?= number_format($product->price, 0, ',', '.') ?></dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">Kategori</dt>
                            <dd class="mt-1 text-sm text-gray-900"><?= esc($product->category_name ?? '-') ?></dd>
                        </div>
                    </dl>
                </div>

            </div>
        </div>

        <div class="lg:col-span-2 space-y-6">
            
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Tambah Perbandingan Harga</h3>
                
                <?= form_open(base_url('admin/products/' . $product->id . '/prices')) ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        
                        <div>
                            <?= view('components/atoms/Label', ['for' => 'marketplace', 'text' => 'Marketplace', 'required' => true]) ?>
                            <select name="marketplace" id="marketplace" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md shadow-sm">
                                <option value="shopee">Shopee</option>
                                <option value="tokopedia">Tokopedia</option>
                                <option value="lazada">Lazada</option>
                                <option value="tiktok">TikTok Shop</option>
                                <option value="blibli">Blibli</option>
                            </select>
                        </div>

                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'price_value',
                            'label'    => 'Harga Tayang',
                            'type'     => 'number',
                            'required' => true,
                            'placeholder' => 'Contoh: 1500000'
                        ]) ?>
                    </div>

                    <?= view('components/molecules/FormGroup', [
                        'name'     => 'product_url',
                        'label'    => 'Link Produk (Affiliate/Original)',
                        'type'     => 'url',
                        'required' => true,
                        'placeholder' => 'https://shopee.co.id/product/...'
                    ]) ?>

                    <div class="flex justify-end mt-4">
                        <?= view('components/atoms/Button', [
                            'type'    => 'submit',
                            'label'   => 'Tambahkan Link',
                            'variant' => 'primary',
                            'width'   => 'w-auto'
                        ]) ?>
                    </div>
                <?= form_close() ?>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="font-medium text-gray-900">Daftar Link Marketplace</h3>
                </div>
                
                <?php if (empty($prices)): ?>
                    <div class="p-8 text-center text-gray-500">
                        Belum ada link marketplace yang ditambahkan untuk produk ini.
                    </div>
                <?php else: ?>
                    <ul class="divide-y divide-gray-200">
                        <?php foreach ($prices as $item): ?>
                            <li class="p-4 flex items-center justify-between hover:bg-gray-50">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-600 uppercase">
                                        <?= substr($item->marketplace, 0, 2) ?>
                                    </div>
                                    
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 capitalize">
                                            <?= esc($item->marketplace) ?>
                                        </p>
                                        <a href="<?= esc($item->product_url) ?>" target="_blank" class="text-xs text-blue-500 hover:underline truncate max-w-xs block">
                                            <?= esc($item->product_url) ?>
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-6">
                                    <span class="text-sm font-bold text-gray-900">
                                        Rp <?= number_format($item->price_value, 0, ',', '.') ?>
                                    </span>
                                    
                                    <form action="<?= base_url('admin/products/prices/' . $item->id . '/delete') ?>" method="post" onsubmit="return confirm('Hapus link harga ini?');">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-gray-400 hover:text-red-600 transition-colors">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?= $this->endSection() ?>
