<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Tenants</h1>
    <a href="/cms/tenants/create" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">
        <i class="fas fa-plus"></i> Tambah Tenant
    </a>
</div>

<div class="bg-white rounded-lg shadow mb-6">
    <form method="get" class="p-4 flex flex-wrap gap-4">
        <input type="text" name="search" placeholder="Cari nama, code, atau email..." value="<?= esc($_GET['search'] ?? '') ?>" class="flex-1 min-w-[200px] px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Semua Status</option>
            <option value="active" <?= (($_GET['status'] ?? '') === 'active') ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= (($_GET['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700">Filter</button>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Code</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Nama</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Email</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Status</th>
                <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php if (empty($tenants)): ?>
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Belum ada tenant</td></tr>
            <?php else: ?>
                <?php foreach ($tenants as $t): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-sm"><?= esc($t['code']) ?></td>
                    <td class="px-4 py-3"><?= esc($t['name']) ?></td>
                    <td class="px-4 py-3 text-sm"><?= esc($t['email'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full <?= $t['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                            <?= $t['status'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="/cms/tenants/<?= $t['id'] ?>" class="text-indigo-600 hover:text-indigo-800 mx-1" title="Detail"><i class="fas fa-eye"></i></a>
                        <a href="/cms/tenants/<?= $t['id'] ?>/edit" class="text-yellow-600 hover:text-yellow-800 mx-1" title="Edit"><i class="fas fa-edit"></i></a>
                        <form method="post" action="/cms/tenants/<?= $t['id'] ?>/toggle-status" class="inline" onsubmit="return confirm('Yakin ingin mengubah status tenant ini?')">
                            <button type="submit" class="text-<?= $t['status'] === 'active' ? 'red' : 'green' ?>-600 hover:text-<?= $t['status'] === 'active' ? 'red' : 'green' ?>-800 mx-1" title="<?= $t['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                <i class="fas fa-<?= $t['status'] === 'active' ? 'ban' : 'check-circle' ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
