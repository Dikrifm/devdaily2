<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Manajer Link Afiliasi
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="space-y-6">

    <div class="sm:flex sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Semua Link Produk</h2>
            <p class="mt-1 text-sm text-gray-500">Monitor dan kelola seluruh tautan afiliasi yang tersebar di katalog.</p>
        </div>
        </div>

    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
        <form action="" method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2 relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                </div>
                <input type="text" name="search" value="<?= esc($search ?? '') ?>" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="Cari berdasarkan nama produk...">
            </div>

            <div class="md:col-span-1">
                <select name="marketplace" class="block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="">Semua Marketplace</option>
                    <option value="shopee" <?= ($marketplaceFilter ?? '') == 'shopee' ? 'selected' : '' ?>>Shopee</option>
                    <option value="tokopedia" <?= ($marketplaceFilter ?? '') == 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
                    <option value="lazada" <?= ($marketplaceFilter ?? '') == 'lazada' ? 'selected' : '' ?>>Lazada</option>
                </select>
            </div>

            <div class="md:col-span-1">
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                    Filter Data
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Marketplace</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Harga Tayang</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URL</th>
                        <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($links)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                <svg class="mx-auto h-12 w-12 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                                Belum ada link afiliasi yang tersimpan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($links as $link): ?>
                            <tr class="hover:bg-gray-50">
                                
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-gray-100 rounded-md overflow-hidden border border-gray-200">
                                            <?php if (!empty($link->product_thumbnail)): ?>
                                                <img class="h-10 w-10 object-cover" src="<?= base_url('uploads/products/' . $link->product_thumbnail) ?>" alt="">
                                            <?php else: ?>
                                                <div class="flex items-center justify-center h-full text-xs text-gray-400">IMG</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 truncate max-w-xs" title="<?= esc($link->product_name) ?>">
                                                <?= esc($link->product_name) ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                ID: <?= $link->product_id ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 capitalize">
                                        <?= esc($link->marketplace) ?>
                                    </span>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    Rp <?= number_format($link->price_value, 0, ',', '.') ?>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div class="flex items-center space-x-2">
                                        <a href="<?= esc($link->product_url) ?>" target="_blank" class="text-blue-600 hover:text-blue-900 truncate max-w-[150px]" title="<?= esc($link->product_url) ?>">
                                            <?= esc($link->product_url) ?>
                                        </a>
                                        <button type="button" onclick="navigator.clipboard.writeText('<?= esc($link->product_url) ?>')" class="text-gray-400 hover:text-gray-600" title="Copy URL">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" /></svg>
                                        </button>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end space-x-3">
                                        
                                        <a href="<?= base_url('admin/links/' . $link->id . '/validate') ?>" class="text-green-600 hover:text-green-900" title="Cek Validitas Link">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        </a>

                                        <a href="<?= base_url('admin/products/' . $link->product_id . '/prices') ?>" class="text-blue-600 hover:text-blue-900" title="Edit di Produk">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </a>

                                        <form action="<?= base_url('admin/links/' . $link->id . '/delete') ?>" method="post" onsubmit="return confirm('Hapus link ini?');">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="text-red-600 hover:text-red-900 bg-transparent border-none cursor-pointer p-0" title="Hapus">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (isset($pager)): ?>
            <div class="px-4 py-3 border-t border-gray-200 sm:px-6">
                <?= $pager->links() ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?= $this->endSection() ?>
