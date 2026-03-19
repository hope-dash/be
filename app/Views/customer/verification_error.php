<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Gagal - <?= \App\Libraries\TenantContext::name() ?></title>
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

        .error-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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

        .error-icon svg {
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

        .info-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
            border-radius: 5px;
        }

        .info-box h3 {
            color: #856404;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .info-box p {
            color: #856404;
            font-size: 14px;
            line-height: 1.6;
            margin: 5px 0;
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
            margin: 10px 5px;
        }

        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .button-secondary {
            background: linear-gradient(135deg, #868f96 0%, #596164 100%);
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
        <div class="error-icon">
            <svg viewBox="0 0 24 24">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </div>

        <h1>Verifikasi Gagal</h1>

        <p class="message"><?= esc($message) ?></p>

        <div class="info-box">
            <h3>Apa yang harus dilakukan?</h3>
            <p>• Pastikan Anda menggunakan link verifikasi yang benar dari email</p>
            <p>• Link verifikasi mungkin sudah kadaluarsa (berlaku 24 jam)</p>
            <p>• Jika masalah berlanjut, silakan hubungi customer service kami</p>
        </div>

        <a href="<?= \App\Libraries\TenantContext::url() ?>" class="button">Kembali ke Beranda</a>
        <a href="https://wa.me/6288980998878" class="button button-secondary">Hubungi Support WA</a>

        <div class="footer">
            <p>&copy; <?= date('Y') ?> <?= \App\Libraries\TenantContext::name() ?>. All rights reserved.</p>
        </div>
    </div>
</body>

</html>