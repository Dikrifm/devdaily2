<?= $this->extend('components/templates/AdminLayout') ?>

<?php
// Deteksi Mode: Create atau Edit berdasarkan variabel $marketplace
$isEdit = isset($marketplace) && $marketplace !== null;
$title  = $isEdit ? 'Edit Marketplace: ' . esc($marketplace->name) : 'Tambah Marketplace Baru';
// Asumsi routes menggunakan resource controller (plural: marketplaces)
$actionUrl = $isEdit ? base_url('admin/marketplaces/' . $marketplace->id) : base_url('admin/marketplaces');
?>

<?= $this->section('page-title') ?>
    <?= $title ?>
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="max-w-3xl mx-auto">
    
    <div class="mb-6">
        <a href="<?= base_url('admin/marketplaces') ?>" class="text-sm text-gray-500 hover:text-blue-600 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
            Kembali ke Daftar Marketplace
        </a>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-medium leading-6 text-gray-900"><?= $title ?></h3>
            <p class="mt-1 text-sm text-gray-500">Konfigurasi platform e-commerce untuk afiliasi produk.</p>
        </div>

        <div class="p-6">
            <?php if (session()->getFlashdata('error')) : ?>
                <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4">
                    <p class="text-sm text-red-700"><?= session()->getFlashdata('error') ?></p>
                </div>
            <?php endif; ?>

            <?= form_open($actionUrl) ?>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <div class="md:col-span-2">
                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'name',
                            'label'    => 'Nama Platform',
                            'value'    => old('name', $isEdit ? $marketplace->name : ''),
                            'required' => true,
                            'placeholder' => 'Contoh: Shopee, Tokopedia',
                            'error'    => session('errors.name')
                        ]) ?>
                    </div>

                    <?= view('components/molecules/FormGroup', [
                        'name'     => 'slug',
                        'label'    => 'Slug (Opsional)',
                        'value'    => old('slug', $isEdit ? $marketplace->slug : ''),
                        'placeholder' => 'shopee',
                        'error'    => session('errors.slug')
                    ]) ?>

                    <div>
                        <?= view('components/atoms/Label', ['for' => 'color', 'text' => 'Warna Brand', 'required' => true]) ?>
                        <div class="flex items-center space-x-3 mt-1">
                            <input type="color" 
                                   id="color" 
                                   name="color" 
                                   value="<?= old('color', $isEdit ? ($marketplace->color ?? '#000000') : '#3b82f6') ?>"
                                   class="h-10 w-20 p-1 border border-gray-300 rounded-md cursor-pointer shadow-sm">
                            <span class="text-sm text-gray-500">Pilih warna dominan untuk tombol beli.</span>
                        </div>
                        <?php if (session('errors.color')): ?>
                            <p class="mt-2 text-sm text-red-600"><?= session('errors.color') ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="border-t border-gray-100 my-6 pt-6">
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input type="hidden" name="is_active" value="0">
                            <input id="is_active" name="is_active" type="checkbox" value="1" 
                                <?= (old('is_active', $isEdit ? $marketplace->is_active : true)) ? 'checked' : '' ?>
                                class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="is_active" class="font-medium text-gray-700">Status Aktif</label>
                            <p class="text-gray-500">Jika nonaktif, opsi marketplace ini tidak akan muncul di halaman input produk.</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <a href="<?= base_url('admin/marketplaces') ?>" class="px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                        Batal
                    </a>
                    
                    <?= view('components/atoms/Button', [
                        'type'    => 'submit',
                        'label'   => $isEdit ? 'Simpan Perubahan' : 'Buat Platform',
                        'variant' => 'primary',
                        'width'   => 'w-auto'
                    ]) ?>
                </div>

            <?= form_close() ?>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
