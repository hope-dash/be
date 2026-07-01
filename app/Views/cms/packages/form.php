<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight"><?= $title ?></h1>
    <p class="text-gray-500 mt-1"><?= $package ? 'Edit package subscription yang sudah ada' : 'Buat package subscription baru' ?></p>
</div>

<div class="max-w-2xl">
    <form action="<?= $package ? '/cms/packages/' . $package['id'] . '/edit' : '/cms/packages/create' ?>" method="POST" class="space-y-6">
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <h3 class="font-bold text-gray-900 mb-4">Informasi Package</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Kode Package *</label>
                    <input type="text" name="code" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($package['code'] ?? '') ?>" placeholder="CONTOH_BASIC" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Nama Package *</label>
                    <input type="text" name="name" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($package['name'] ?? '') ?>" placeholder="Basic Plan" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Tipe</label>
                    <input type="text" name="type" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($package['type'] ?? '') ?>" placeholder="BASIC, PRO, ENTERPRISE">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Wording / Tagline</label>
                    <input type="text" name="wording" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($package['wording'] ?? '') ?>" placeholder="Cocok untuk bisnis kecil">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Harga *</label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400 font-semibold">Rp</span>
                        <input type="number" step="0.01" name="price" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 pl-10" value="<?= $package['price'] ?? 0 ?>" required>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Currency</label>
                    <select name="currency" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50">
                        <option value="IDR" <?= ($package['currency'] ?? 'IDR') === 'IDR' ? 'selected' : '' ?>>IDR</option>
                        <option value="USD" <?= ($package['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Durasi (bulan) *</label>
                    <input type="number" min="1" name="duration_months" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= (int)($package['duration_months'] ?? 1) ?>" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quota Produk</label>
                    <input type="number" min="0" name="product_quota" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= $package['product_quota'] ?? '' ?>" placeholder="Kosongkan = unlimited">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quota Transaksi / Bulan</label>
                    <input type="number" min="0" name="transaction_monthly_quota" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= $package['transaction_monthly_quota'] ?? '' ?>" placeholder="Kosongkan = unlimited">
                </div>
                <div class="flex items-center gap-3 pt-5">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="is_active" value="1" <?= ($package['is_active'] ?? 1) ? 'checked' : '' ?> class="w-4.5 h-4.5 rounded-lg border-2 border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-all">
                    <label for="is_active" class="text-sm font-semibold text-gray-700 cursor-pointer select-none">Package Aktif</label>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <h3 class="font-bold text-gray-900 mb-4">Integrasi</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php $integrations = [
                    'integration_tiktok' => 'TikTok Shop',
                    'integration_shopee' => 'Shopee',
                    'integration_email' => 'Email',
                    'integration_moota' => 'Moota (Bank)',
                    'integration_whatsapp' => 'WhatsApp',
                ]; ?>
                <?php foreach ($integrations as $key => $label): ?>
                <label class="flex items-center gap-3 p-3 rounded-xl border-2 border-gray-200 cursor-pointer hover:border-indigo-300 transition-all">
                    <input type="hidden" name="<?= $key ?>" value="0">
                    <input type="checkbox" name="<?= $key ?>" value="1" <?= ($package[$key] ?? 0) ? 'checked' : '' ?> class="w-4.5 h-4.5 rounded border-2 border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-all">
                    <span class="text-sm font-semibold text-gray-700 select-none"><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <h3 class="font-bold text-gray-900 mb-4">Deskripsi Fitur</h3>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Fitur (satu per baris)</label>
                <textarea name="description" rows="6" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="Fitur 1"><?php
                    try {
                        $desc = $package['description'] ?? [];
                        echo is_array($desc) ? implode("\n", array_map(function($v) { return is_string($v) ? $v : (is_scalar($v) ? strval($v) : ''); }, $desc)) : '';
                    } catch (\Throwable $e) { echo ''; }
                ?></textarea>
                <p class="text-xs text-gray-400 mt-1.5">Tulis setiap fitur dalam baris terpisah</p>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm hover:shadow-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 12.75l6 6 9-13.5"/></svg>
                <?= $package ? 'Simpan Perubahan' : 'Buat Package' ?>
            </button>
            <a href="/cms/packages" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Batal</a>
        </div>
    </form>
</div>