<<<<<<< HEAD
<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Subscriptions</h1>
    <p class="text-gray-500 mt-1">Semua subscription tenant dalam sistem</p>
</div>

<form method="GET" class="mb-6">
    <div class="flex flex-wrap gap-3 items-center">
        <select name="status" class="rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm bg-white outline-none transition-all cursor-pointer hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" onchange="this.form.submit()">
            <option value="">Semua Status</option>
            <option value="active" <?= ($status ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="expired" <?= ($status ?? '') === 'expired' ? 'selected' : '' ?>>Expired</option>
            <option value="canceled" <?= ($status ?? '') === 'canceled' ? 'selected' : '' ?>>Canceled</option>
        </select>
        <?php if ($status): ?>
        <a href="/cms/subscriptions" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Reset Filter</a>
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
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Harga</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Mulai</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Berakhir</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Dibuat</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($subscriptions): foreach ($subscriptions as $s): ?>
                <tr class="border-t border-gray-100 hover:bg-indigo-50/40 transition-colors duration-150">
                    <td class="px-5 py-4"><span class="font-mono text-xs font-semibold bg-gray-100 text-gray-600 px-2 py-1 rounded-lg">#<?= $s['id'] ?></span></td>
                    <td class="px-5 py-4">
                        <a href="/cms/tenants/<?= $s['tenant_id'] ?>" class="font-semibold text-indigo-600 hover:text-indigo-700"><?= esc($s['tenant_name'] ?? $s['tenant_code']) ?></a>
                        <p class="text-xs text-gray-400"><?= esc($s['tenant_code'] ?? '') ?></p>
                    </td>
                    <td class="px-5 py-4 font-medium"><?= esc($s['package_name'] ?? '-') ?> <span class="text-gray-400 font-mono">(<?= esc($s['package_code'] ?? '') ?>)</span></td>
                    <td class="px-5 py-4 font-semibold">Rp <?= number_format((float)($s['package_price'] ?? 0), 0, ',', '.') ?></td>
                    <td class="px-5 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $s['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : ($s['status'] === 'expired' ? 'bg-gray-100 text-gray-600' : 'bg-red-50 text-red-700') ?>"><?= $s['status'] ?></span></td>
                    <td class="px-5 py-4 text-xs text-gray-500"><?= $s['start_at'] ?? '-' ?></td>
                    <td class="px-5 py-4 text-xs text-gray-500"><?= $s['end_at'] ?? '-' ?></td>
                    <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8" class="px-5 py-12 text-center text-gray-400">Belum ada subscription.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
=======
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Subscriptions</h1>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Tenant</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Paket</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Status</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Mulai</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Selesai</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php if (empty($subscriptions)): ?>
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Belum ada subscription</td></tr>
            <?php else: ?>
                <?php foreach ($subscriptions as $s): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="/cms/tenants/<?= $s['tenant_id'] ?>" class="text-indigo-600 hover:underline">
                            <?= esc($s['tenant_name'] ?? '-') ?>
                        </a>
                        <span class="text-gray-400 text-xs ml-1">(<?= esc($s['tenant_code'] ?? '-') ?>)</span>
                    </td>
                    <td class="px-4 py-3"><?= esc($s['package_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full 
                            <?= $s['status'] === 'active' ? 'bg-green-100 text-green-700' : ($s['status'] === 'expired' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') ?>">
                            <?= $s['status'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center text-sm"><?= $s['start_at'] ? date('d M Y', strtotime($s['start_at'])) : '-' ?></td>
                    <td class="px-4 py-3 text-center text-sm"><?= $s['end_at'] ? date('d M Y', strtotime($s['end_at'])) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
>>>>>>> main
