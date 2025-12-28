<?= $this->extend('components/templates/AuthLayout') ?>

<?= $this->section('title') ?>Lupa Password<?= $this->endSection() ?>

<?= $this->section('auth-heading') ?>
    Reset Password
<?= $this->endSection() ?>

<?= $this->section('auth-subheading') ?>
    Masukkan email yang terdaftar akun Anda.
<?= $this->endSection() ?>

<?= $this->section('auth-content') ?>

    <?php if (session()->getFlashdata('error')) : ?>
        <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded-md">
            <p class="text-sm text-red-700"><?= session()->getFlashdata('error') ?></p>
        </div>
    <?php endif; ?>

    <?php if (session()->getFlashdata('success')) : ?>
        <div class="mb-4 bg-green-50 border-l-4 border-green-500 p-4 rounded-md">
            <p class="text-sm text-green-700"><?= session()->getFlashdata('success') ?></p>
        </div>
    <?php endif; ?>

    <?= form_open('admin/auth/forgot-password', ['class' => 'space-y-6']) ?>
        
        <?= view('components/molecules/FormGroup', [
            'name'        => 'email',
            'label'       => 'Email Address',
            'type'        => 'email',
            'placeholder' => 'nama@devdaily.com',
            'required'    => true
        ]) ?>

        <?= view('components/atoms/Button', [
            'type'    => 'submit',
            'label'   => 'Kirim Link Reset',
            'variant' => 'primary',
            'width'   => 'w-full'
        ]) ?>

        <div class="flex items-center justify-center mt-4">
            <a href="<?= base_url('admin/login') ?>" class="text-sm font-medium text-gray-600 hover:text-blue-600 flex items-center transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                Kembali ke Halaman Login
            </a>
        </div>

    <?= form_close() ?>

<?= $this->endSection() ?>
