<?= $this->extend('components/templates/BaseLayout') ?>

<?= $this->section('content') ?>

<div class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
        <h1 class="text-4xl font-extrabold text-gray-900 sm:text-5xl md:text-6xl mb-6">
            Bandingkan Harga <span class="text-blue-600">Lebih Cerdas</span>
        </h1>
        <p class="max-w-md mx-auto text-base text-gray-500 sm:text-lg md:text-xl md:max-w-3xl mb-10">
            Temukan harga termurah untuk gadget impian Anda dari Shopee, Tokopedia, dan Lazada dalam satu kali pencarian.
        </p>
        
        <div class="max-w-xl mx-auto">
            <form action="<?= url_to('products.search') ?>" method="GET" class="flex gap-2">
                <input type="text" name="q" class="flex-1 rounded-lg border-gray-300 py-3 px-5 text-gray-900 shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none" placeholder="Cari iPhone 15, Samsung S24...">
                <button type="submit" class="bg-blue-600 text-white px-8 py-3 rounded-lg font-medium hover:bg-blue-700 shadow-lg transition">
                    Cari
                </button>
            </form>
        </div>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="text-center">
        <h2 class="text-2xl font-bold text-gray-900 mb-4">Fitur Unggulan</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-8">
            <div class="p-6 bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="text-3xl mb-4">⚡</div>
                <h3 class="font-semibold text-lg mb-2">Real-time Update</h3>
                <p class="text-gray-500 text-sm">Harga diperbarui secara otomatis setiap jam.</p>
            </div>
            <div class="p-6 bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="text-3xl mb-4">🛡️</div>
                <h3 class="font-semibold text-lg mb-2">Toko Terverifikasi</h3>
                <p class="text-gray-500 text-sm">Hanya menampilkan penjual dengan reputasi tinggi.</p>
            </div>
            <div class="p-6 bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="text-3xl mb-4">📊</div>
                <h3 class="font-semibold text-lg mb-2">Riwayat Harga</h3>
                <p class="text-gray-500 text-sm">Lihat grafik kenaikan dan penurunan harga.</p>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
