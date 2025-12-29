<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Kelola Izin Akses
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="max-w-7xl mx-auto">
    
    <div class="md:flex md:items-center md:justify-between mb-6">
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
                Konfigurasi Izin: <span class="text-blue-600"><?= esc(str_replace('_', ' ', $role->name)) ?></span>
            </h2>
            <p class="mt-1 text-sm text-gray-500">
                Tentukan tindakan apa saja yang boleh dilakukan oleh pengguna dengan role ini.
            </p>
        </div>
        <div class="mt-4 flex md:mt-0 md:ml-4">
            <a href="<?= base_url('admin/authorization') ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                Kembali
            </a>
        </div>
    </div>

    <?= form_open(base_url('admin/authorization/roles/' . $role->id . '/permissions')) ?>
    
    <div class="space-y-6">
        
        <?php 
        // Asumsi $permissions dikelompokkan dari Controller. 
        // Jika flat, kita kelompokkan manual di sini berdasarkan prefix (misal: user.create -> Group User)
        // Format $groupedPermissions = ['User' => [$p1, $p2], 'Product' => [$p3, $p4]]
        ?>
        
        <?php if (empty($groupedPermissions)): ?>
            <div class="bg-white p-12 text-center rounded-lg border border-gray-200">
                <p class="text-gray-500">Tidak ada permission yang tersedia di sistem.</p>
            </div>
        <?php else: ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($groupedPermissions as $groupName => $perms): ?>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        
                        <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide">
                                <?= esc($groupName) ?>
                            </h3>
                            <button type="button" onclick="toggleGroup(this)" class="text-xs text-blue-600 hover:text-blue-800 font-medium focus:outline-none">
                                Pilih Semua
                            </button>
                        </div>
                        
                        <div class="p-4 space-y-3">
                            <?php foreach ($perms as $perm): ?>
                                <?php 
                                    // Cek apakah role ini punya permission ini
                                    $isChecked = in_array($perm->id, $rolePermissions ?? []) ? 'checked' : '';
                                ?>
                                <div class="relative flex items-start">
                                    <div class="flex items-center h-5">
                                        <input id="perm_<?= $perm->id ?>" 
                                               name="permissions[]" 
                                               value="<?= $perm->id ?>" 
                                               type="checkbox" 
                                               <?= $isChecked ?>
                                               class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded cursor-pointer group-checkbox">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="perm_<?= $perm->id ?>" class="font-medium text-gray-700 cursor-pointer select-none">
                                            <?= esc($perm->description ?? $perm->name) ?>
                                        </label>
                                        <p class="text-xs text-gray-400 font-mono mt-0.5"><?= esc($perm->name) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

    <div class="fixed bottom-0 left-0 right-0 md:left-64 bg-white border-t border-gray-200 p-4 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.1)] z-10 flex justify-end items-center space-x-4">
        <span class="text-sm text-gray-500">Perubahan akan langsung berlaku untuk semua user.</span>
        
        <?= view('components/atoms/Button', [
            'type'    => 'submit',
            'label'   => 'Simpan Perubahan Izin',
            'variant' => 'primary',
            'width'   => 'w-auto'
        ]) ?>
    </div>
    
    <div class="h-20"></div>

    <?= form_close() ?>

</div>

<script>
    function toggleGroup(btn) {
        // Cari container parent (card)
        const card = btn.closest('.bg-white');
        const checkboxes = card.querySelectorAll('.group-checkbox');
        
        // Cek status checkbox pertama untuk menentukan toggle on/off
        const firstState = checkboxes[0].checked;
        const newState = !firstState; // Kebalikan dari status sekarang
        
        checkboxes.forEach(cb => {
            cb.checked = newState;
        });
        
        // Ubah teks tombol
        btn.innerText = newState ? 'Batal Pilih' : 'Pilih Semua';
    }
</script>

<?= $this->endSection() ?>
