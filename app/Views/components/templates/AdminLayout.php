<?= $this->extend('components/templates/BaseLayout') ?>

<?= $this->section('content') ?>

<div class="flex h-screen overflow-hidden bg-gray-100">

    <?= view('components/organisms/Sidebar') ?>

    <div class="flex-1 flex flex-col overflow-hidden relative">
        
        <?= view('components/organisms/Navbar') ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-6 text-slate-800">
            <?= $this->renderSection('admin-content') ?>
        </main>
        
    </div>

</div>

<div id="sidebar-backdrop" class="fixed inset-0 z-10 bg-gray-900 opacity-50 hidden md:hidden glass-dark transition-opacity"></div>

<?= $this->endSection() ?>
