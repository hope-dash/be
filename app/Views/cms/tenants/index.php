<div class="mb-6 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Tenants</h1>
        <p class="text-gray-500 mt-1">Kelola semua tenant terdaftar</p>
    </div>
    <a href="/cms/tenants/create" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm hover:shadow-md mt-3 sm:mt-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Tambah Tenant
    </a>
</div>

<form method="GET" class="mb-6 flex flex-wrap gap-3">
    <div class="relative flex-1 min-w-[200px]">
        <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input type="text" name="search" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 pl-10" placeholder="Cari nama, kode, atau email..." value="<?= esc($search ?? '') ?>">
    </div>
    <select name="status" class="rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm bg-white outline-none transition-all cursor-pointer hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50">
        <option value="">Semua Status</option>
        <option value="active" <?= ($status ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= ($status ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
    <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm hover:shadow-md">Cari</button>
    <?php if ($search || $status): ?>
    <a href="/cms/tenants" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Reset</a>
    <?php endif; ?>
</form>

<div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50/80">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Kode</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Nama</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Dibuat</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($tenants): foreach ($tenants as $t): ?>
                <tr class="border-t border-gray-100 hover:bg-indigo-50/40 transition-colors duration-150">
                    <td class="px-5 py-4"><span class="font-mono text-xs font-semibold bg-gray-100 text-gray-600 px-2 py-1 rounded-lg"><?= esc($t['code']) ?></span></td>
                    <td class="px-5 py-4 font-semibold text-gray-900"><?= esc($t['name']) ?></td>
                    <td class="px-5 py-4 text-gray-500"><?= esc($t['email'] ?? '-') ?></td>
                    <td class="px-5 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $t['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>"><?= $t['status'] ?? 'inactive' ?></span></td>
                    <td class="px-5 py-4 text-gray-400 text-xs"><?= $t['created_at'] ? date('d M Y H:i', strtotime($t['created_at'])) : '-' ?></td>
                    <td class="px-5 py-4 text-right">
                        <div class="inline-flex gap-1.5">
                            <a href="/cms/tenants/<?= $t['id'] ?>" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Detail</a>
                            <a href="/cms/tenants/<?= $t['id'] ?>/edit" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Edit</a>
                            <form action="/cms/tenants/<?= $t['id'] ?>/toggle-status" method="POST" class="inline" onsubmit="return confirm('Ubah status tenant <?= esc($t['name']) ?>?')">
                                <button type="submit" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 shadow-sm <?= $t['status'] === 'active' ? 'bg-amber-500 text-white hover:bg-amber-600' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>">
                                    <?= $t['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" class="px-5 py-12 text-center text-gray-400">Belum ada tenant.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (isset($pager) && $pager): ?>
<div class="mt-5 flex items-center justify-between">
    <p class="text-sm text-gray-500">Total: <?= count($tenants) ?> tenant</p>
    <div class="flex gap-1">
        <?= $pager->links() ?>
    </div>
</div>
<?php endif; ?>