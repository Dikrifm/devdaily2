<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Profil Saya
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="max-w-6xl mx-auto">

    <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-900">Pengaturan Akun</h2>
        <p class="mt-1 text-sm text-gray-500">Kelola informasi pribadi dan keamanan akun Anda.</p>
    </div>

    <?php if (session()->getFlashdata('success')) : ?>
        <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-md">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-green-700"><?= session()->getFlashdata('success') ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (session()->getFlashdata('error')) : ?>
        <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-md">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-700"><?= session()->getFlashdata('error') ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-medium leading-6 text-gray-900">Informasi Dasar</h3>
            </div>
            <div class="p-6">
                <?= form_open('admin/profile/update') ?>
                    
                    <div class="space-y-6">
                        <div class="flex items-center">
                            <span class="inline-block h-16 w-16 rounded-full overflow-hidden bg-gray-100 border border-gray-200">
                                <div class="h-full w-full flex items-center justify-center text-gray-400 text-2xl font-bold">
                                    <?= strtoupper(substr($user->username ?? 'A', 0, 1)) ?>
                                </div>
                            </span>
                            <div class="ml-4">
                                <h4 class="text-sm font-medium text-gray-900"><?= esc($user->username ?? 'User') ?></h4>
                                <p class="text-xs text-gray-500">Administrator</p>
                            </div>
                        </div>

                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'name',
                            'label'    => 'Nama Lengkap',
                            'value'    => old('name', $user->name ?? ''),
                            'required' => true,
                            'error'    => session('errors.name')
                        ]) ?>

                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'email',
                            'label'    => 'Alamat Email',
                            'type'     => 'email',
                            'value'    => old('email', $user->email ?? ''),
                            'required' => true,
                            'error'    => session('errors.email')
                        ]) ?>
                    </div>

                    <div class="mt-6 border-t border-gray-100 pt-6 flex justify-end">
                        <?= view('components/atoms/Button', [
                            'type'    => 'submit',
                            'label'   => 'Simpan Profil',
                            'variant' => 'primary',
                            'width'   => 'w-auto'
                        ]) ?>
                    </div>

                <?= form_close() ?>
            </div>
        </div>

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-medium leading-6 text-gray-900">Ganti Password</h3>
            </div>
            <div class="p-6">
                <?= form_open('admin/profile/change-password') ?>
                    
                    <div class="space-y-6">
                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'current_password',
                            'label'    => 'Password Saat Ini',
                            'type'     => 'password',
                            'required' => true,
                            'placeholder' => '••••••••',
                            'error'    => session('errors.current_password')
                        ]) ?>

                        <hr class="border-gray-100">

                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'new_password',
                            'label'    => 'Password Baru',
                            'type'     => 'password',
                            'required' => true,
                            'placeholder' => 'Minimal 8 karakter',
                            'error'    => session('errors.new_password')
                        ]) ?>

                        <?= view('components/molecules/FormGroup', [
                            'name'     => 'new_password_confirm',
                            'label'    => 'Konfirmasi Password Baru',
                            'type'     => 'password',
                            'required' => true,
                            'placeholder' => 'Ulangi password baru',
                            'error'    => session('errors.new_password_confirm')
                        ]) ?>
                    </div>

                    <div class="mt-6 border-t border-gray-100 pt-6 flex justify-end">
                        <?= view('components/atoms/Button', [
                            'type'    => 'submit',
                            'label'   => 'Update Password',
                            'variant' => 'secondary',
                            'width'   => 'w-auto'
                        ]) ?>
                    </div>

                <?= form_close() ?>
            </div>
        </div>

    </div>
</div>

<?= $this->endSection() ?>
