<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS - Satu Alur</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php if (session()->get('cms_logged_in')): ?>
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside id="sidebar" class="w-64 bg-indigo-800 text-white flex-shrink-0 hidden md:block">
            <div class="p-4 border-b border-indigo-700">
                <h1 class="text-xl font-bold">Satu Alur CMS</h1>
            </div>
            <nav class="p-4 space-y-1">
                <a href="/cms/dashboard" class="flex items-center gap-3 px-3 py-2 rounded hover:bg-indigo-700 <?= current_url() === site_url('cms/dashboard') ? 'bg-indigo-700' : '' ?>">
                    <i class="fas fa-home w-5"></i> Dashboard
                </a>
                <a href="/cms/tenants" class="flex items-center gap-3 px-3 py-2 rounded hover:bg-indigo-700 <?= strpos(current_url(), '/cms/tenants') !== false ? 'bg-indigo-700' : '' ?>">
                    <i class="fas fa-building w-5"></i> Tenants
                </a>
                <a href="/cms/packages" class="flex items-center gap-3 px-3 py-2 rounded hover:bg-indigo-700 <?= strpos(current_url(), '/cms/packages') !== false ? 'bg-indigo-700' : '' ?>">
                    <i class="fas fa-box w-5"></i> Paket
                </a>
                <a href="/cms/subscriptions" class="flex items-center gap-3 px-3 py-2 rounded hover:bg-indigo-700 <?= strpos(current_url(), '/cms/subscriptions') !== false ? 'bg-indigo-700' : '' ?>">
                    <i class="fas fa-file-invoice w-5"></i> Subscriptions
                </a>
                <a href="/cms/orders" class="flex items-center gap-3 px-3 py-2 rounded hover:bg-indigo-700 <?= strpos(current_url(), '/cms/orders') !== false ? 'bg-indigo-700' : '' ?>">
                    <i class="fas fa-shopping-cart w-5"></i> Orders
                </a>
                <hr class="border-indigo-700 my-4">
                <a href="/cms/logout" class="flex items-center gap-3 px-3 py-2 rounded hover:bg-red-700 text-red-200">
                    <i class="fas fa-sign-out-alt w-5"></i> Logout
                </a>
            </nav>
        </aside>

        <!-- Mobile menu button -->
        <div class="md:hidden fixed top-0 left-0 right-0 bg-indigo-800 text-white p-3 z-50 flex items-center justify-between">
            <h1 class="font-bold">Satu Alur CMS</h1>
            <button onclick="document.getElementById('mobileMenu').classList.toggle('hidden')" class="text-white">
                <i class="fas fa-bars text-xl"></i>
            </button>
        </div>

        <!-- Mobile menu -->
        <div id="mobileMenu" class="hidden fixed inset-0 bg-indigo-900 z-40 pt-14">
            <nav class="p-4 space-y-1">
                <a href="/cms/dashboard" class="block px-3 py-2 text-white hover:bg-indigo-700 rounded">Dashboard</a>
                <a href="/cms/tenants" class="block px-3 py-2 text-white hover:bg-indigo-700 rounded">Tenants</a>
                <a href="/cms/packages" class="block px-3 py-2 text-white hover:bg-indigo-700 rounded">Paket</a>
                <a href="/cms/subscriptions" class="block px-3 py-2 text-white hover:bg-indigo-700 rounded">Subscriptions</a>
                <a href="/cms/orders" class="block px-3 py-2 text-white hover:bg-indigo-700 rounded">Orders</a>
                <hr class="border-indigo-700 my-4">
                <a href="/cms/logout" class="block px-3 py-2 text-red-300 hover:bg-red-700 rounded">Logout</a>
            </nav>
        </div>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto <?= session()->get('cms_logged_in') ? 'pt-14 md:pt-0' : '' ?>">
            <div class="p-6">
                <?php if (session()->get('success')): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?= session()->get('success') ?></div>
                <?php endif; ?>
                <?php if (session()->get('error')): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= session()->get('error') ?></div>
                <?php endif; ?>
                <?= $content ?>
            </div>
        </main>
    </div>
    <?php else: ?>
        <?= $content ?>
    <?php endif; ?>
</body>
</html>
