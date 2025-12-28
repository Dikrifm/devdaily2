<?= $this->extend('components/templates/AdminLayout') ?>

<?php
// Deteksi Mode Edit berdasarkan keberadaan variabel $categoryTarget
$isEdit = isset($categoryTarget) && $categoryTarget !== null;
$formTitle = $isEdit ? 'Edit Kategori' : 'Tambah Kategori';
$formAction = $isEdit ? base_url('admin/categories/' . $categoryTarget->id) : base_url('admin/categories');
?>

<?= $this->section('page-title') ?>
    Manajemen Kategori
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    
    <div class="lg:col-span-1">
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6 sticky top-24">
            <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4"><?= $formTitle ?></h3>
            
            <?php if (session('error')) : ?>
                <div class="mb-4 bg-red-50 text-red-700 p-3 rounded text-sm border-l-4 border-red-500">
                    <?= session('error') ?>
                </div>
            <?php endif; ?>
            
            <?php if (session('success')) : ?>
                <div class="mb-4 bg-green-50 text-green-700 p-3 rounded text-sm border-l-4 border-green-500">
                    <?= session('success') ?>
                </div>
            <?php endif; ?>

            <?= form_open($formAction) ?>
                
                <?= view('components/molecules/FormGroup', [
                    'name'     => 'name',
                    'label'    => 'Nama Kategori',
                    'value'    => old('name', $isEdit ? $categoryTarget->name : ''),
                    'required' => true,
                    'placeholder' => 'Misal: Elektronik',
                    'error'    => session('errors.name')
                ]) ?>

                <?= view('components/molecules/FormGroup', [
                    'name'     => 'slug',
                    'label'    => 'URL Slug (Opsional)',
                    'value'    => old('slug', $isEdit ? $categoryTarget->slug : ''),
                    'placeholder' => 'elektronik-murah',
                    'error'    => session('errors.slug')
                ]) ?>
                
                <div class="mb-4">
                    <?= view('components/atoms/Label', ['for' => 'description', 'text' => 'Deskripsi Singkat']) ?>
                    <textarea id="description" name="description" rows="3" class="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"><?= old('description', $isEdit ? $categoryTarget->description : '') ?></textarea>
                </div>

                <div class="flex items-center justify-between mt-6">
                    <?php if ($isEdit): ?>
                        <a href="<?= base_url('admin/categories') ?>" class="text-sm text-gray-500 hover:text-gray-700 underline">Batal Edit</a>
                    <?php else: ?>
                        <span></span> <?php endif; ?>

                    <?= view('components/atoms/Button', [
                        'type'    => 'submit',
                        'label'   => $isEdit ? 'Simpan Perubahan' : 'Tambah',
                        'variant' => 'primary',
                        'width'   => 'w-auto'
                    ]) ?>
                </div>

            <?= form_close() ?>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                <h3 class="font-medium text-gray-900">Daftar Kategori</h3>
                <span class="text-xs text-gray-500">Total: <?= count($categories) ?></span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slug</th>
                            <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="3" class="px-6 py-8 text-center text-gray-500">
                                    Belum ada kategori. Silakan tambah baru.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr class="<?= ($isEdit && $categoryTarget->id == $cat->id) ? 'bg-blue-50' : 'hover:bg-gray-50' ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= esc($cat->name) ?></div>
                                        <?php if ($cat->description): ?>
                                            <div class="text-xs text-gray-500 truncate max-w-xs"><?= esc($cat->description) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <code class="bg-gray-100 px-2 py-1 rounded text-xs"><?= esc($cat->slug) ?></code>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-3">
                                            <a href="<?= base_url('admin/categories/' . $cat->id . '/edit') ?>" class="text-blue-600 hover:text-blue-900">
                                                Edit
                                            </a>
                                            <form action="<?= base_url('admin/categories/' . $cat->id . '/delete') ?>" method="post" onsubmit="return confirm('Hapus kategori ini? Produk di dalamnya akan menjadi Uncategorized.');">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="text-red-600 hover:text-red-900 bg-transparent border-none cursor-pointer">
                                                    Hapus
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
        </div>
    </div>

</div>

<?= $this->endSection() ?>
