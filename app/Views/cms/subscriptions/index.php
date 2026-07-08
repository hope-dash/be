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
