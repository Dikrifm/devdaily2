<?= $this->extend('components/templates/AdminLayout') ?>

<?php
// Deteksi Mode: Create atau Edit
$isEdit = isset($user) && $user !== null;
$title  = $isEdit ? 'Edit User: ' . esc($user->getName()) : 'Tambah User Baru';
$actionUrl = $isEdit ? base_url('admin/users/' . $user->getId()) : base_url('admin/users');
?>

<?= $this->section('page-title') ?>
    <?= $title ?>
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="max-w-3xl mx-auto">
    
    <div class="mb-6">
        <a href="<?= base_url('admin/users') ?>" class="text-sm text-gray-500 hover:text-blue-600 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
            Kembali ke Daftar User
        </a>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-medium leading-6 text-gray-900"><?= $title ?></h3>
            <p class="mt-1 text-sm text-gray-500">Isi informasi lengkap anggota tim di bawah ini.</p>
        </div>

        <div class="p-6">
            <?php if (session()->getFlashdata('error')) : ?>
                <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4">
                    <p class="text-sm text-red-700"><?= session()->getFlashdata('error') ?></p>
                </div>
            <?php endif; ?>

            <?= form_open($actionUrl) ?>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <?= view('components/molecules/FormGroup', [
                        'name'     => 'name',
                        'label'    => 'Nama Lengkap',
                        'value'    => old('name', $isEdit ? $user->getName() : ''),
                        'required' => true,
                        'error'    => session('errors.name')
                    ]) ?>

                    <?= view('components/molecules/FormGroup', [
                        'name'     => 'username',
                        'label'    => 'Username',
                        'value'    => old('username', $isEdit ? $user->getUsername() : ''),
                        'required' => true,
                        'error'    => session('errors.username')
                    ]) ?>
                </div>

                <?= view('components/molecules/FormGroup', [
                    'name'     => 'email',
                    'label'    => 'Alamat Email',
                    'type'     => 'email',
                    'value'    => old('email', $isEdit ? $user->getEmail() : ''),
                    'required' => true,
                    'error'    => session('errors.email')
                ]) ?>

                <div class="mb-4">
                    <?= view('components/atoms/Label', ['for' => 'role', 'text' => 'Role Akses', 'required' => true]) ?>
                    <select name="role" id="role" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md border shadow-sm">
                        <?php foreach ($roles as $key => $label): ?>
                            <option value="<?= $key ?>" <?= (old('role', $isEdit ? $user->getRole() : '') == $key) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="border-t border-gray-100 my-6 pt-6">
                    <h4 class="text-sm font-semibold text-gray-900 mb-4">Keamanan & Status</h4>
                    
                    <div class="mb-4">
                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'password',
                            'label'    => 'Password',
                            'type'     => 'password',
                            'required' => !$isEdit, // Wajib hanya saat create
                            'error'    => session('errors.password'),
                            'placeholder' => $isEdit ? '••••••••' : 'Masukkan password minimal 8 karakter'
                        ]) ?>
                        <?php if ($isEdit): ?>
                            <p class="text-xs text-gray-500 mt-[-0.5rem] mb-2">Kosongkan jika tidak ingin mengubah password.</p>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input type="hidden" name="active" value="0">
                            <input id="active" name="active" type="checkbox" value="1" 
                                <?= (old('active', $isEdit ? $user->isActive() : true)) ? 'checked' : '' ?>
                                class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="active" class="font-medium text-gray-700">Akun Aktif</label>
                            <p class="text-gray-500">User aktif dapat login ke dashboard admin.</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <a href="<?= base_url('admin/users') ?>" class="px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                        Batal
                    </a>
                    
                    <?= view('components/atoms/Button', [
                        'type'    => 'submit',
                        'label'   => $isEdit ? 'Simpan Perubahan' : 'Buat User Baru',
                        'variant' => 'primary',
                        'width'   => 'w-auto'
                    ]) ?>
                </div>

            <?= form_close() ?>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
