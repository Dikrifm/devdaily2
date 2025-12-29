<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Otorisasi & Role
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="space-y-6">

    <div class="sm:flex sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Peran Pengguna (Roles)</h2>
            <p class="mt-1 text-sm text-gray-500">Definisikan jabatan dan hak akses untuk keamanan sistem.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <button type="button" onclick="document.getElementById('createRoleModal').classList.remove('hidden')" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                Buat Role Baru
            </button>
        </div>
    </div>

    <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-blue-700">
                    Role <strong>Super Admin</strong> memiliki akses penuh ke seluruh sistem dan tidak dapat dihapus atau diubah izinnya.
                </p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if (empty($roles)): ?>
            <div class="col-span-full py-12 text-center bg-white rounded-lg border border-gray-200 border-dashed">
                <p class="text-gray-500">Belum ada role yang didefinisikan (selain default).</p>
            </div>
        <?php else: ?>
            <?php foreach ($roles as $role): ?>
                <?php 
                    $isSystem = in_array($role->name, ['super_admin', 'admin']); 
                    $cardBorder = $isSystem ? 'border-l-4 border-l-purple-500' : 'border-gray-200';
                ?>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border <?= $cardBorder ?> hover:shadow-md transition-shadow">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900 capitalize">
                                <?= esc(str_replace('_', ' ', $role->name)) ?>
                            </h3>
                            <?php if ($isSystem): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                    System
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    Custom
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <p class="mt-2 text-sm text-gray-500 h-10 line-clamp-2">
                            <?= esc($role->description ?? 'Tidak ada deskripsi.') ?>
                        </p>
                        
                        <div class="mt-4 flex items-center text-xs text-gray-500">
                            <svg class="flex-shrink-0 mr-1.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                            Digunakan oleh <?= number_format($role->users_count ?? 0) ?> user
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 px-6 py-4 flex justify-between items-center border-t border-gray-100">
                        <a href="<?= base_url('admin/authorization/permissions/' . $role->id) ?>" class="text-sm font-medium text-blue-600 hover:text-blue-500 flex items-center">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" /></svg>
                            Kelola Izin
                        </a>

                        <?php if (!$isSystem): ?>
                            <form action="<?= base_url('admin/authorization/roles/' . $role->id . '/delete') ?>" method="post" onsubmit="return confirm('Hapus role ini? User yang menggunakan role ini akan kehilangan akses.');">
                                <?= csrf_field() ?>
                                <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-500">
                                    Hapus
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<div id="createRoleModal" class="hidden fixed z-10 inset-0 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="document.getElementById('createRoleModal').classList.add('hidden')"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        
        <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">
            <?= form_open(base_url('admin/authorization/roles')) ?>
                <div>
                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Buat Role Baru</h3>
                    <div class="mt-4 space-y-4">
                        <?= view('components/molecules/FormGroup', [
                            'name' => 'name',
                            'label' => 'Nama Role (Identifier)',
                            'placeholder' => 'misal: staff_gudang',
                            'required' => true
                        ]) ?>
                        <?= view('components/molecules/FormGroup', [
                            'name' => 'description',
                            'label' => 'Deskripsi',
                            'placeholder' => 'Jelaskan fungsi role ini...',
                        ]) ?>
                    </div>
                </div>
                <div class="mt-5 sm:mt-6 sm:grid sm:grid-cols-2 sm:gap-3 sm:grid-flow-row-dense">
                    <?= view('components/atoms/Button', ['type' => 'submit', 'label' => 'Simpan', 'width' => 'w-full']) ?>
                    <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:col-start-1 sm:text-sm" onclick="document.getElementById('createRoleModal').classList.add('hidden')">
                        Batal
                    </button>
                </div>
            <?= form_close() ?>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
