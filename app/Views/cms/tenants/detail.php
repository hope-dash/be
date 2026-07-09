<div class="mb-6 sm:flex sm:items-center sm:justify-between">
    <div class="flex items-center gap-4">
        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-100 to-indigo-200 flex items-center justify-center text-2xl font-bold text-indigo-700 shadow-sm">
            <?= strtoupper(substr($tenant['name'], 0, 1)) ?>
        </div>
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight"><?= esc($tenant['name']) ?></h1>
            <p class="text-sm text-gray-500 mt-0.5">
                <span class="font-mono bg-gray-100 px-2 py-0.5 rounded-lg text-xs font-semibold"><?= esc($tenant['code']) ?></span>
                &middot; Dibuat <?= $tenant['created_at'] ? date('d M Y', strtotime($tenant['created_at'])) : '-' ?>
            </p>
        </div>
    </div>
    <div class="flex gap-2 mt-3 sm:mt-0">
        <a href="/cms/tenants/<?= $tenant['id'] ?>/edit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Edit</a>
        <form action="/cms/tenants/<?= $tenant['id'] ?>/toggle-status" method="POST" class="inline" onsubmit="return confirm('Ubah status tenant <?= esc($tenant['name']) ?>?')">
            <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 shadow-sm <?= $tenant['status'] === 'active' ? 'bg-amber-500 text-white hover:bg-amber-600' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>">
                <?= $tenant['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
            </button>        </form>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-gray-100 p-6 flex items-center gap-4">
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $tenant['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>"><?= $tenant['status'] ?? 'inactive' ?></span>
        <div><p class="text-xs text-gray-400 uppercase tracking-wide font-semibold">Status</p></div>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 p-6">
        <p class="text-xs text-gray-400 uppercase tracking-wide font-semibold">Email</p>
        <p class="text-sm font-semibold text-gray-900 mt-1"><?= esc($tenant['email'] ?? '-') ?></p>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 p-6">
        <p class="text-xs text-gray-400 uppercase tracking-wide font-semibold">URL</p>
        <p class="text-sm font-semibold text-gray-900 mt-1 truncate"><?= $tenant['url'] ? esc($tenant['url']) : '-' ?></p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-900">Subscription Aktif</h3></div>
        <div class="p-6">
            <?php if ($activeSub): ?>
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 bg-indigo-50 rounded-xl">
                    <span class="text-sm text-indigo-700 font-medium">Package</span>
                    <span class="text-sm font-bold text-indigo-800"><?= esc($activeSub['package_name']) ?> (<?= esc($activeSub['package_code']) ?>)</span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-3 bg-gray-50 rounded-xl">
                        <p class="text-xs text-gray-400 font-semibold uppercase">Status</p>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide bg-emerald-50 text-emerald-700 mt-1"><?= $activeSub['status'] ?></span>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-xl">
                        <p class="text-xs text-gray-400 font-semibold uppercase">Harga</p>
                        <p class="font-bold text-gray-900 mt-1">Rp <?= number_format((float)$activeSub['package_price'], 0, ',', '.') ?></p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-xl">
                        <p class="text-xs text-gray-400 font-semibold uppercase">Mulai</p>
                        <p class="text-sm font-semibold text-gray-900 mt-1"><?= $activeSub['start_at'] ?? '-' ?></p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-xl">
                        <p class="text-xs text-gray-400 font-semibold uppercase">Berakhir</p>
                        <p class="text-sm font-semibold text-gray-900 mt-1"><?= $activeSub['end_at'] ?? '-' ?></p>
                    </div>
                    <?php if ($activeSub): ?>
                    <div class="mt-3 pt-3 border-t border-gray-100">
                        <p class="text-xs text-gray-400 font-semibold uppercase mb-2">Integrasi Aktif</p>
                        <div class="flex gap-2 flex-wrap">
                            <?php $intMap = ['integration_tiktok' => 'TikTok', 'integration_shopee' => 'Shopee', 'integration_email' => 'Email', 'integration_moota' => 'Moota', 'integration_whatsapp' => 'WA']; ?>
                            <?php foreach ($intMap as $k => $l): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold <?= ($activeSub[$k] ?? 0) ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-400' ?>">
                                <?= ($activeSub[$k] ?? 0) ? '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4.5 12.75l6 6 9-13.5"/></svg>' : '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>' ?>
                                <?= $l ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <p class="text-sm text-gray-400 text-center py-6">Tidak ada subscription aktif.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-900">Riwayat Quota</h3></div>
        <div class="overflow-x-auto">
            <?php if ($quotaRows): ?>
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Bulan</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Produk</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Transaksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotaRows as $q): ?>
                    <tr class="border-t border-gray-100 hover:bg-indigo-50/40">
                        <td class="px-5 py-4 font-semibold text-gray-900 text-xs"><?= date('M Y', strtotime($q['month_start'])) ?></td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden w-24">
                                    <div class="bg-indigo-500 h-2 rounded-full" style="width: <?= ($q['product_quota'] ? min(100, ((int)$q['product_used'] / (int)$q['product_quota']) * 100) : 0) ?>%"></div>
                                </div>
                                <span class="text-xs font-medium text-gray-600"><?= ($q['product_used'] ?? 0) ?>/<?= $q['product_quota'] ?? '∞' ?></span>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden w-24">
                                    <div class="bg-emerald-500 h-2 rounded-full" style="width: <?= ($q['transaction_monthly_quota'] ? min(100, ((int)$q['transaction_monthly_used'] / (int)$q['transaction_monthly_quota']) * 100) : 0) ?>%"></div>
                                </div>
                                <span class="text-xs font-medium text-gray-600"><?= ($q['transaction_monthly_used'] ?? 0) ?>/<?= $q['transaction_monthly_quota'] ?? '∞' ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-sm text-gray-400 text-center py-6">Belum ada data quota.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="space-y-5">
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-900">Riwayat Subscription</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Package</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Mulai</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Berakhir</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Dibuat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($subscriptions): foreach ($subscriptions as $s): ?>
                    <tr class="border-t border-gray-100 hover:bg-indigo-50/40">
                        <td class="px-5 py-4 font-semibold text-gray-900"><?= esc($s['package_name']) ?> <span class="text-gray-400 font-mono">(<?= esc($s['package_code']) ?>)</span></td>
                        <td class="px-5 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $s['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : ($s['status'] === 'expired' ? 'bg-gray-100 text-gray-600' : 'bg-red-50 text-red-700') ?>"><?= $s['status'] ?></span></td>
                        <td class="px-5 py-4 text-gray-500 text-xs"><?= $s['start_at'] ?? '-' ?></td>
                        <td class="px-5 py-4 text-gray-500 text-xs"><?= $s['end_at'] ?? '-' ?></td>
                        <td class="px-5 py-4 text-gray-400 text-xs"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">Belum ada riwayat subscription.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-900">Orders</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Package</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">External ID</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Paid At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders): foreach ($orders as $o): ?>
                    <tr class="border-t border-gray-100 hover:bg-indigo-50/40">
                        <td class="px-5 py-4"><span class="font-mono text-xs font-semibold bg-gray-100 px-2 py-0.5 rounded-lg">#<?= $o['id'] ?></span></td>
                        <td class="px-5 py-4 font-medium"><?= esc($o['package_name'] ?? '-') ?></td>
                        <td class="px-5 py-4 font-semibold">Rp <?= number_format((float)$o['amount'], 0, ',', '.') ?></td>
                        <td class="px-5 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $o['status'] === 'paid' ? 'bg-emerald-50 text-emerald-700' : ($o['status'] === 'waiting_payment' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700') ?>"><?= $o['status'] ?></span></td>
                        <td class="px-5 py-4 text-xs font-mono text-gray-400 max-w-[120px] truncate"><?= esc($o['external_transaction_id'] ?? '-') ?></td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= $o['paid_at'] ?? '-' ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6" class="px-5 py-8 text-center text-gray-400">Belum ada order.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Toko -->
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-900">Toko / Cabang</h3>
            <button onclick="document.getElementById('createTokoModal').classList.remove('hidden')" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Tambah Toko
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Nama</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Alamat</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Telepon</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tipe</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Dibuat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tokos): foreach ($tokos as $t): ?>
                    <tr class="border-t border-gray-100 hover:bg-indigo-50/40">
                        <td class="px-5 py-4 font-semibold text-gray-900"><?= esc($t['toko_name']) ?></td>
                        <td class="px-5 py-4 text-gray-500 max-w-[200px] truncate"><?= esc($t['alamat'] ?? '-') ?></td>
                        <td class="px-5 py-4 text-gray-500"><?= esc($t['phone_number'] ?? '-') ?></td>
                        <td class="px-5 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide bg-indigo-50 text-indigo-700"><?= esc($t['type'] ?? 'CABANG') ?></span></td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= $t['created_at'] ? date('d M Y', strtotime($t['created_at'])) : '-' ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">Belum ada toko.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Users -->
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-900">Akun User</h3>
            <button onclick="document.getElementById('createUserModal').classList.remove('hidden')" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Tambah Akun
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Nama</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Username</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Dibuat</th>
                        <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users): foreach ($users as $u): ?>
                    <tr class="border-t border-gray-100 hover:bg-indigo-50/40">
                        <td class="px-5 py-4 font-semibold text-gray-900"><?= esc($u['name']) ?></td>
                        <td class="px-5 py-4 font-mono text-sm"><?= esc($u['username']) ?></td>
                        <td class="px-5 py-4 text-gray-500"><?= esc($u['email'] ?? '-') ?></td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= $u['created_at'] ? date('d M Y', strtotime($u['created_at'])) : '-' ?></td>
                        <td class="px-5 py-4 text-right">
                            <form action="/cms/tenants/<?= $tenant['id'] ?>/users/<?= $u['user_id'] ?>/reset-password" method="POST" class="inline" onsubmit="return confirm('Reset password untuk user <?= esc($u['name']) ?> menjadi password123?')">
                                <button type="submit" class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Reset Password</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">Belum ada akun user.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Create User -->
<div id="createUserModal" class="fixed inset-0 z-50 bg-black/30 backdrop-blur-sm hidden flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-gray-900">Tambah Akun User</h3>
            <button onclick="document.getElementById('createUserModal').classList.add('hidden')" class="p-2 rounded-xl hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form action="/cms/tenants/<?= $tenant['id'] ?>/users/create" method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Nama *</label>
                <input type="text" name="name" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="Nama lengkap" required>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Username *</label>
                <input type="text" name="username" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="username" required>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Email</label>
                <input type="email" name="email" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="email@example.com">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Password *</label>
                <input type="text" name="password" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="password" required>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm hover:shadow-md">Simpan</button>
                <button type="button" onclick="document.getElementById('createUserModal').classList.add('hidden')" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Batal</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Modal Create Toko -->
<div id="createTokoModal" class="fixed inset-0 z-50 bg-black/30 backdrop-blur-sm hidden flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-gray-900">Tambah Toko / Cabang</h3>
            <button onclick="document.getElementById('createTokoModal').classList.add('hidden')" class="p-2 rounded-xl hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form action="/cms/tenants/<?= $tenant['id'] ?>/tokos/create" method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Nama Toko *</label>
                <input type="text" name="toko_name" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="Nama toko" required>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Alamat</label>
                <textarea name="alamat" rows="2" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="Alamat lengkap"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Telepon</label>
                    <input type="text" name="phone_number" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="08xxxxxxxxxx">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Email</label>
                    <input type="email" name="email_toko" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="toko@email.com">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Provinsi</label>
                    <input type="text" name="provinsi" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Kota</label>
                    <input type="text" name="kota_kabupaten" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Kecamatan</label>
                    <input type="text" name="kecamatan" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Kode Pos</label>
                    <input type="text" name="kode_pos" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm hover:shadow-md">Simpan</button>
                <button type="button" onclick="document.getElementById('createTokoModal').classList.add('hidden')" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Batal</button>            </div>
        </form>
    </div>
</div>

<div class="mt-6">
    <a href="/cms/tenants" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
        Kembali
    </a>
</div>