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
