<div class="mb-8">
    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Dashboard</h1>
    <p class="text-gray-500 mt-1">Ringkasan sistem multi-tenant</p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-5 mb-8">
    <div class="bg-white rounded-2xl border border-gray-100 p-6 hover:shadow-lg hover:border-gray-200 transition-all duration-200">
        <div class="flex items-start justify-between mb-3">
            <span class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Tenants</span>
            <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
            </div>
        </div>
        <p class="text-3xl font-extrabold text-gray-900"><?= $totalTenants ?></p>
        <div class="flex items-center gap-1.5 mt-1">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            <span class="text-sm text-gray-500"><?= $activeTenants ?> aktif</span>
        </div>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 p-6 hover:shadow-lg hover:border-gray-200 transition-all duration-200">
        <div class="flex items-start justify-between mb-3">
            <span class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Packages</span>
            <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </div>
        </div>
        <p class="text-3xl font-extrabold text-gray-900"><?= $totalPackages ?></p>
        <div class="flex items-center gap-1.5 mt-1">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            <span class="text-sm text-gray-500"><?= $activePackages ?> aktif</span>
        </div>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 p-6 hover:shadow-lg hover:border-gray-200 transition-all duration-200">
        <div class="flex items-start justify-between mb-3">
            <span class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Subs Aktif</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
        <p class="text-3xl font-extrabold text-gray-900"><?= $activeSubs ?></p>
        <p class="text-sm text-gray-500 mt-1">Berlangganan aktif</p>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 p-6 hover:shadow-lg hover:border-gray-200 transition-all duration-200">
        <div class="flex items-start justify-between mb-3">
            <span class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Pending</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
        <p class="text-3xl font-extrabold text-gray-900"><?= $pendingOrders ?></p>
        <p class="text-sm text-gray-500 mt-1">Menunggu pembayaran</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8">
    <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-900">Tenant Terbaru</h3>
            <a href="/cms/tenants" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">Lihat Semua</a>
        </div>
        <?php if ($recentTenants): ?>
        <div class="divide-y divide-gray-100">
            <?php foreach ($recentTenants as $t): ?>
            <a href="/cms/tenants/<?= $t['id'] ?>" class="flex items-center justify-between px-6 py-4 hover:bg-indigo-50/40 transition-colors">
                <div class="flex items-center gap-4">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-100 to-indigo-200 flex items-center justify-center text-sm font-bold text-indigo-700">
                        <?= strtoupper(substr($t['name'], 0, 1)) ?>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900"><?= esc($t['name']) ?></p>
                        <p class="text-xs text-gray-400"><?= esc($t['code']) ?> &middot; <?= date('d M Y', strtotime($t['created_at'])) ?></p>
                    </div>
                </div>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $t['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>"><?= $t['status'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="px-6 py-10 text-center text-sm text-gray-400">Belum ada tenant.</div>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-bold text-gray-900">Total Revenue</h3>
        </div>
        <div class="px-6 py-5">
            <p class="text-3xl font-extrabold text-gray-900">Rp <?= number_format((float)$paidRevenue, 0, ',', '.') ?></p>
            <p class="text-sm text-gray-500 mt-1">Dari semua order berstatus paid</p>
        </div>
        <div class="px-6 pb-5">
            <div class="flex items-center justify-between p-3 bg-amber-50 rounded-xl">
                <span class="text-sm font-semibold text-amber-800">Pending Orders</span>
                <span class="text-xl font-extrabold text-amber-800"><?= $pendingOrders ?></span>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-bold text-gray-900">Order Terbaru</h3>
        <a href="/cms/orders" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">Lihat Semua</a>
    </div>
    <?php if ($recentOrders): ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50/80">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tenant</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Package</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tanggal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentOrders as $o): ?>
                <tr class="border-t border-gray-100 hover:bg-indigo-50/40 transition-colors duration-150">
                    <td class="px-5 py-4 font-semibold text-gray-900"><?= esc($o['tenant_name'] ?? $o['tenant_code']) ?></td>
                    <td class="px-5 py-4 text-gray-600"><?= esc($o['package_name'] ?? '-') ?></td>
                    <td class="px-5 py-4 font-semibold">Rp <?= number_format((float)$o['amount'], 0, ',', '.') ?></td>
                    <td class="px-5 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-semibold tracking-wide <?= $o['status'] === 'paid' ? 'bg-emerald-50 text-emerald-700' : ($o['status'] === 'waiting_payment' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700') ?>"><?= $o['status'] === 'waiting_payment' ? 'Waiting' : $o['status'] ?></span></td>
                    <td class="px-5 py-4 text-gray-400 text-xs"><?= date('d M Y H:i', strtotime($o['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="px-6 py-10 text-center text-sm text-gray-400">Belum ada order.</div>
    <?php endif; ?>
</div>