<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Orders</h1>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Tenant</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Paket</th>
                <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">Amount</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Status</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Tgl Order</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php if (empty($orders)): ?>
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Belum ada order</td></tr>
            <?php else: ?>
                <?php foreach ($orders as $o): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="/cms/tenants/<?= $o['tenant_id'] ?>" class="text-indigo-600 hover:underline">
                            <?= esc($o['tenant_name'] ?? '-') ?>
                        </a>
                        <span class="text-gray-400 text-xs ml-1">(<?= esc($o['tenant_code'] ?? '-') ?>)</span>
                    </td>
                    <td class="px-4 py-3"><?= esc($o['package_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-right">Rp <?= number_format($o['amount'] ?? 0, 0, ',', '.') ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full 
                            <?= $o['status'] === 'paid' ? 'bg-green-100 text-green-700' : ($o['status'] === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') ?>">
                            <?= $o['status'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center text-sm"><?= date('d M Y H:i', strtotime($o['created_at'])) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($o['status'] === 'waiting_payment'): ?>
                            <form method="post" action="/cms/orders/<?= $o['id'] ?>/approve" class="inline" onsubmit="return confirm('Setujui order ini? Subscription akan otomatis dibuat.')">
                                <button type="submit" class="text-green-600 hover:text-green-800 mx-1" title="Setujui"><i class="fas fa-check"></i></button>
                            </form>
                            <form method="post" action="/cms/orders/<?= $o['id'] ?>/cancel" class="inline" onsubmit="return confirm('Batalkan order ini?')">
                                <button type="submit" class="text-red-600 hover:text-red-800 mx-1" title="Tolak"><i class="fas fa-times"></i></button>
                            </form>
                        <?php else: ?>
                            <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
