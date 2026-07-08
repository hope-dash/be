<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Detail Tenant: <?= esc($tenant['name']) ?></h1>
    <div class="flex gap-2">
        <span class="px-3 py-1 rounded-full text-sm font-semibold <?= $tenant['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
            <?= $tenant['status'] ?>
        </span>
        <a href="/cms/tenants" class="text-gray-600 hover:text-gray-800"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>
</div>

<!-- Info -->
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div><span class="text-gray-500 text-sm">Code</span><p class="font-mono font-bold"><?= esc($tenant['code']) ?></p></div>
        <div><span class="text-gray-500 text-sm">Nama</span><p class="font-bold"><?= esc($tenant['name']) ?></p></div>
        <div><span class="text-gray-500 text-sm">Email</span><p><?= esc($tenant['email'] ?? '-') ?></p></div>
        <div>
            <form method="post" action="/cms/tenants/<?= $tenant['id'] ?>/toggle-status" onsubmit="return confirm('Yakin?')">
                <button type="submit" class="text-sm <?= $tenant['status'] === 'active' ? 'text-red-600' : 'text-green-600' ?> hover:underline">
                    <i class="fas fa-<?= $tenant['status'] === 'active' ? 'ban' : 'check-circle' ?>"></i>
                    <?= $tenant['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                </button>
            </form>
        </div>
        <div>
            <form method="post" action="/cms/tenants/<?= $tenant['id'] ?>/delete" onsubmit="return confirm('Hapus tenant <?= esc($tenant['name']) ?>? Semua domain akan dimatikan.')">
                <button type="submit" class="text-sm text-red-600 hover:underline">
                    <i class="fas fa-trash"></i> Hapus Tenant
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Subscriptions -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b bg-gray-50">
        <h2 class="text-lg font-bold">Subscriptions</h2>
    </div>
    <div class="p-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500">
                <th class="pb-2">Paket</th><th class="pb-2">Status</th><th class="pb-2">Mulai</th><th class="pb-2">Selesai</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php if (empty($subscriptions)): ?>
                    <tr><td colspan="4" class="py-4 text-center text-gray-400">Belum ada subscription</td></tr>
                <?php else: ?>
                    <?php foreach ($subscriptions as $s): ?>
                    <tr>
                        <td class="py-2"><?= esc($s['package_name'] ?? '-') ?></td>
                        <td class="py-2">
                            <span class="px-2 py-1 text-xs rounded-full <?= $s['status'] === 'active' ? 'bg-green-100 text-green-700' : ($s['status'] === 'expired' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') ?>">
                                <?= $s['status'] ?>
                            </span>
                        </td>
                        <td class="py-2"><?= $s['start_at'] ? date('d M Y', strtotime($s['start_at'])) : '-' ?></td>
                        <td class="py-2"><?= $s['end_at'] ? date('d M Y', strtotime($s['end_at'])) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Quota -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b bg-gray-50">
        <h2 class="text-lg font-bold">Quota Usage</h2>
    </div>
    <div class="p-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500">
                <th class="pb-2">Bulan</th><th class="pb-2">Produk</th><th class="pb-2">Transaksi</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php if (empty($quota)): ?>
                    <tr><td colspan="3" class="py-4 text-center text-gray-400">Belum ada data quota</td></tr>
                <?php else: ?>
                    <?php foreach ($quota as $q): ?>
                    <tr>
                        <td class="py-2"><?= date('M Y', strtotime($q['month_start'])) ?></td>
                        <td class="py-2"><?= ($q['product_used'] ?? 0) ?> / <?= $q['product_quota'] ?? 0 ?></td>
                        <td class="py-2"><?= ($q['transaction_monthly_used'] ?? 0) ?> / <?= $q['transaction_monthly_quota'] ?? 0 ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Tokos -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b bg-gray-50 flex items-center justify-between">
        <h2 class="text-lg font-bold">Toko</h2>
        <button onclick="document.getElementById('tokoModal').classList.remove('hidden')" class="text-sm bg-indigo-600 text-white px-3 py-1 rounded hover:bg-indigo-700">
            <i class="fas fa-plus"></i> Tambah Toko
        </button>
    </div>
    <div class="p-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500">
                <th class="pb-2">Nama</th><th class="pb-2">No. Telp</th><th class="pb-2">Email</th><th class="pb-2">Tipe</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php if (empty($tokos)): ?>
                    <tr><td colspan="4" class="py-4 text-center text-gray-400">Belum ada toko</td></tr>
                <?php else: ?>
                    <?php foreach ($tokos as $tk): ?>
                    <tr>
                        <td class="py-2"><?= esc($tk['toko_name']) ?></td>
                        <td class="py-2"><?= esc($tk['phone_number'] ?? '-') ?></td>
                        <td class="py-2"><?= esc($tk['email_toko'] ?? '-') ?></td>
                        <td class="py-2"><?= esc($tk['type'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Toko Modal -->
<div id="tokoModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h3 class="text-lg font-bold mb-4">Tambah Toko</h3>
        <form method="post" action="/cms/tenants/<?= $tenant['id'] ?>/tokos/create">
            <div class="mb-3">
                <label class="block text-sm font-bold mb-1">Nama Toko <span class="text-red-500">*</span></label>
                <input type="text" name="toko_name" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div class="mb-3">
                <label class="block text-sm font-bold mb-1">No. Telepon</label>
                <input type="text" name="phone_number" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="mb-3">
                <label class="block text-sm font-bold mb-1">Email</label>
                <input type="email" name="email" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold mb-1">Alamat</label>
                <textarea name="alamat" rows="2" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="this.closest('#tokoModal').classList.add('hidden')" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400">Batal</button>
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Domain Section (NEW) -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b bg-gray-50 flex items-center justify-between">
        <h2 class="text-lg font-bold">Domain</h2>
        <button onclick="document.getElementById('domainModal').classList.remove('hidden')" class="text-sm bg-indigo-600 text-white px-3 py-1 rounded hover:bg-indigo-700">
            <i class="fas fa-plus"></i> Tambah Domain
        </button>
    </div>
    <div class="p-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="pb-2">Domain</th>
                    <th class="pb-2">Tipe</th>
                    <th class="pb-2">Verifikasi</th>
                    <th class="pb-2 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y" id="domainTableBody">
                <?php if (empty($domains)): ?>
                    <tr id="noDomainsRow"><td colspan="4" class="py-4 text-center text-gray-400">Belum ada domain</td></tr>
                <?php else: ?>
                    <?php foreach ($domains as $d): ?>
                    <tr id="domain-row-<?= $d['id'] ?>">
                        <td class="py-2 font-mono"><?= esc($d['domain']) ?></td>
                        <td class="py-2">
                            <span class="px-2 py-1 text-xs rounded-full
                                <?= $d['type'] === 'main' ? 'bg-purple-100 text-purple-700' : ($d['type'] === 'admin' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700') ?>">
                                <?= $d['type'] ?>
                            </span>
                        </td>
                        <td class="py-2">
                            <span class="px-2 py-1 text-xs rounded-full <?= $d['is_verified'] ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?>">
                                <?= $d['is_verified'] ? 'Terverifikasi' : 'Belum Verifikasi' ?>
                            </span>
                        </td>
                        <td class="py-2 text-center">
                            <button onclick="deleteDomain(<?= $d['id'] ?>)" class="text-red-600 hover:text-red-800" title="Hapus">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Domain Modal -->
<div id="domainModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h3 class="text-lg font-bold mb-4">Tambah Domain</h3>
        <form id="domainForm">
            <div class="mb-3">
                <label class="block text-sm font-bold mb-1">Domain <span class="text-red-500">*</span></label>
                <input type="text" name="domain" placeholder="contoh.satualur.my.id" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold mb-1">Tipe <span class="text-red-500">*</span></label>
                <select name="type" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                    <option value="">Pilih Tipe</option>
                    <option value="admin">Admin</option>
                    <option value="shop">Shop</option>
                    <option value="main">Main</option>
                </select>
            </div>
            <div id="domainError" class="text-red-600 text-sm mb-3 hidden"></div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeDomainModal()" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400">Batal</button>
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Users -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b bg-gray-50 flex items-center justify-between">
        <h2 class="text-lg font-bold">Users</h2>
        <button onclick="document.getElementById('userModal').classList.remove('hidden')" class="text-sm bg-indigo-600 text-white px-3 py-1 rounded hover:bg-indigo-700">
            <i class="fas fa-plus"></i> Tambah User
        </button>
    </div>
    <div class="p-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500">
                <th class="pb-2">Nama</th><th class="pb-2">Username</th><th class="pb-2">Email</th><th class="pb-2 text-center">Aksi</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php if (empty($users)): ?>
                    <tr><td colspan="4" class="py-4 text-center text-gray-400">Belum ada user</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="py-2"><?= esc($u['name']) ?></td>
                        <td class="py-2 font-mono"><?= esc($u['username']) ?></td>
                        <td class="py-2"><?= esc($u['email'] ?? '-') ?></td>
                        <td class="py-2 text-center">
                            <button onclick="resetPassword(<?= $u['user_id'] ?>)" class="text-yellow-600 hover:text-yellow-800 mx-1" title="Reset Password">
                                <i class="fas fa-key"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- User Modal -->
<div id="userModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h3 class="text-lg font-bold mb-4">Tambah User</h3>
        <form method="post" action="/cms/tenants/<?= $tenant['id'] ?>/users/create">
            <div class="mb-3">
                <label class="block text-sm font-bold mb-1">Nama <span class="text-red-500">*</span></label>
                <input type="text" name="name" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div class="mb-3">
                <label class="block text-sm font-bold mb-1">Username <span class="text-red-500">*</span></label>
                <input type="text" name="username" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div class="mb-3">
                <label class="block text-sm font-bold mb-1">Email</label>
                <input type="email" name="email" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold mb-1">Password <span class="text-red-500">*</span></label>
                <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="this.closest('#userModal').classList.add('hidden')" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400">Batal</button>
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h3 class="text-lg font-bold mb-4">Reset Password User</h3>
        <form id="resetForm" method="post">
            <div class="mb-4">
                <label class="block text-sm font-bold mb-1">Password Baru <span class="text-red-500">*</span></label>
                <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="document.getElementById('resetModal').classList.add('hidden')" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400">Batal</button>
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetPassword(userId) {
    const form = document.getElementById('resetForm');
    form.action = '/cms/tenants/<?= $tenant['id'] ?>/users/' + userId + '/reset-password';
    document.getElementById('resetModal').classList.remove('hidden');
}

function closeDomainModal() {
    document.getElementById('domainModal').classList.add('hidden');
    document.getElementById('domainForm').reset();
    document.getElementById('domainError').classList.add('hidden');
}

document.getElementById('domainForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const formData = new FormData(form);
    const errorDiv = document.getElementById('domainError');
    errorDiv.classList.add('hidden');

    fetch('/cms/tenants/<?= $tenant['id'] ?>/domains/create', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.status) {
            location.reload();
        } else {
            errorDiv.textContent = data.message;
            errorDiv.classList.remove('hidden');
        }
    })
    .catch(() => {
        errorDiv.textContent = 'Terjadi kesalahan';
        errorDiv.classList.remove('hidden');
    });
});

function deleteDomain(domainId) {
    if (!confirm('Yakin ingin menghapus domain ini?')) return;
    fetch('/cms/tenants/<?= $tenant['id'] ?>/domains/' + domainId + '/delete', {
        method: 'POST'
    })
    .then(r => r.json())
    .then(data => {
        if (data.status) {
            const row = document.getElementById('domain-row-' + domainId);
            if (row) row.remove();
            const tbody = document.getElementById('domainTableBody');
            if (tbody && tbody.querySelectorAll('tr').length === 0) {
                tbody.innerHTML = '<tr id="noDomainsRow"><td colspan="4" class="py-4 text-center text-gray-400">Belum ada domain</td></tr>';
            }
        } else {
            alert(data.message);
        }
    });
}
</script>
