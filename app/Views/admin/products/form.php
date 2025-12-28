<?= $this->extend('components/templates/AdminLayout') ?>

<?php
// Deteksi Mode
$isEdit = isset($product) && $product !== null;
$title  = $isEdit ? 'Edit Produk: ' . esc($product->name) : 'Tambah Produk Baru';
$actionUrl = $isEdit ? base_url('admin/products/' . $product->id) : base_url('admin/products');
?>

<?= $this->section('page-title') ?>
    <?= $title ?>
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="max-w-5xl mx-auto">
    
    <div class="mb-6">
        <a href="<?= base_url('admin/products') ?>" class="text-sm text-gray-500 hover:text-blue-600 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
            Kembali ke Katalog
        </a>
    </div>

    <?= form_open_multipart($actionUrl) ?>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Informasi Dasar</h3>
                
                <?= view('components/molecules/FormGroup', [
                    'name'     => 'name',
                    'label'    => 'Nama Produk',
                    'value'    => old('name', $isEdit ? $product->name : ''),
                    'required' => true,
                    'placeholder' => 'Contoh: Mechanical Keyboard Keychron K2',
                    'error'    => session('errors.name')
                ]) ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?= view('components/molecules/FormGroup', [
                        'name'     => 'sku',
                        'label'    => 'SKU (Stock Keeping Unit)',
                        'value'    => old('sku', $isEdit ? $product->sku : ''),
                        'placeholder' => 'KEY-K2-RGB',
                        'error'    => session('errors.sku')
                    ]) ?>

                    <?= view('components/molecules/FormGroup', [
                        'name'     => 'price',
                        'label'    => 'Harga Dasar (IDR)',
                        'type'     => 'number',
                        'value'    => old('price', $isEdit ? $product->price : ''),
                        'required' => true,
                        'placeholder' => '0',
                        'error'    => session('errors.price')
                    ]) ?>
                </div>

                <div class="mb-4">
                    <?= view('components/atoms/Label', ['for' => 'description', 'text' => 'Deskripsi Produk']) ?>
                    <div class="mt-1">
                        <textarea id="description" name="description" rows="5" 
                                  class="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                  placeholder="Jelaskan spesifikasi dan keunggulan produk..."><?= old('description', $isEdit ? $product->description : '') ?></textarea>
                    </div>
                    <?php if (session('errors.description')): ?>
                        <p class="mt-2 text-sm text-red-600"><?= session('errors.description') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="lg:col-span-1 space-y-6">
            
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Pengaturan</h3>
                
                <div class="flex items-start mb-6">
                    <div class="flex items-center h-5">
                        <input type="hidden" name="active" value="0">
                        <input id="active" name="active" type="checkbox" value="1" 
                               <?= (old('active', $isEdit ? $product->active : true)) ? 'checked' : '' ?>
                               class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="active" class="font-medium text-gray-700">Publikasikan</label>
                        <p class="text-gray-500 text-xs">Produk akan tampil di halaman depan.</p>
                    </div>
                </div>

                <div class="mb-4">
                    <?= view('components/atoms/Label', ['for' => 'category_id', 'text' => 'Kategori', 'required' => true]) ?>
                    <select id="category_id" name="category_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md shadow-sm">
                        <option value="">Pilih Kategori...</option>
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat->id ?>" <?= (old('category_id', $isEdit ? $product->category_id : '') == $cat->id) ? 'selected' : '' ?>>
                                    <?= esc($cat->name) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (session('errors.category_id')): ?>
                        <p class="mt-2 text-sm text-red-600"><?= session('errors.category_id') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Gambar Thumbnail</h3>
                
                <?php if ($isEdit && $product->thumbnail): ?>
                    <div class="mb-4">
                        <p class="text-xs text-gray-500 mb-2">Gambar Saat Ini:</p>
                        <img src="<?= base_url('uploads/products/' . $product->thumbnail) ?>" alt="Thumbnail" class="w-full h-48 object-cover rounded-md border border-gray-200">
                    </div>
                <?php endif; ?>

                <div class="mt-2">
                    <label class="block text-sm font-medium text-gray-700">Upload Gambar Baru</label>
                    <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:bg-gray-50 transition-colors">
                        <div class="space-y-1 text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <div class="flex text-sm text-gray-600 justify-center">
                                <label for="thumbnail" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                                    <span>Pilih File</span>
                                    <input id="thumbnail" name="thumbnail" type="file" class="sr-only" accept="image/png, image/jpeg, image/jpg, image/webp">
                                </label>
                            </div>
                            <p class="text-xs text-gray-500">PNG, JPG, WEBP up to 2MB</p>
                        </div>
                    </div>
                    <?php if (session('errors.thumbnail')): ?>
                        <p class="mt-2 text-sm text-red-600"><?= session('errors.thumbnail') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pt-4">
                <?= view('components/atoms/Button', [
                    'type'    => 'submit',
                    'label'   => $isEdit ? 'Update Produk' : 'Simpan Produk',
                    'variant' => 'primary',
                    'class'   => 'shadow-lg'
                ]) ?>
            </div>

        </div>
    </div>
    
    <?= form_close() ?>

</div>

<?= $this->endSection() ?>
