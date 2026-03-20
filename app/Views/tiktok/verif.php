<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrasi TikTok Shop - <?= (isset($status) && $status === 'success') ? 'Success' : 'Gagal' ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f6f8;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }

        .icon {
            font-size: 60px;
            margin-bottom: 20px;
            color: <?= (isset($status) && $status === 'success') ? '#ff0050' : '#e74c3c' ?>;
        }

        h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 20px;
        }

        p {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .button {
            display: inline-block;
            padding: 15px 30px;
            background: #000;
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            transition: transform 0.2s;
        }

        .button:hover {
            transform: scale(1.05);
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="icon"><?= (isset($status) && $status === 'success') ? '✓' : '✗' ?></div>
        <h1><?= esc($message ?? 'Integrasi TikTok Shop') ?></h1>
        
        <?php if (isset($status) && $status === 'success' && isset($toko)): ?>
            <p>Toko <strong><?= esc($toko['toko_name']) ?></strong> telah berhasil terhubung dengan akun TikTok Shop Anda.</p>
        <?php endif; ?>

        <p>Silakan tutup jendela ini atau kembali ke panel admin Anda.</p>

        <center>
            <a href="https://hopesparepart.com/admin/settings/toko" class="button">Kembali ke Admin</a>
        </center>
    </div>
</body>

</html>