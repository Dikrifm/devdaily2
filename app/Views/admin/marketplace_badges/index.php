<?= $this->extend('components/templates/AdminLayout') ?>

<?php
// Deteksi Mode Edit
$isEdit = isset($mbTarget) && $mbTarget !== null;
$formTitle = $isEdit ? 'Edit Marketplace Badge' : 'Tambah Badge Baru';
$formAction = $isEdit ? base_url('admin/marketplace-badges/' . $mbTarget->id) : base_url('admin/marketplace-badges');
?>

<?= $this->section('page-title') ?>
    Badge Reputasi Marketplace
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
                
                <div class="mb-4">
                    <?= view('components/atoms/Label', ['for' => 'marketplace_id', 'text' => 'Marketplace', 'required' => true]) ?>
                    <select name="marketplace_id" id="marketplace_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md shadow-sm">
                        <option value="">Pilih Platform...</option>
                        <?php foreach ($marketplaces as $mp): ?>
                            <option value="<?= $mp->id ?>" <?= (old('marketplace_id', $isEdit ? $mbTarget->marketplace_id : '') == $mp->id) ? 'selected' : '' ?>>
                                <?= esc($mp->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?= view('components/molecules/FormGroup', [
                    'name'     => 'label',
                    'label'    => 'Label Badge',
                    'value'    => old('label', $isEdit ? $mbTarget->label : ''),
                    'required' => true,
                    'placeholder' => 'Misal: Official Store',
                    'error'    => session('errors.label')
                ]) ?>

                <div class="mb-4">
                    <?= view('components/atoms/Label', ['for' => 'icon_svg', 'text' => 'SVG Icon (Opsional)']) ?>
                    <textarea id="icon_svg" name="icon_svg" rows="3" class="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md font-mono text-xs" placeholder='<svg...>...</svg>'><?= old('icon_svg', $isEdit ? $mbTarget->icon_svg : '') ?></textarea>
                    <p class="mt-1 text-xs text-gray-500">Masukkan kode SVG mentah untuk ikon centang atau piala.</p>
                </div>

                <div class="flex items-center justify-between mt-6">
                    <?php if ($isEdit): ?>
                        <a href="<?= base_url('admin/marketplace-badges') ?>" class="text-sm text-gray-500 hover:text-gray-700 underline">Batal</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>

                    <?= view('components/atoms/Button', [
                        'type'    => 'submit',
                        'label'   => $isEdit ? 'Simpan Perubahan' : 'Tambah Badge',
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
                <h3 class="font-medium text-gray-900">Daftar Reputasi Toko</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Marketplace</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Preview Badge</th>
                            <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($badges)): ?>
                            <tr>
                                <td colspan="3" class="px-6 py-8 text-center text-gray-500">
                                    Belum ada badge marketplace.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($badges as $badge): ?>
                                <tr class="<?= ($isEdit && $mbTarget->id == $badge->id) ? 'bg-blue-50' : 'hover:bg-gray-50' ?>">
                                    
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= esc($badge->marketplace_name) ?></div>
                                    </td>
                                    
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100">
                                            <?php if (!empty($badge->icon_svg)): ?>
                                                <span class="w-3 h-3 mr-1">
                                                    <?= $badge->icon_svg ?> </span>
                                            <?php endif; ?>
                                            <?= esc($badge->label) ?>
                                        </div>
                                    </td>
                                    
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-3">
                                            <a href="<?= base_url('admin/marketplace-badges/' . $badge->id . '/edit') ?>" class="text-blue-600 hover:text-blue-900">
                                                Edit
                                            </a>
                                            <form action="<?= base_url('admin/marketplace-badges/' . $badge->id . '/delete') ?>" method="post" onsubmit="return confirm('Hapus badge ini?');">
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
