<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold"><?= $package ? 'Edit Paket' : 'Tambah Paket Baru' ?></h1>
    <a href="/cms/packages" class="text-gray-600 hover:text-gray-800"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <form method="post" action="<?= $package ? '/cms/packages/' . $package['id'] . '/edit' : '/cms/packages/create' ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="block text-gray-700 text-sm font-bold mb-2">Nama Paket <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="<?= esc($package['name'] ?? old('name')) ?>" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Harga (Rp) <span class="text-red-500">*</span></label>
                <input type="number" name="price" value="<?= esc($package['price'] ?? old('price', 0)) ?>" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Durasi (Bulan) <span class="text-red-500">*</span></label>
                <input type="number" name="duration_months" value="<?= esc($package['duration_months'] ?? old('duration_months', 1)) ?>" min="1" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Kuota Produk</label>
                <input type="number" name="product_quota" value="<?= esc($package['product_quota'] ?? old('product_quota', 0)) ?>" min="0" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Kuota Transaksi/Bulan</label>
                <input type="number" name="transaction_monthly_quota" value="<?= esc($package['transaction_monthly_quota'] ?? old('transaction_monthly_quota', 0)) ?>" min="0" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>

        <!-- Integration Toggles -->
        <div class="mt-6 pt-6 border-t">
            <h3 class="text-lg font-bold mb-4">Fitur Integrasi</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <?php foreach ($integrationFields as $key => $label): ?>
                <label class="flex items-center gap-2 p-3 border rounded-lg hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="features[]" value="<?= $key ?>" <?= in_array($key, $selectedFeatures) ? 'checked' : '' ?> class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm"><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700">
                <?= $package ? 'Simpan Perubahan' : 'Buat Paket' ?>
            </button>
            <a href="/cms/packages" class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400">Batal</a>
        </div>
    </form>
</div>
