<?= $this->extend('components/templates/BaseLayout') ?>

<?= $this->section('title') ?>
    Halaman Tidak Ditemukan
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="min-h-screen bg-white flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md text-center">
        
        <h1 class="text-9xl font-extrabold text-blue-100 tracking-widest">
            404
        </h1>
        
        <div class="bg-blue-600 px-2 text-sm rounded rotate-12 absolute top-1/3 left-1/2 -translate-x-1/2 -translate-y-1/2 text-white font-bold transform shadow-lg">
            Page Not Found
        </div>

        <h2 class="mt-8 text-3xl font-extrabold text-gray-900 tracking-tight sm:text-4xl">
            Ups! Halaman hilang.
        </h2>
        
        <p class="mt-4 text-base text-gray-500">
            <?php if (ENVIRONMENT !== 'production') : ?>
                <?= nl2br(esc($message)) ?>
            <?php else : ?>
                Maaf, kami tidak dapat menemukan halaman yang Anda cari. Mungkin halaman tersebut sudah dihapus atau URL-nya salah.
            <?php endif; ?>
        </p>

        <div class="mt-8 flex justify-center gap-4">
            <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-500 font-medium flex items-center transition-colors">
                <svg class="mr-2 -ml-1 h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
                Kembali
            </a>

            <span class="text-gray-300">|</span>

            <a href="<?= base_url('admin/dashboard') ?>" class="text-blue-600 hover:text-blue-500 font-medium flex items-center transition-colors">
                Ke Dashboard
                <svg class="ml-2 -mr-1 h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </a>
        </div>

    </div>
</div>

<?= $this->endSection() ?>
