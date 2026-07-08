<h1 class="text-2xl font-bold mb-6">Dashboard</h1>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">Total Tenants</p>
                <p class="text-3xl font-bold text-indigo-600"><?= $totalTenants ?></p>
            </div>
            <div class="bg-indigo-100 p-3 rounded-full">
                <i class="fas fa-building text-indigo-600 text-xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">Tenants Aktif</p>
                <p class="text-3xl font-bold text-green-600"><?= $activeTenants ?></p>
            </div>
            <div class="bg-green-100 p-3 rounded-full">
                <i class="fas fa-check-circle text-green-600 text-xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">Total Paket</p>
                <p class="text-3xl font-bold text-blue-600"><?= $totalPackages ?></p>
            </div>
            <div class="bg-blue-100 p-3 rounded-full">
                <i class="fas fa-box text-blue-600 text-xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">Total Orders</p>
                <p class="text-3xl font-bold text-purple-600"><?= $totalOrders ?></p>
            </div>
            <div class="bg-purple-100 p-3 rounded-full">
                <i class="fas fa-shopping-cart text-purple-600 text-xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">Orders Pending</p>
                <p class="text-3xl font-bold text-yellow-600"><?= $pendingOrders ?></p>
            </div>
            <div class="bg-yellow-100 p-3 rounded-full">
                <i class="fas fa-clock text-yellow-600 text-xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">Subscriptions Aktif</p>
                <p class="text-3xl font-bold text-teal-600"><?= $activeSubscriptions ?></p>
            </div>
            <div class="bg-teal-100 p-3 rounded-full">
                <i class="fas fa-file-invoice text-teal-600 text-xl"></i>
            </div>
        </div>
    </div>
</div>
