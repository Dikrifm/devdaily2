<?= $this->extend('components/templates/AuthLayout') ?>

<?= $this->section('content') ?>

<div class="w-full max-w-sm p-6 bg-white rounded-xl shadow-lg dark:bg-gray-800 border border-gray-200 dark:border-gray-700 backdrop-blur-sm">
    
    <div class="mb-8 text-center">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">DevDaily</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Sign in to your account</p>
    </div>

    <form action="<?= base_url('auth/process') ?>" method="post" class="space-y-6">
        <?= csrf_field() ?>

        <div>
            <label for="email" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Email</label>
            <input type="email" name="email" id="email" 
                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary focus:border-primary block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white" 
                   placeholder="admin@devdaily.com" required>
        </div>

        <div>
            <label for="password" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Password</label>
            <input type="password" name="password" id="password" 
                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary focus:border-primary block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white" 
                   required>
        </div>

        <button type="submit" 
                class="w-full text-white bg-primary hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800 transition-colors">
            Login Securely
        </button>
    </form>
</div>

<?= $this->endSection() ?>
