<<<<<<< HEAD
<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Orders</h1>
    <p class="text-gray-500 mt-1">Semua order subscription dari tenant</p>
</div>

<form method="GET" class="mb-6">
    <div class="flex flex-wrap gap-3 items-center">
        <select name="status" class="rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm bg-white outline-none transition-all cursor-pointer hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" onchange="this.form.submit()">
            <option value="">Semua Status</option>
            <option value="waiting_payment" <?= ($status ?? '') === 'waiting_payment' ? 'selected' : '' ?>>Waiting Payment</option>
            <option value="paid" <?= ($status ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="canceled" <?= ($status ?? '') === 'canceled' ? 'selected' : '' ?>>Canceled</option>
            <option value="failed" <?= ($status ?? '') === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select>
        <?php if ($status): ?>
        <a href="/cms/orders" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Reset Filter</a>
        <?php endif; ?>
    </div>
</form>

<div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50/80">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tenant</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Package</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">External ID</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Paid At</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Dibuat</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($orders): foreach ($orders as $o): ?>
                <tr class="border-t border-gray-100 hover:bg-indigo-50/40 transition-colors duration-150">
                    <td class="px-5 py-4"><span class="font-mono text-xs font-semibold bg-gray-100 px-2 py-1 rounded-lg">#<?= $o['id'] ?></span></td>
                    <td class="px-5 py-4">
                        <a href="/cms/tenants/<?= $o['tenant_id'] ?>" class="font-semibold text-indigo-600 hover:text-indigo-700"><?= esc($o['tenant_name'] ?? $o['tenant_code']) ?></a>
                        <p class="text-xs text-gray-400"><?= esc($o['tenant_code'] ?? '') ?></p>
                    </td>
                    <td class="px-5 py-4 text-sm"><?= esc($o['package_name'] ?? '-') ?></td>
                    <td class="px-5 py-4 font-semibold">Rp <?= number_format((float)$o['amount'], 0, ',', '.') ?></td>
                    <td class="px-5 py-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $o['status'] === 'paid' ? 'bg-emerald-50 text-emerald-700' : ($o['status'] === 'waiting_payment' ? 'bg-amber-50 text-amber-700' : ($o['status'] === 'failed' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600')) ?>">
                            <?= $o['status'] === 'waiting_payment' ? 'Waiting' : $o['status'] ?>
                        </span>
                    </td>
                    <td class="px-5 py-4 text-xs font-mono text-gray-400 max-w-[100px] truncate" title="<?= esc($o['external_transaction_id'] ?? '') ?>"><?= esc($o['external_transaction_id'] ?? '-') ?></td>
                    <td class="px-5 py-4 text-xs text-gray-400"><?= $o['paid_at'] ?? '-' ?></td>
                    <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                    <td class="px-5 py-4 text-right">
                        <?php if ($o['status'] === 'waiting_payment'): ?>
                        <div class="inline-flex gap-1.5">
                            <form action="/cms/orders/<?= $o['id'] ?>/approve" method="POST" class="inline" onsubmit="return confirm('Approve pembayaran order ini? Subscription akan diaktifkan.')">
                                <button type="submit" class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    Approve
                                </button>
                            </form>
                            <form action="/cms/orders/<?= $o['id'] ?>/cancel" method="POST" class="inline" onsubmit="return confirm('Cancel order ini?')">
                                <button type="submit" class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 bg-red-600 text-white hover:bg-red-700 shadow-sm">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    Cancel
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="9" class="px-5 py-12 text-center text-gray-400">Belum ada order.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
=======
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
>>>>>>> main
