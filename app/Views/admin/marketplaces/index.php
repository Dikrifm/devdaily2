<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Kelola Marketplace
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="space-y-6">

    <div class="sm:flex sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Platform Marketplace</h2>
            <p class="mt-1 text-sm text-gray-500">Atur platform e-commerce tempat produk Anda dijual.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="<?= base_url('admin/marketplaces/new') ?>" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                Tambah Platform
            </a>
        </div>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Platform</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Warna Brand</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($marketplaces)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                                <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                                <span class="block">Belum ada marketplace yang dikonfigurasi.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($marketplaces as $mp): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 flex items-center justify-center rounded-full bg-gray-100 text-gray-500 font-bold uppercase text-xs border border-gray-200">
                                            <?= substr($mp->name, 0, 2) ?>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?= esc($mp->name) ?></div>
                                            <div class="text-xs text-gray-500"><?= esc($mp->slug) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <span class="h-6 w-6 rounded border border-gray-200 shadow-sm" style="background-color: <?= esc($mp->color ?? '#000000') ?>;"></span>
                                        <span class="ml-2 text-sm text-gray-500 font-mono"><?= esc($mp->color ?? '#000000') ?></span>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <form action="<?= base_url('admin/marketplaces/' . $mp->id . '/toggle-status') ?>" method="post">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 <?= $mp->is_active ? 'bg-green-500' : 'bg-gray-200' ?>">
                                            <span class="sr-only">Toggle status</span>
                                            <span aria-hidden="true" class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transform ring-0 transition ease-in-out duration-200 <?= $mp->is_active ? 'translate-x-5' : 'translate-x-0' ?>"></span>
                                        </button>
                                    </form>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="<?= base_url('admin/marketplaces/' . $mp->id . '/edit') ?>" class="text-blue-600 hover:text-blue-900 mr-4">Edit</a>
                                    
                                    <form action="<?= base_url('admin/marketplaces/' . $mp->id . '/delete') ?>" method="post" class="inline-block" onsubmit="return confirm('Hapus marketplace ini? Link produk terkait mungkin akan terpengaruh.');">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-red-600 hover:text-red-900 bg-transparent border-none cursor-pointer p-0">
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?= $this->endSection() ?>
