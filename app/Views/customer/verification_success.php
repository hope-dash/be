<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Terverifikasi - <?= \App\Libraries\TenantContext::name() ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 50px 40px;
            text-align: center;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.5s ease-out 0.2s both;
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
        
        .success-icon svg {
            width: 40px;
            height: 40px;
            stroke: white;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
        
        h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 15px;
        }
        
        .message {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .customer-name {
            color: #667eea;
            font-weight: bold;
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
            border-radius: 5px;
        }
        
        .info-box h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .info-box p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .button {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            font-size: 16px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-top: 10px;
        }
        
        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #999;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">
            <svg viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        
        <h1><?= $already_verified ? 'Sudah Terverifikasi' : 'Berhasil!' ?></h1>
        
        <?php if (!$already_verified): ?>
            <p class="customer-name">Selamat datang, <?= esc($customer_name) ?>!</p>
        <?php endif; ?>
        
        <p class="message"><?= esc($message) ?></p>
        
        <?php if (!$already_verified): ?>
            <div class="info-box">
                <h3>Apa Selanjutnya?</h3>
                <p>✓ Akun Anda sudah aktif dan siap digunakan</p>
                <p>✓ Anda dapat login menggunakan email dan password yang telah dikirimkan</p>
                <p>✓ Nikmati berbagai penawaran menarik dari <?= \App\Libraries\TenantContext::name() ?></p>
            </div>
        <?php endif; ?>
        
        <a href="<?= \App\Libraries\TenantContext::url() ?>" class="button">Kembali ke Beranda</a>
        
        <div class="footer">
            <p>&copy; <?= date('Y') ?> <?= \App\Libraries\TenantContext::name() ?>. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
