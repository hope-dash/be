<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'CMS' ?> — Satu Alur</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: { primary: { '50': '#eef2ff', '100': '#e0e7ff', '200': '#c7d2fe', '300': '#a5b4fc', '400': '#818cf8', '500': '#6366f1', '600': '#4f46e5', '700': '#4338ca', '800': '#3730a3', '900': '#312e81' } }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans antialiased text-gray-800 min-h-screen">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar Desktop -->
        <aside class="w-64 bg-white border-r border-gray-200 flex-shrink-0 hidden lg:flex flex-col">
            <div class="h-16 flex items-center gap-3 px-6 border-b border-gray-100">
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <span class="text-lg font-extrabold text-gray-900 tracking-tight">Satu Alur</span>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 py-5 space-y-1">
                <a href="/cms/dashboard" class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 <?= str_starts_with($currentUri, '/cms/dashboard') ? 'bg-indigo-50 text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                    Dashboard
                </a>
                <a href="/cms/tenants" class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 <?= str_starts_with($currentUri, '/cms/tenants') ? 'bg-indigo-50 text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                    Tenants
                </a>
                <a href="/cms/packages" class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 <?= str_starts_with($currentUri, '/cms/packages') ? 'bg-indigo-50 text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                    Packages
                </a>
                <a href="/cms/subscriptions" class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 <?= str_starts_with($currentUri, '/cms/subscriptions') ? 'bg-indigo-50 text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Subscriptions
                </a>
                <a href="/cms/orders" class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 <?= str_starts_with($currentUri, '/cms/orders') ? 'bg-indigo-50 text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                    Orders
                </a>
            </nav>
            <div class="px-4 py-4 border-t border-gray-100">
                <div class="flex items-center gap-3 px-2">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-400 to-indigo-600 flex items-center justify-center text-sm font-bold text-white shadow-sm">
                        <?= strtoupper(substr($cmsUser['name'] ?? 'A', 0, 1)) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800 truncate"><?= $cmsUser['name'] ?? 'Admin' ?></p>
                        <p class="text-xs text-gray-400 truncate"><?= $cmsUser['username'] ?? '' ?></p>
                    </div>
                    <a href="/cms/logout" class="p-2 rounded-xl text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all" title="Logout">
                        <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Mobile Header -->
        <div class="lg:hidden fixed top-0 left-0 right-0 z-30 bg-white border-b border-gray-200 h-14 flex items-center px-4 shadow-sm">
            <button id="mobileMenuBtn" class="p-2 -ml-2 rounded-xl hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </button>
            <div class="flex items-center gap-2 ml-2">
                <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center">
                    <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <span class="font-bold text-gray-900">Satu Alur</span>
            </div>
        </div>
        <div id="mobileOverlay" class="lg:hidden fixed inset-0 z-30 bg-black/30 backdrop-blur-sm hidden" onclick="toggleMobileMenu()"></div>
        <aside id="mobileSidebar" class="lg:hidden fixed top-0 left-0 bottom-0 z-40 w-72 bg-white shadow-2xl transform -translate-x-full transition-transform duration-300">
            <div class="h-14 flex items-center justify-between px-4 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <span class="font-bold text-gray-900">Satu Alur</span>
                </div>
                <button onclick="toggleMobileMenu()" class="p-2 rounded-xl hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <nav class="p-3 space-y-1">
                <?php $mobileLinks = ['/cms/dashboard' => 'Dashboard', '/cms/tenants' => 'Tenants', '/cms/packages' => 'Packages', '/cms/subscriptions' => 'Subscriptions', '/cms/orders' => 'Orders']; ?>
                <?php foreach ($mobileLinks as $url => $label): ?>
                <a href="<?= $url ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 <?= str_starts_with($currentUri, $url) ? 'bg-indigo-50 text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <!-- Main -->
        <main class="flex-1 overflow-y-auto pt-14 lg:pt-0 bg-gray-50">
            <?php if (session()->getFlashdata('success')): ?>
            <div class="mx-4 mt-4 lg:mx-6 lg:mt-6 flex items-center gap-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl px-5 py-3.5 text-sm font-medium shadow-sm">
                <svg class="w-5 h-5 flex-shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?= session()->getFlashdata('success') ?>
            </div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('error')): ?>
            <div class="mx-4 mt-4 lg:mx-6 lg:mt-6 flex items-center gap-3 bg-red-50 border border-red-200 text-red-800 rounded-2xl px-5 py-3.5 text-sm font-medium shadow-sm">
                <svg class="w-5 h-5 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <?= session()->getFlashdata('error') ?>
            </div>
            <?php endif; ?>
            <div class="p-4 lg:p-6">                <?= $content ?>
            </div>
        </main>
    </div>

    <script>
        function toggleMobileMenu() {
            document.getElementById('mobileSidebar').classList.toggle('-translate-x-full');
            document.getElementById('mobileOverlay').classList.toggle('hidden');
        }
        document.getElementById('mobileMenuBtn')?.addEventListener('click', toggleMobileMenu);
    </script>
</body>
</html>