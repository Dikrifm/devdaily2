<?= $this->extend('components/templates/AdminLayout') ?>

<?php
// Logika deteksi Mode Edit
$isEdit = isset($badgeTarget) && $badgeTarget !== null;
$formTitle = $isEdit ? 'Edit Badge' : 'Buat Badge Baru';
$formAction = $isEdit ? base_url('admin/badges/' . $badgeTarget->id) : base_url('admin/badges');

// Preset Warna Badge (Tailwind Classes)
$colors = [
    'blue'   => 'bg-blue-100 text-blue-800',
    'green'  => 'bg-green-100 text-green-800',
    'red'    => 'bg-red-100 text-red-800',
    'yellow' => 'bg-yellow-100 text-yellow-800',
    'purple' => 'bg-purple-100 text-purple-800',
    'gray'   => 'bg-gray-100 text-gray-800',
    'black'  => 'bg-gray-800 text-white',
];
?>

<?= $this->section('page-title') ?>
    Manajemen Badge Produk
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
                    'label'    => 'Nama Badge (Internal)',
                    'value'    => old('name', $isEdit ? $badgeTarget->name : ''),
                    'required' => true,
                    'placeholder' => 'Misal: Promo Lebaran',
                    'error'    => session('errors.name')
                ]) ?>

                <?= view('components/molecules/FormGroup', [
                    'name'     => 'label',
                    'label'    => 'Teks Tampilan',
                    'value'    => old('label', $isEdit ? $badgeTarget->label : ''),
                    'required' => true,
                    'placeholder' => 'Misal: SALE 50%',
                    'error'    => session('errors.label')
                ]) ?>

                <div class="mb-4">
                    <?= view('components/atoms/Label', ['for' => 'color', 'text' => 'Warna Tampilan', 'required' => true]) ?>
                    <select name="color" id="color" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md shadow-sm">
                        <?php foreach ($colors as $key => $class): ?>
                            <option value="<?= $class ?>" <?= (old('color', $isEdit ? $badgeTarget->color : '') == $class) ? 'selected' : '' ?>>
                                <?= ucfirst($key) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center justify-between mt-6">
                    <?php if ($isEdit): ?>
                        <a href="<?= base_url('admin/badges') ?>" class="text-sm text-gray-500 hover:text-gray-700 underline">Batal</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>

                    <?= view('components/atoms/Button', [
                        'type'    => 'submit',
                        'label'   => $isEdit ? 'Update Badge' : 'Simpan Badge',
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
                <h3 class="font-medium text-gray-900">Daftar Badge Tersedia</h3>
                <span class="text-xs text-gray-500"><?= count($badges) ?> item</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Preview</th>
                            <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($badges)): ?>
                            <tr>
                                <td colspan="3" class="px-6 py-8 text-center text-gray-500">
                                    Belum ada badge. Buat badge pertama untuk menandai produk spesial.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($badges as $badge): ?>
                                <tr class="<?= ($isEdit && $badgeTarget->id == $badge->id) ? 'bg-blue-50' : 'hover:bg-gray-50' ?>">
                                    
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= esc($badge->name) ?></div>
                                        <div class="text-xs text-gray-500">Digunakan di <?= rand(0, 10) ?> produk</div> </td>
                                    
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= esc($badge->color) ?>">
                                            <?= esc($badge->label) ?>
                                        </span>
                                    </td>
                                    
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-3">
                                            <a href="<?= base_url('admin/badges/' . $badge->id . '/edit') ?>" class="text-blue-600 hover:text-blue-900">
                                                Edit
                                            </a>
                                            <form action="<?= base_url('admin/badges/' . $badge->id . '/delete') ?>" method="post" onsubmit="return confirm('Hapus badge ini?');">
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
