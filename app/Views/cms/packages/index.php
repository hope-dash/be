<div class="mb-6 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Packages</h1>
        <p class="text-gray-500 mt-1">Kelola paket subscription untuk tenant</p>
    </div>
    <a href="/cms/packages/create" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm hover:shadow-md mt-3 sm:mt-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Buat Package
    </a>
</div>

<div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50/80">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Kode</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Nama</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tipe</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Harga</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Durasi</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Qty Produk</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Qty Transaksi</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Integrasi</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($packages): foreach ($packages as $p): ?>
                <tr class="border-t border-gray-100 hover:bg-indigo-50/40 transition-colors duration-150">
                    <td class="px-5 py-4"><span class="font-mono text-xs font-semibold bg-gray-100 text-gray-600 px-2 py-1 rounded-lg"><?= esc($p['code']) ?></span></td>
                    <td class="px-5 py-4 font-semibold text-gray-900"><?= esc($p['name']) ?></td>
                    <td class="px-5 py-4 text-gray-500"><?= esc($p['type'] ?? '-') ?></td>
                    <td class="px-5 py-4 font-semibold"><?= $p['currency'] ?? 'IDR' ?> <?= number_format((float)$p['price'], 0, ',', '.') ?></td>
                    <td class="px-5 py-4 text-gray-600"><?= (int)$p['duration_months'] ?> bln</td>
                    <td class="px-5 py-4"><?= $p['product_quota'] === null ? '<span class="text-gray-400">∞</span>' : (int)$p['product_quota'] ?></td>
                    <td class="px-5 py-4"><?= $p['transaction_monthly_quota'] === null ? '<span class="text-gray-400">∞</span>' : (int)$p['transaction_monthly_quota'] ?></td>
                    <td class="px-5 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $p['is_active'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>"><?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td class="px-5 py-4">
                        <div class="flex gap-1.5 flex-wrap">
                            <?php $intLabels = ['integration_tiktok' => 'TT', 'integration_shopee' => 'SP', 'integration_email' => 'EM', 'integration_moota' => 'MT', 'integration_whatsapp' => 'WA']; ?>
                            <?php foreach ($intLabels as $k => $l): ?>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold <?= ($p[$k] ?? 0) ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-400' ?>"><?= $l ?></span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="px-5 py-4 text-right">
                        <div class="inline-flex gap-1.5">
                            <a href="/cms/packages/<?= $p['id'] ?>/edit" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Edit</a>
                            <form action="/cms/packages/<?= $p['id'] ?>/toggle" method="POST" class="inline" onsubmit="return confirm('Ubah status package <?= esc($p['name']) ?>?')">
                                <button type="submit" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 shadow-sm <?= $p['is_active'] ? 'bg-amber-500 text-white hover:bg-amber-600' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>">
                                    <?= $p['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10" class="px-5 py-12 text-center text-gray-400">Belum ada package.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>