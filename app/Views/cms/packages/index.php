<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Paket Subscription</h1>
    <a href="/cms/packages/create" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">
        <i class="fas fa-plus"></i> Tambah Paket
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($packages)): ?>
        <div class="col-span-full text-center text-gray-500 py-12">Belum ada paket</div>
    <?php else: ?>
        <?php foreach ($packages as $p): ?>
        <div class="bg-white rounded-lg shadow overflow-hidden border-t-4 <?= $p['is_active'] ? 'border-indigo-500' : 'border-gray-300' ?>">
            <div class="p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-xl font-bold"><?= esc($p['name']) ?></h3>
                    <span class="px-2 py-1 text-xs rounded-full <?= $p['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                        <?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </div>
                <p class="text-3xl font-bold text-indigo-600 mb-4">Rp <?= number_format($p['price'], 0, ',', '.') ?></p>
                <ul class="space-y-2 text-sm text-gray-600">
                    <li><i class="fas fa-clock w-5 text-gray-400"></i> Durasi: <?= $p['duration_months'] ?> bulan</li>
                    <li><i class="fas fa-box w-5 text-gray-400"></i> Kuota Produk: <?= number_format($p['product_quota'] ?? 0) ?></li>
                    <li><i class="fas fa-exchange-alt w-5 text-gray-400"></i> Kuota Transaksi: <?= number_format($p['transaction_monthly_quota'] ?? 0) ?>/bulan</li>
                </ul>

                <?php
                $pFeatures = [];
                $pDetails = [];
                if (!empty($p['description'])) {
                    $decoded = json_decode($p['description'], true);
                    if (is_array($decoded)) {
                        if (isset($decoded['features'])) {
                            $pFeatures = $decoded['features'];
                            $pDetails = $decoded['details'] ?? [];
                        } else {
                            $pFeatures = $decoded;
                        }
                    }
                }
                ?>
                <?php if (!empty($pFeatures)): ?>
                <div class="mt-4 pt-4 border-t">
                    <p class="text-xs font-semibold text-gray-500 mb-2">INTEGRASI</p>
                    <div class="flex flex-wrap gap-1">
                        <?php $fieldLabels = [
                            'whatsapp' => 'WhatsApp', 'email' => 'Email',
                            'shopee' => 'Shopee', 'tiktok' => 'TikTok', 'moota' => 'Moota',
                            'multi_toko' => 'Multi Toko', 'laporan' => 'Laporan',
                            'service_on' => 'Service',
                        ]; ?>
                        <?php foreach ($pFeatures as $key): ?>
                            <span class="px-2 py-0.5 text-xs rounded bg-indigo-100 text-indigo-700">
                                <?= $fieldLabels[$key] ?? $key ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!empty($pDetails)): ?>
                <div class="mt-3">
                    <p class="text-xs font-semibold text-gray-500 mb-1">DETAIL</p>
                    <ul class="list-disc list-inside text-xs text-gray-600 space-y-0.5">
                        <?php foreach ($pDetails as $d): ?>
                        <li><?= esc($d) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="px-6 py-3 bg-gray-50 border-t flex justify-between">
                <a href="/cms/packages/<?= $p['id'] ?>/edit" class="text-yellow-600 hover:text-yellow-800"><i class="fas fa-edit"></i> Edit</a>
                <form method="post" action="/cms/packages/<?= $p['id'] ?>/toggle" onsubmit="return confirm('Yakin?')">
                    <button type="submit" class="text-<?= $p['is_active'] ? 'red' : 'green' ?>-600 hover:text-<?= $p['is_active'] ? 'red' : 'green' ?>-800">
                        <i class="fas fa-<?= $p['is_active'] ? 'ban' : 'check-circle' ?>"></i>
                        <?= $p['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
