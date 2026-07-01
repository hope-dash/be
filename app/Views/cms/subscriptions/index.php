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