<?= $this->extend('components/templates/BaseLayout') ?>

<?= $this->section('content') ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-100 dark:bg-gray-900 transition-colors duration-200">
    
    <div class="max-w-md w-full space-y-8 p-8 rounded-2xl glass">
        
        <div class="text-center">
            <div class="mx-auto h-12 w-12 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-xl shadow-lg">
                D
            </div>
            
            <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900 dark:text-white">
                <?= $this->renderSection('auth-heading') ?>
            </h2>
            
            <p class="mt-2 text-center text-sm text-gray-600 dark:text-gray-300">
                <?= $this->renderSection('auth-subheading') ?>
            </p>
        </div>

        <div class="mt-8">
            <?= $this->renderSection('auth-content') ?>
        </div>
        
    </div>
</div>

<?= $this->endSection() ?>
