<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Audit Log Sistem
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="space-y-6">

    <div class="sm:flex sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Riwayat Aktivitas</h2>
            <p class="mt-1 text-sm text-gray-500">Rekam jejak tindakan pengguna untuk keamanan dan audit.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="<?= base_url('admin/audit-logs/export') ?>" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                <svg class="-ml-1 mr-2 h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                Export CSV
            </a>
        </div>
    </div>

    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
        <form action="" method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            
            <div class="md:col-span-1">
                <label for="user_id" class="block text-xs font-medium text-gray-500 mb-1">User</label>
                <select name="user_id" id="user_id" class="block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="">Semua User</option>
                    <?php if (!empty($users)): ?>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u->id ?>" <?= ($filters['user_id'] ?? '') == $u->id ? 'selected' : '' ?>>
                                <?= esc($u->name) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="md:col-span-1">
                <label for="event" class="block text-xs font-medium text-gray-500 mb-1">Tipe Event</label>
                <select name="event" id="event" class="block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="">Semua Event</option>
                    <option value="create" <?= ($filters['event'] ?? '') == 'create' ? 'selected' : '' ?>>Create (Tambah)</option>
                    <option value="update" <?= ($filters['event'] ?? '') == 'update' ? 'selected' : '' ?>>Update (Ubah)</option>
                    <option value="delete" <?= ($filters['event'] ?? '') == 'delete' ? 'selected' : '' ?>>Delete (Hapus)</option>
                    <option value="login" <?= ($filters['event'] ?? '') == 'login' ? 'selected' : '' ?>>Login/Auth</option>
                </select>
            </div>

            <div class="md:col-span-1">
                <label for="date" class="block text-xs font-medium text-gray-500 mb-1">Tanggal</label>
                <input type="date" name="date" id="date" value="<?= esc($filters['date'] ?? '') ?>" class="block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div class="md:col-span-1 flex items-end">
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none">
                    Terapkan
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aktivitas</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Target</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                        <th scope="col" class="relative px-6 py-3"><span class="sr-only">View</span></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                Tidak ada catatan aktivitas yang ditemukan sesuai filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                                // Logic warna badge berdasarkan event
                                $eventColor = 'bg-gray-100 text-gray-800'; // Default
                                if (strpos($log->event, 'create') !== false) $eventColor = 'bg-green-100 text-green-800';
                                if (strpos($log->event, 'update') !== false) $eventColor = 'bg-blue-100 text-blue-800';
                                if (strpos($log->event, 'delete') !== false) $eventColor = 'bg-red-100 text-red-800';
                                if (strpos($log->event, 'login') !== false) $eventColor = 'bg-purple-100 text-purple-800';
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('d M Y H:i', strtotime($log->created_at)) ?>
                                </td>
                                
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-6 w-6 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 mr-2">
                                            <?= strtoupper(substr($log->user_name ?? '?', 0, 1)) ?>
                                        </div>
                                        <div class="text-sm font-medium text-gray-900"><?= esc($log->user_name ?? 'System/Guest') ?></div>
                                    </div>
                                </td>
                                
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $eventColor ?> capitalize">
                                        <?= esc(str_replace('.', ' ', $log->event)) ?>
                                    </span>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 truncate max-w-xs">
                                    <?= esc($log->summary ?? '-') ?>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div class="flex flex-col">
                                        <span><?= esc($log->ip_address) ?></span>
                                        <span class="text-xs text-gray-400 truncate max-w-[100px]" title="<?= esc($log->user_agent) ?>">
                                            Browser Info
                                        </span>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="<?= base_url('admin/audit-logs/' . $log->id) ?>" class="text-blue-600 hover:text-blue-900">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (isset($pager)): ?>
            <div class="px-4 py-3 border-t border-gray-200 sm:px-6">
                <?= $pager->links() ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
