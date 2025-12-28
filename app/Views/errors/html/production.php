<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terjadi Kesalahan Sistem - DevDaily</title>
    
    <link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
    
    <style>
        /* Fallback sederhana jika CSS gagal load */
        body { font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-gray-50 text-slate-800 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white shadow-lg rounded-2xl p-8 text-center border border-gray-100">
        
        <div class="mx-auto flex items-center justify-center h-20 w-20 rounded-full bg-red-100 mb-6">
            <svg class="h-10 w-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-gray-900 mb-2">
            Whoops! Terjadi Kesalahan.
        </h1>
        
        <p class="text-gray-500 mb-8">
            Sepertinya ada masalah teknis di sisi kami. Tim pengembang telah diberitahu. Silakan coba muat ulang halaman atau kembali beberapa saat lagi.
        </p>

        <div class="space-y-3">
            <button onclick="window.location.reload()" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                Muat Ulang Halaman
            </button>

            <a href="<?= base_url() ?>" class="w-full flex justify-center py-3 px-4 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none transition-colors">
                Kembali ke Beranda
            </a>
        </div>

        <div class="mt-8 pt-6 border-t border-gray-100">
            <p class="text-xs text-gray-400">
                Error Code: 500 Internal Server Error
            </p>
        </div>

    </div>

</body>
</html>
