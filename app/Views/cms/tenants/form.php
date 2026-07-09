<<<<<<< HEAD
<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight"><?= $title ?></h1>
    <p class="text-gray-500 mt-1"><?= $tenant ? 'Edit data tenant yang sudah ada' : 'Buat tenant baru dalam sistem' ?></p>
</div>

<div class="max-w-2xl">
    <form action="<?= $tenant ? '/cms/tenants/' . $tenant['id'] . '/edit' : '/cms/tenants/create' ?>" method="POST" class="space-y-6">
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <h3 class="font-bold text-gray-900 mb-4">Informasi Tenant</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Kode Tenant *</label>
                    <input type="text" name="code" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 <?= $tenant ? 'bg-gray-50 cursor-not-allowed' : '' ?>" value="<?= esc($tenant['code'] ?? '') ?>" <?= $tenant ? 'readonly' : '' ?> required>
                    <?php if (!$tenant): ?><p class="text-xs text-gray-400 mt-1.5">Identifier unik, tidak bisa diubah setelah dibuat</p><?php endif; ?>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Nama Tenant *</label>
                    <input type="text" name="name" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($tenant['name'] ?? '') ?>" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Email</label>
                    <input type="email" name="email" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($tenant['email'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Telepon</label>
                    <input type="text" name="phone_number" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($tenant['phone_number'] ?? '') ?>" placeholder="08xxxxxxxxxx">
                </div>
                <?php if (!$tenant): ?>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Alamat</label>
                    <textarea name="alamat" rows="2" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="Alamat tenant / toko"><?= esc($tenant['alamat'] ?? '') ?></textarea>
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">URL</label>
                    <input type="url" name="url" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($tenant['url'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Logo URL</label>
                    <input type="text" name="logo_url" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" value="<?= esc($tenant['logo_url'] ?? '') ?>" placeholder="https://example.com/logo.png">
                </div>
                <?php if (!$tenant): ?>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Status</label>
                    <select name="status" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$tenant): ?>
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <h3 class="font-bold text-gray-900 mb-4">Akun Admin</h3>
            <p class="text-sm text-gray-500 mb-4">Akun admin akan dibuat otomatis untuk mengelola tenant ini.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Nama PIC / Admin *</label>
                    <input type="text" name="pic_name" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="Nama lengkap admin" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Username *</label>
                    <input type="text" name="username" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="username" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Password *</label>
                    <input type="text" name="password" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" placeholder="password" required>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm hover:shadow-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 12.75l6 6 9-13.5"/></svg>
                <?= $tenant ? 'Simpan Perubahan' : 'Buat Tenant' ?>
            </button>
            <a href="/cms/tenants" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-xl transition-all duration-200 border-2 border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-800 hover:bg-gray-50">Batal</a>
        </div>
    </form>
</div>
=======
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold"><?= $tenant ? 'Edit Tenant' : 'Tambah Tenant Baru' ?></h1>
    <a href="/cms/tenants" class="text-gray-600 hover:text-gray-800"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <form method="post" action="<?= $tenant ? '/cms/tenants/' . $tenant['id'] . '/edit' : '/cms/tenants/create' ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="block text-gray-700 text-sm font-bold mb-2">Nama Toko <span class="text-red-500">*</span></label>
                <input type="text" name="toko_name" value="<?= esc($tenant['name'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>

            <?php if (!$tenant): ?>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Nama PIC <span class="text-red-500">*</span></label>
                <input type="text" name="pic_name" value="<?= esc(old('pic_name')) ?>" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Username <span class="text-red-500">*</span></label>
                <input type="text" name="username" value="<?= esc(old('username')) ?>" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Password <span class="text-red-500">*</span></label>
                <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                <input type="email" name="email" value="<?= esc($tenant['email'] ?? old('email')) ?>" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <?php if (!$tenant): ?>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">No. Telepon</label>
                <input type="text" name="phone_number" value="<?= esc(old('phone_number')) ?>" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="md:col-span-2">
                <label class="block text-gray-700 text-sm font-bold mb-2">Alamat</label>
                <textarea name="alamat" rows="2" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?= esc(old('alamat')) ?></textarea>
            </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700">
                <?= $tenant ? 'Simpan Perubahan' : 'Buat Tenant' ?>
            </button>
            <a href="/cms/tenants" class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400">Batal</a>
        </div>
    </form>
</div>
>>>>>>> main
