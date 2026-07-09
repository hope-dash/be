<<<<<<< HEAD
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — Satu Alur</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>tailwind.config={theme:{extend:{fontFamily:{sans:['Inter','system-ui','sans-serif']},colors:{primary:{'50':'#eef2ff','100':'#e0e7ff','200':'#c7d2fe','300':'#a5b4fc','400':'#818cf8','500':'#6366f1','600':'#4f46e5','700':'#4338ca','800':'#3730a3','900':'#312e81'}}}}}</script>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 via-white to-indigo-50 font-sans flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-lg shadow-indigo-200 mb-5">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Satu Alur</h1>
            <p class="text-gray-500 mt-1.5 text-sm">Panel Manajemen Multi-Tenant</p>
        </div>
        <div class="bg-white rounded-2xl shadow-xl shadow-gray-200/50 border border-gray-100 p-8">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Selamat Datang</h2>
            <p class="text-sm text-gray-500 mb-6">Masuk untuk mengelola sistem</p>
            <?php if (session()->getFlashdata('error')): ?>
            <div class="mb-5 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm font-medium">
                <svg class="w-5 h-5 flex-shrink-0 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <?= session()->getFlashdata('error') ?>
            </div>
            <?php endif; ?>
            <form action="/cms/login" method="POST" class="space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Username</label>
                    <input type="text" name="username" value="admin" class="w-full rounded-xl border-2 border-gray-200 px-4 py-3 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Password</label>
                    <input type="password" name="password" value="admin123" class="w-full rounded-xl border-2 border-gray-200 px-4 py-3 text-sm outline-none transition-all bg-white hover:border-gray-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50" required>
                </div>
                <button type="submit" class="w-full bg-gradient-to-r from-indigo-600 to-indigo-700 text-white rounded-xl px-4 py-3 text-sm font-bold hover:from-indigo-700 hover:to-indigo-800 transition-all shadow-md shadow-indigo-200 hover:shadow-lg">Masuk</button>
            </form>
            <div class="mt-6 pt-5 border-t border-gray-100">
                <p class="text-xs text-gray-400 text-center">Demo: <span class="font-semibold text-gray-500">admin</span> / <span class="font-semibold text-gray-500">admin123</span></p>
            </div>
        </div>
    </div>
</body>
</html>
=======
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-900 to-purple-900">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md">
        <h2 class="text-2xl font-bold text-center mb-2">CMS Satu Alur</h2>
        <p class="text-gray-500 text-center mb-6">Silakan login untuk melanjutkan</p>

        <?php if (session()->get('error')): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= session()->get('error') ?></div>
        <?php endif; ?>

        <form method="post" action="/cms/login">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Username</label>
                <input type="text" name="username" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            </div>
            <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700 transition">Login</button>
        </form>
    </div>
</div>
>>>>>>> main
