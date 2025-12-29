<?= $this->extend('components/templates/AdminLayout') ?>

<?= $this->section('page-title') ?>
    Detail Log Aktivitas #<?= $log->id ?>
<?= $this->endSection() ?>

<?= $this->section('admin-content') ?>

<div class="max-w-6xl mx-auto">

    <div class="mb-6 flex items-center justify-between">
        <a href="<?= base_url('admin/audit-logs') ?>" class="text-sm text-gray-500 hover:text-blue-600 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
            Kembali ke Riwayat
        </a>
        <span class="text-sm text-gray-400">
            Dicatat pada: <?= date('d M Y, H:i:s', strtotime($log->created_at)) ?>
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-1 space-y-6">
            
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                    <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wide">Pelaku (Actor)</h3>
                </div>
                <div class="p-4">
                    <div class="flex items-center mb-4">
                        <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold mr-3">
                            <?= strtoupper(substr($log->user_name ?? '?', 0, 1)) ?>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900"><?= esc($log->user_name ?? 'System/Guest') ?></p>
                            <p class="text-xs text-gray-500">ID: <?= esc($log->user_id ?? '-') ?></p>
                        </div>
                    </div>
                    
                    <div class="space-y-2 border-t border-gray-100 pt-3">
                        <div>
                            <span class="text-xs text-gray-500 block">Role</span>
                            <span class="text-sm font-medium text-gray-800"><?= esc($log->user_role ?? 'Unknown') ?></span>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 block">IP Address</span>
                            <span class="text-sm font-mono text-gray-800 bg-gray-50 px-2 py-0.5 rounded border border-gray-200">
                                <?= esc($log->ip_address) ?>
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 block">User Agent</span>
                            <p class="text-xs text-gray-600 break-words mt-1">
                                <?= esc($log->user_agent) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                    <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wide">Ringkasan Event</h3>
                </div>
                <div class="p-4 space-y-3">
                    <?php
                        // Warna Badge Event
                        $badgeClass = 'bg-gray-100 text-gray-800';
                        if (strpos($log->event, 'create') !== false) $badgeClass = 'bg-green-100 text-green-800';
                        if (strpos($log->event, 'update') !== false) $badgeClass = 'bg-blue-100 text-blue-800';
                        if (strpos($log->event, 'delete') !== false) $badgeClass = 'bg-red-100 text-red-800';
                        if (strpos($log->event, 'login') !== false) $badgeClass = 'bg-purple-100 text-purple-800';
                    ?>
                    <div>
                        <span class="text-xs text-gray-500 block">Tipe Event</span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $badgeClass ?> capitalize mt-1">
                            <?= esc($log->event) ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 block">Target ID</span>
                        <span class="text-sm font-mono text-gray-800">
                            #<?= esc($log->subject_id ?? '-') ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 block">URL Akses</span>
                        <code class="text-xs text-blue-600 break-all bg-blue-50 px-1 py-0.5 rounded">
                            <?= esc($log->url ?? '/') ?>
                        </code>
                    </div>
                </div>
            </div>

        </div>

        <div class="lg:col-span-2 space-y-6">
            
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wide">Data Perubahan</h3>
                    <span class="text-xs text-gray-500">JSON Format</span>
                </div>
                
                <div class="p-0">
                    <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200">
                        
                        <div class="p-4 bg-red-50/30">
                            <h4 class="text-xs font-bold text-red-600 uppercase mb-2">Sebelum (Old)</h4>
                            <?php if (empty($log->old_values)): ?>
                                <p class="text-sm text-gray-400 italic">Tidak ada data sebelumnya (Misal: Create Event)</p>
                            <?php else: ?>
                                <pre class="text-xs font-mono text-gray-700 bg-white p-3 rounded border border-gray-200 overflow-x-auto custom-scrollbar"><?php 
                                    $old = is_string($log->old_values) ? json_decode($log->old_values) : $log->old_values;
                                    echo esc(json_encode($old, JSON_PRETTY_PRINT)); 
                                ?></pre>
                            <?php endif; ?>
                        </div>

                        <div class="p-4 bg-green-50/30">
                            <h4 class="text-xs font-bold text-green-600 uppercase mb-2">Sesudah (New)</h4>
                            <?php if (empty($log->new_values)): ?>
                                <p class="text-sm text-gray-400 italic">Tidak ada data baru (Misal: Delete Event)</p>
                            <?php else: ?>
                                <pre class="text-xs font-mono text-gray-700 bg-white p-3 rounded border border-gray-200 overflow-x-auto custom-scrollbar"><?php 
                                    $new = is_string($log->new_values) ? json_decode($log->new_values) : $log->new_values;
                                    echo esc(json_encode($new, JSON_PRETTY_PRINT)); 
                                ?></pre>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>

            <?php if (!empty($log->exception_info)): ?>
                <div class="bg-red-50 shadow-sm rounded-lg border border-red-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-red-200">
                        <h3 class="text-sm font-bold text-red-700 uppercase tracking-wide">Exception / Error Log</h3>
                    </div>
                    <div class="p-4">
                        <pre class="text-xs font-mono text-red-800 whitespace-pre-wrap"><?= esc($log->exception_info) ?></pre>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<style>
    /* Scrollbar minimalis untuk blok JSON */
    .custom-scrollbar::-webkit-scrollbar {
        height: 8px;
        width: 8px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: #f1f1f1; 
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #cbd5e1; 
        border-radius: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #94a3b8; 
    }
</style>

<?= $this->endSection() ?>
