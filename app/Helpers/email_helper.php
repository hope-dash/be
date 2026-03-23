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

        $senderEmail = env('email.SMTPUser');
        $senderName = \App\Libraries\TenantContext::name();

        $email->setFrom($senderEmail, $senderName);
        $email->setReplyTo(\App\Libraries\TenantContext::email(), $senderName);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        log_message('debug', '[send_email] From: ' . $senderEmail . ' Name: "' . $senderName . '" To: ' . $to);

        if ($email->send()) {
            return true;
        } else {
            log_message('error', 'Email sending failed: ' . $email->printDebugger(['headers']));
            return false;
        }
    }
}

if (!function_exists('send_system_email')) {
    /**
     * Send email using Super Admin sender (from ENV)
     * Optional $fromEmail and $fromName can be used to mask the sender (e.g. as a Tenant)
     */
    function send_system_email($to, $subject, $message, $fromEmail = null, $fromName = null)
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

        $fromEmail = env('email.SMTPUser');
        $fromName = $fromName ?? env('APP_NAME', 'UMKM HEBAT');

        $email->setFrom($fromEmail, $fromName);
        $email->setReplyTo(env('email.replyTo', $fromEmail), $fromName);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        if ($email->send()) {
            return true;
        } else {
            log_message('error', 'System Email sending failed: ' . $email->printDebugger(['headers']));
            return false;
        }
    }
}

if (!function_exists('send_email_with_attachments')) {
    /**
     * Send email with file attachments (local filesystem paths).
     *
     * @param string $to
     * @param string $subject
     * @param string $message HTML
     * @param array<int,string> $attachments
     * @return bool
     */
    function send_email_with_attachments($to, $subject, $message, array $attachments = [])
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

        $senderName = \App\Libraries\TenantContext::name();
        $email->setFrom(env('email.SMTPUser'), $senderName);
        $email->setReplyTo(\App\Libraries\TenantContext::email(), $senderName);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        foreach ($attachments as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                $email->attach($path);
            }
        }

        if ($email->send()) {
            return true;
        }

        log_message('error', 'Email sending failed: ' . $email->printDebugger(['headers']));
        return false;
    }
}

if (!function_exists('send_system_email_with_attachments')) {
    /**
     * Send email with attachments using Super Admin sender (from ENV)
     * Optional $fromEmail and $fromName can be used to mask the sender (e.g. as a Tenant)
     */
    function send_system_email_with_attachments($to, $subject, $message, array $attachments = [], $fromEmail = null, $fromName = null)
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

        $fromEmail = env('email.SMTPUser');
        $fromName = $fromName ?? env('APP_NAME', 'UMKM HEBAT');

        $email->setFrom($fromEmail, $fromName);
        $email->setReplyTo(env('email.replyTo', $fromEmail), $fromName);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        foreach ($attachments as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                $email->attach($path);
            }
        }

        if ($email->send()) {
            return true;
        }

        log_message('error', 'System Email sending failed: ' . $email->printDebugger(['headers']));
        return false;
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
    function get_email_template($title, $content, $customTitle = null)
    {
        $displayTitle = $customTitle ?? \App\Libraries\TenantContext::name();
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
            color: #ffffff !important;
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
            <h1>' . $displayTitle . '</h1>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' ' . $displayTitle . '. All rights reserved.</p>
            <p style="font-size: 10px; color: #999;">Sent via ' . \App\Libraries\TenantContext::name() . ' (ID: ' . \App\Libraries\TenantContext::id() . ')</p>
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
        $verificationUrl = $baseUrl . '/api/customer/verify?token=' . $verificationToken;

        $content = '
            <h2>Selamat Datang, ' . htmlspecialchars($name) . '!</h2>
            <p>Terima kasih telah mendaftar di ' . \App\Libraries\TenantContext::name() . '. Akun Anda telah berhasil dibuat.</p>
            
            <div class="info-box">
                <h3>Informasi Akun Anda:</h3>
                <p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>
                <p><strong>Password:</strong> ' . htmlspecialchars($password) . '</p>
            </div>
            
            <p><strong>Penting:</strong> Silakan simpan informasi login Anda dengan aman. Kami menyimpan password Anda dalam bentuk encryption, sehingga password hanya diketahui oleh anda.</p>
            
            <p>Untuk mengaktifkan akun Anda, silakan verifikasi email Anda dengan mengklik tombol di bawah ini:</p>
            
            <center>
                <a href="' . $verificationUrl . '" class="button" style="color: #ffffff;">Verifikasi Email Saya</a>
            </center>
            
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                Jika tombol tidak berfungsi, copy dan paste link berikut ke browser Anda:<br>
                <a href="' . $verificationUrl . '">' . $verificationUrl . '</a>
            </p>
        ';

        $html = get_email_template('Selamat Datang di ' . \App\Libraries\TenantContext::name(), $content);

        return send_email($email, 'Selamat Datang di ' . \App\Libraries\TenantContext::name() . ' - Verifikasi Email Anda', $html);
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
        $verificationUrl = $baseUrl . '/api/customer/verify?token=' . $verificationToken;

        $content = '
            <h2>Halo, ' . htmlspecialchars($name) . '!</h2>
            <p>Silakan verifikasi alamat email Anda dengan mengklik tombol di bawah ini:</p>
            
            <center>
                <a href="' . $verificationUrl . '" class="button" style="color: #ffffff;">Verifikasi Email Saya</a>
            </center>
            
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                Jika tombol tidak berfungsi, copy dan paste link berikut ke browser Anda:<br>
                <a href="' . $verificationUrl . '">' . $verificationUrl . '</a>
            </p>
            
            <p>Link verifikasi ini akan kadaluarsa dalam 24 jam.</p>
        ';

        $html = get_email_template('Verifikasi Email Anda', $content);

        return send_email($email, 'Verifikasi Email Anda - ' . \App\Libraries\TenantContext::name(), $html);
    }
}

if (!function_exists('enqueue_email')) {
    /**
     * Add an email to the queue for asynchronous sending
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email body (HTML)
     * @return bool Success status
     */
    function enqueue_email($to, $subject, $message)
    {
        $emailQueueModel = new \App\Models\EmailQueueModel();
        return $emailQueueModel->insert([
            'recipient' => $to,
            'subject' => $subject,
            'message' => $message,
            'status' => 'PENDING'
        ]);
    }
}

if (!function_exists('send_invoice_email')) {
    /**
     * Enqueue invoice and payment instructions email
     * 
     * @param array $transaction Transaction data including customer and items
     * @return bool Success status
     */
    function send_invoice_email($transaction)
    {
        if (empty($transaction['customer']['email'])) {
            return false;
        }

        $email = $transaction['customer']['email'];
        $name = $transaction['customer']['nama_customer'];
        $invoiceDisplay = $transaction['invoice'];

        $bankName = $transaction['bank'] ?? '-';
        $bankAccount = $transaction['nomer_rekening'] ?? '-';
        $bankOwner = $transaction['nama_pemilik'] ?? '-';
        $totalAmount = number_format($transaction['actual_total'], 0, ',', '.');

        $content = '
            <h2>Halo, ' . htmlspecialchars($name) . '!</h2>
            <p>Terima kasih telah berbelanja di ' . \App\Libraries\TenantContext::name() . '. Pesanan Anda dengan nomor invoice <strong>' . htmlspecialchars($invoiceDisplay) . '</strong> telah berhasil dibuat.</p>
            
            <div class="info-box">
                <h3>Detail Pembayaran:</h3>
                <p><strong>Total Tagihan:</strong> Rp ' . $totalAmount . '</p>
                <p><strong>Bank:</strong> ' . htmlspecialchars($bankName) . '</p>
                <p><strong>Nomor Rekening:</strong> ' . htmlspecialchars($bankAccount) . '</p>
                <p><strong>Atas Nama:</strong> ' . htmlspecialchars($bankOwner) . '</p>
            </div>
            
            <p>Silakan lakukan pembayaran sesuai dengan detail di atas. Setelah melakukan pembayaran, Anda dapat mengunggah bukti pembayaran melalui aplikasi atau website kami.</p>
            
            <p>Anda dapat melihat detail pesanan Anda melalui link di bawah ini:</p>
            
            <center>
                <a href="' . env('app.baseURL', 'http://localhost:3000') . '/api/invoice/download/' . $transaction['id'] . '" class="button" style="color: #ffffff;">Lihat Invoice</a>
            </center>
            
            <p>Terima kasih atas kepercayaan Anda.</p>
        ';

        $html = get_email_template('Invoice ' . $invoiceDisplay, $content);

        return enqueue_email($email, 'Tagihan Pesanan #' . $invoiceDisplay . ' - ' . \App\Libraries\TenantContext::name(), $html);
    }
}

if (!function_exists('send_payment_confirmed_email')) {
    /**
     * Email when payment is verified and packaging starts
     */
    function send_payment_confirmed_email($transaction)
    {
        if (empty($transaction['customer']['email']))
            return false;

        $email = $transaction['customer']['email'];
        $name = $transaction['customer']['nama_customer'];
        $invoice = $transaction['invoice'];

        $content = '
            <h2>Pembayaran Diterima!</h2>
            <p>Halo, ' . htmlspecialchars($name) . '. Pembayaran Anda untuk pesanan <strong>' . htmlspecialchars($invoice) . '</strong> di <strong>' . \App\Libraries\TenantContext::name() . '</strong> telah berhasil diverifikasi.</p>
            <p>Saat ini tim kami sedang menyiapkan dan mengemas produk pesanan Anda dengan teliti.</p>
            <p>Kami akan memberikan update selanjutnya segera setelah paket siap dikirim atau diambil.</p>
            <p>Terima kasih telah bersabar!</p>
        ';

        $html = get_email_template('Pembayaran Terverifikasi - ' . $invoice, $content);
        return enqueue_email($email, 'Pembayaran Diverifikasi & Pesanan Sedang Disiapkan #' . $invoice . ' - ' . \App\Libraries\TenantContext::name(), $html);
    }
}

if (!function_exists('send_payment_rejected_email')) {
    /**
     * Email when payment is rejected
     */
    function send_payment_rejected_email($transaction, $reason)
    {
        if (empty($transaction['customer']['email']))
            return false;

        $email = $transaction['customer']['email'];
        $name = $transaction['customer']['nama_customer'];
        $invoice = $transaction['invoice'];

        $content = '
            <h2>Pembayaran Ditolak</h2>
            <p>Halo, ' . htmlspecialchars($name) . '. Mohon maaf, pembayaran Anda untuk pesanan <strong>' . htmlspecialchars($invoice) . '</strong> di <strong>' . \App\Libraries\TenantContext::name() . '</strong> belum dapat kami verifikasi.</p>
            
            <div class="info-box">
                <p><strong>Alasan Penolakan:</strong> ' . htmlspecialchars($reason ?: 'Bukti pembayaran tidak sesuai atau tidak terbaca.') . '</p>
            </div>
            
            <p>Silakan lakukan pembayaran ulang atau unggah bukti pembayaran yang valid melalui aplikasi/website kami agar kami dapat segera memproses pesanan Anda.</p>
            
            <p>Terima kasih atas pengertiannya.</p>
        ';

        $html = get_email_template('Pembayaran Ditolak - ' . $invoice, $content);
        return enqueue_email($email, 'Update Status Pembayaran Pesanan #' . $invoice . ' - ' . \App\Libraries\TenantContext::name(), $html);
    }
}

if (!function_exists('send_order_ready_email')) {
    /**
     * Email when order status is READY
     */
    function send_order_ready_email($transaction)
    {
        if (empty($transaction['customer']['email']))
            return false;

        $email = $transaction['customer']['email'];
        $name = $transaction['customer']['nama_customer'];
        $invoice = $transaction['invoice'];

        $content = '
            <h2>Pesanan Anda Sudah Siap!</h2>
            <p>Halo, ' . htmlspecialchars($name) . '. Kabar baik! Pesanan <strong>' . htmlspecialchars($invoice) . '</strong> di <strong>' . \App\Libraries\TenantContext::name() . '</strong> telah selesai kami kemas dan siap untuk tahap selanjutnya.</p>
            <p>Jika Anda memilih pengiriman via kurir, paket akan segera diserahkan ke pihak ekspedisi. Jika Anda memilih ambil di tempat, Anda sudah bisa datang ke toko kami sesuai jam operasional.</p>
        ';

        $html = get_email_template('Pesanan Siap - ' . $invoice, $content);
        return enqueue_email($email, 'Pesanan Anda Siap Dikirim/Diambil #' . $invoice . ' - ' . \App\Libraries\TenantContext::name(), $html);
    }
}

if (!function_exists('send_order_shipped_email')) {
    /**
     * Email when order status is SHIPPED
     */
    function send_order_shipped_email($transaction, $receiptNumber, $courierName)
    {
        if (empty($transaction['customer']['email']))
            return false;

        $email = $transaction['customer']['email'];
        $name = $transaction['customer']['nama_customer'];
        $invoice = $transaction['invoice'];

        $content = '
            <h2>Pesanan Dalam Perjalanan!</h2>
            <p>Halo, ' . htmlspecialchars($name) . '. Pesanan <strong>' . htmlspecialchars($invoice) . '</strong> di <strong>' . \App\Libraries\TenantContext::name() . '</strong> telah kami serahkan ke kurir.</p>
            <div class="info-box">
                <p><strong>Kurir:</strong> ' . htmlspecialchars($courierName ?: '-') . '</p>
                <p><strong>Nomor Resi:</strong> ' . htmlspecialchars($receiptNumber ?: '-') . '</p>
            </div>
            <p>Anda dapat melacak status pengiriman melalui website ekspedisi terkait menggunakan nomor resi di atas.</p>
            <p>Terima kasih telah berbelanja di ' . \App\Libraries\TenantContext::name() . '!</p>
        ';

        $html = get_email_template('Pesanan Dikirim - ' . $invoice, $content);
        return enqueue_email($email, 'Pesanan Anda Sudah Dikirim #' . $invoice . ' - ' . \App\Libraries\TenantContext::name(), $html);
    }
}

if (!function_exists('send_order_delivered_email')) {
    /**
     * Email when order status is DELIVERED
     */
    function send_order_delivered_email($transaction)
    {
        if (empty($transaction['customer']['email']))
            return false;

        $email = $transaction['customer']['email'];
        $name = $transaction['customer']['nama_customer'];
        $invoice = $transaction['invoice'];

        $content = '
            <h2>Pesanan Telah Diterima!</h2>
            <p>Halo, ' . htmlspecialchars($name) . '. Kabar baik! Pesanan <strong>' . htmlspecialchars($invoice) . '</strong> di <strong>' . \App\Libraries\TenantContext::name() . '</strong> telah berhasil diterima atau diambil.</p>
            <p>Terima kasih telah berbelanja di ' . \App\Libraries\TenantContext::name() . '. Kami berharap produk yang Anda terima sesuai dengan keinginan Anda.</p>
            <p>Jika Anda puas dengan pelayanan kami, mohon berikan ulasan positif Anda. Sampai jumpa di pesanan berikutnya!</p>
        ';

        $html = get_email_template('Pesanan Diterima - ' . $invoice, $content);
        return enqueue_email($email, 'Pesanan Anda Telah Diterima #' . $invoice . ' - ' . \App\Libraries\TenantContext::name(), $html);
    }
}

if (!function_exists('send_invoice_adjusted_email')) {
    /**
     * Email when an invoice is adjusted
     */
    function send_invoice_adjusted_email($transaction, $componentName, $type, $amount, $newActualTotal)
    {
        if (empty($transaction['customer']['email']))
            return false;

        $email = $transaction['customer']['email'];
        $name = $transaction['customer']['nama_customer'];
        $invoice = $transaction['invoice'];

        $sign = ($type === 'addition') ? 'Penambahan' : 'Pengurangan';

        // Format amount: if it's PPN, render as percentage instead of Rupiah
        $isPpn = stripos($componentName, 'ppn') !== false || stripos($componentName, 'pajak') !== false;
        if ($isPpn && $type !== 'Multiple') {
            $formattedAdjustment = (float) $amount . '%';
        } else {
            $formattedAdjustment = 'Rp ' . number_format($amount, 0, ',', '.');
        }

        $newTotalStr = number_format($newActualTotal, 0, ',', '.');

        $content = '
            <h2>Pembaruan Invoice</h2>
            <p>Halo, ' . htmlspecialchars($name) . '. Terdapat penyesuaian biaya pada pesanan Anda di <strong>' . \App\Libraries\TenantContext::name() . '</strong> dengan nomor invoice <strong>' . htmlspecialchars($invoice) . '</strong>.</p>
            
            <div class="info-box">
                <p><strong>Komponen:</strong> ' . htmlspecialchars($componentName) . '</p>
                <p><strong>Jenis Penyesuaian:</strong> ' . $sign . '</p>
                <p><strong>Nominal Penyesuaian:</strong> ' . $formattedAdjustment . '</p>
                <hr style="border-top: 1px solid #ddd; border-bottom: none; border-left: none; border-right: none;" />
                <p><strong>Total Tagihan Baru:</strong> Rp ' . $newTotalStr . '</p>
            </div>
            
            <p>Anda dapat melihat detail pesanan Anda melalui link di bawah ini:</p>
            
            <center>
                <a href="' . env('app.baseURL', 'http://localhost:3000') . '/api/invoice/download/' . $transaction['id'] . '" class="button" style="color: #ffffff;">Lihat Invoice Terbaru</a>
            </center>
            
            <p>Terima kasih atas pengertian dan kepercayaan Anda.</p>
        ';

        $html = get_email_template('Pembaruan Invoice - ' . $invoice, $content);
        return enqueue_email($email, 'Pembaruan Tagihan Pesanan #' . $invoice . ' - ' . \App\Libraries\TenantContext::name(), $html);
    }
}
