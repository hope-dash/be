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
            <div class="mt-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Multi Toko (maksimal jumlah toko)</label>
                <input type="number" name="multi_toko" value="<?= esc($multiTokoValue ?? 1) ?>" min="1" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 w-32">
            </div>
        </div>

        <!-- Detail Section -->
        <div class="mt-6 pt-6 border-t">
            <h3 class="text-lg font-bold mb-4">Detail</h3>
            <div id="detailContainer">
                <?php if (!empty($detailItems)): ?>
                    <?php foreach ($detailItems as $item): ?>
                    <div class="flex gap-2 mb-2">
                        <input type="text" name="detail_items[]" value="<?= esc($item) ?>" class="flex-1 px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Tulis detail...">
                        <button type="button" onclick="this.parentElement.remove()" class="px-3 py-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg"><i class="fas fa-times"></i></button>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="flex gap-2 mb-2">
                        <input type="text" name="detail_items[]" class="flex-1 px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Tulis detail...">
                        <button type="button" onclick="this.parentElement.remove()" class="px-3 py-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" onclick="addDetailInput()" class="mt-2 text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                <i class="fas fa-plus"></i> Tambah Baris
            </button>
        </div>

        <script>
        function addDetailInput(value = '') {
            const container = document.getElementById('detailContainer');
            const div = document.createElement('div');
            div.className = 'flex gap-2 mb-2';
            div.innerHTML = '<input type="text" name="detail_items[]" value="' + value.replace(/"/g, '&quot;') + '" class="flex-1 px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Tulis detail..."> <button type="button" onclick="this.parentElement.remove()" class="px-3 py-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg"><i class="fas fa-times"></i></button>';
            container.appendChild(div);
        }
        </script>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700">
                <?= $package ? 'Simpan Perubahan' : 'Buat Paket' ?>
            </button>
            <a href="/cms/packages" class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400">Batal</a>
        </div>
    </form>
</div>
