<?php

if (!function_exists('send_email')) {
    /**
     * Send email using CodeIgniter Email library
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email body (HTML)
     * @return bool Success status
     */
    function send_email($to, $subject, $message)
    {
        $email = \Config\Services::email();

        $config = [
            'protocol' => 'smtp',
            'SMTPHost' => env('email.SMTPHost'),
            'SMTPUser' => env('email.SMTPUser'),
            'SMTPPass' => env('email.SMTPPass'),
            'SMTPPort' => (int) env('email.SMTPPort'),
            'SMTPCrypto' => env('email.SMTPCrypto'),
            'mailType' => 'html',
            'charset' => 'utf-8',
            'newline' => "\r\n",
            'wordWrap' => true,
        ];

        $email->initialize($config);

        $email->setFrom(env('email.fromEmail'), env('email.fromName'));
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        if ($email->send()) {
            return true;
        } else {
            log_message('error', 'Email sending failed: ' . $email->printDebugger(['headers']));
            return false;
        }
    }
}

if (!function_exists('get_email_template')) {
    /**
     * Get email template HTML
     * 
     * @param string $title Email title
     * @param string $content Email content
     * @return string HTML template
     */
    function get_email_template($title, $content)
    {
        return '
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px 20px;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-weight: bold;
        }
        .footer {
            background: #f8f8f8;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Hope Sparepart</h1>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' Hope Sparepart. All rights reserved.</p>
            <p>Jika Anda memiliki pertanyaan, silakan hubungi kami di admin@hopesparepart.com</p>
        </div>
    </div>
</body>
</html>';
    }
}

if (!function_exists('send_registration_email')) {
    /**
     * Send registration success email with credentials
     * 
     * @param string $email Customer email
     * @param string $name Customer name
     * @param string $password Plain text password
     * @param string $verificationToken Verification token
     * @return bool Success status
     */
    function send_registration_email($email, $name, $password, $verificationToken)
    {
        $baseUrl = env('app.baseURL');
        $verificationUrl = $baseUrl . '/customer/verify?token=' . $verificationToken;

        $content = '
            <h2>Selamat Datang, ' . htmlspecialchars($name) . '!</h2>
            <p>Terima kasih telah mendaftar di Hope Sparepart. Akun Anda telah berhasil dibuat.</p>
            
            <div class="info-box">
                <h3>Informasi Akun Anda:</h3>
                <p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>
                <p><strong>Password:</strong> ' . htmlspecialchars($password) . '</p>
            </div>
            
            <p><strong>Penting:</strong> Silakan simpan informasi login Anda dengan aman. Kami menyimpan password Anda dalam bentuk encryption, sehingga password hanya diketahui oleh anda.</p>
            
            <p>Untuk mengaktifkan akun Anda, silakan verifikasi email Anda dengan mengklik tombol di bawah ini:</p>
            
            <center>
                <a href="' . $verificationUrl . '" class="button">Verifikasi Email Saya</a>
            </center>
            
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                Jika tombol tidak berfungsi, copy dan paste link berikut ke browser Anda:<br>
                <a href="' . $verificationUrl . '">' . $verificationUrl . '</a>
            </p>
        ';

        $html = get_email_template('Selamat Datang di Hope Sparepart', $content);
        
        return send_email($email, 'Selamat Datang di Hope Sparepart - Verifikasi Email Anda', $html);
    }
}

if (!function_exists('send_verification_email')) {
    /**
     * Send email verification link
     * 
     * @param string $email Customer email
     * @param string $name Customer name
     * @param string $verificationToken Verification token
     * @return bool Success status
     */
    function send_verification_email($email, $name, $verificationToken)
    {
        $baseUrl = env('app.baseURL');
        $verificationUrl = $baseUrl . '/customer/verify?token=' . $verificationToken;

        $content = '
            <h2>Halo, ' . htmlspecialchars($name) . '!</h2>
            <p>Silakan verifikasi alamat email Anda dengan mengklik tombol di bawah ini:</p>
            
            <center>
                <a href="' . $verificationUrl . '" class="button">Verifikasi Email Saya</a>
            </center>
            
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                Jika tombol tidak berfungsi, copy dan paste link berikut ke browser Anda:<br>
                <a href="' . $verificationUrl . '">' . $verificationUrl . '</a>
            </p>
            
            <p>Link verifikasi ini akan kadaluarsa dalam 24 jam.</p>
        ';

        $html = get_email_template('Verifikasi Email Anda', $content);
        
        return send_email($email, 'Verifikasi Email Anda - Hope Sparepart', $html);
    }
}
