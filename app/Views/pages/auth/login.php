<?= $this->extend('components/templates/AuthLayout') ?>

<?= $this->section('title') ?>Login Admin<?= $this->endSection() ?>

<?= $this->section('auth-heading') ?>
    Selamat Datang Kembali
<?= $this->endSection() ?>

<?= $this->section('auth-subheading') ?>
    Masuk ke dashboard administrator Anda
<?= $this->endSection() ?>

<?= $this->section('auth-content') ?>

    <?php if (session()->getFlashdata('error')) : ?>
        <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded-md">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-700">
                        <?= session()->getFlashdata('error') ?>
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (session()->getFlashdata('success')) : ?>
        <div class="mb-4 bg-green-50 border-l-4 border-green-500 p-4 rounded-md">
            <div class="flex">
                <div class="ml-3">
                    <p class="text-sm text-green-700">
                        <?= session()->getFlashdata('success') ?>
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?= form_open('admin/auth/login', ['class' => 'space-y-6']) ?>
        
        <?= view('components/molecules/FormGroup', [
            'name'        => 'login_id',
            'label'       => 'Email atau Username',
            'placeholder' => 'admin@devdaily.com',
            'value'       => old('login_id'),
            'required'    => true
        ]) ?>

        <div class="relative">
            <?= view('components/molecules/FormGroup', [
                'name'        => 'password',
                'label'       => 'Password',
                'type'        => 'password',
                'placeholder' => '••••••••',
                'required'    => true
            ]) ?>
            
            <div class="absolute top-0 right-0">
                <a href="<?= base_url('admin/forgot-password') ?>" class="text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                    Lupa password?
                </a>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <input id="remember-me" name="remember" type="checkbox" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <label for="remember-me" class="ml-2 block text-sm text-gray-900 dark:text-gray-300">
                    Ingat saya
                </label>
            </div>
        </div>

        <?= view('components/atoms/Button', [
            'type'    => 'submit',
            'label'   => 'Masuk Dashboard',
            'variant' => 'primary',
            'width'   => 'w-full'
        ]) ?>

    <?= form_close() ?>

<?= $this->endSection() ?>
