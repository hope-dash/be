<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #
        <?= $transaction['invoice_number'] ?? $transaction['id'] ?>
    </title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @page {
            size: A4;
            margin: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            background: #fff;
            line-height: 1.6;
        }

        .invoice-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 20mm;
            background: white;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #2c3e50;
        }

        .logo-section {
            flex: 1;
        }

        .logo {
            max-width: 150px;
            max-height: 80px;
            margin-bottom: 10px;
        }

        .company-info {
            font-size: 12px;
            color: #555;
        }

        .company-info h1 {
            font-size: 24px;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .company-info p {
            margin: 3px 0;
        }

        .invoice-title {
            text-align: right;
            flex: 1;
        }

        .invoice-title h2 {
            font-size: 30px;
            color: #2c3e50;
            margin-bottom: 2px;
        }

        .invoice-meta {
            font-size: 12px;
            color: #555;
        }

        .invoice-meta p {
            margin: 5px 0;
        }

        .invoice-meta strong {
            color: #2c3e50;
        }

        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .info-box {
            flex: 1;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            margin-right: 15px;
        }

        .info-box:last-child {
            margin-right: 0;
        }

        .info-box h3 {
            font-size: 14px;
            color: #2c3e50;
            margin-bottom: 10px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 5px;
        }

        .info-box p {
            font-size: 12px;
            margin: 5px 0;
            color: #555;
        }

        .bank-info {
            background: #e8f4f8;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }

        .bank-info p {
            margin: 3px 0;
            font-size: 11px;
        }

        .bank-info strong {
            color: #2c3e50;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table thead {
            background: #2c3e50;
            color: white;
        }

        table thead th {
            padding: 12px 8px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
        }

        table tbody td {
            padding: 10px 8px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 12px;
        }

        table tbody tr:hover {
            background: #f8f9fa;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .summary-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }

        .summary-box {
            width: 350px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 13px;
        }

        .summary-row.total {
            border-top: 2px solid #2c3e50;
            padding-top: 10px;
            margin-top: 10px;
            font-size: 16px;
            font-weight: bold;
            color: #2c3e50;
        }

        .payment-section {
            margin-bottom: 30px;
        }

        .payment-section h3 {
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 15px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 5px;
        }

        .payment-list {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
            font-size: 12px;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-paid {
            background: #d4edda;
            color: #155724;
        }

        .status-partial {
            background: #fff3cd;
            color: #856404;
        }

        .status-unpaid {
            background: #f8d7da;
            color: #721c24;
        }

        .status-canceled {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-refund {
            background: #cce5ff;
            color: #004085;
        }

        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            font-size: 11px;
            color: #777;
        }

        .notes {
            background: #fdf2f2;
            padding: 20px;
            border: 2px solid #e03131;
            margin-bottom: 25px;
            border-radius: 8px;
        }

        .notes h4 {
            font-size: 16px;
            color: #c92a2a;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 800;
        }

        .notes p {
            font-size: 13px;
            color: #333;
            margin: 5px 0;
            line-height: 1.5;
            font-weight: 500;
        }

        .shipping-highlight {
            background: #f0f4ff !important;
            border: 2px solid #c1cbffff !important;
        }

        .shipping-highlight h3 {
            color: #364fc7 !important;
            border-bottom: 2px solid #c1cbffff !important;
        }

        .bank-summary {
            margin-top: 15px;
            padding: 15px;
            background: #fff;
            border: 1px dashed #2c3e50;
            border-radius: 5px;
        }

        .bank-summary h4 {
            font-size: 12px;
            color: #2c3e50;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .qty-text {
            font-size: 15px;
            font-weight: 800;
            color: #000;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }

            .invoice-container {
                margin: 0;
                padding: 15mm;
            }

            @page {
                margin: 0;
            }
        }
    </style>
</head>

<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            <div class="logo-section">
                <?php if (!empty($transaction['toko_logo'])): ?>
                    <img src="<?= base_url('uploads/toko/' . $transaction['toko_logo']) ?>" alt="Logo" class="logo">
                    <?php
                endif; ?>
                <div class="company-info">
                    <h1>
                        <?= esc($transaction['toko_name'] ?? 'Nama Toko') ?>
                    </h1>
                    <p>
                        <?= esc($transaction['toko_alamat'] ?? '') ?>
                    </p>
                    <p>Telp:
                        <?= esc($transaction['toko_phone'] ?? '') ?>
                    </p>
                    <?php if (!empty($transaction['email_toko'])): ?>
                        <p>Email:
                            <?= esc($transaction['email_toko']) ?>
                        </p>
                        <?php
                    endif; ?>
                </div>
            </div>
            <div class="invoice-title">
                <h2>INVOICE</h2>
                <div class="invoice-meta">
                    <p><strong>No. Invoice:</strong>
                        <?= esc($transaction['invoice_number'] ?? 'INV-' . str_pad($transaction['id'], 6, '0', STR_PAD_LEFT)) ?>
                    </p>
                    <p><strong>Tanggal:</strong>
                        <?= date('d F Y', strtotime($transaction['created_at'])) ?>
                    </p>

                    <?= !empty($transaction['due_date']) ? '<p><strong>Jatuh Tempo:</strong>' . date('d F Y', strtotime($transaction['due_date'])) . '</p>' : '' ?>

                    <p style="margin-top: 10px;">
                        <?php
                        $status = strtolower($transaction['status'] ?? 'unpaid');
                        $statusClass = 'status-unpaid';
                        $statusText = 'Belum Dibayar';

                        if ($status === 'paid' || $status === 'fully_paid') {
                            $statusClass = 'status-paid';
                            $statusText = 'Lunas';
                        } elseif ($status === 'partially_paid') {
                            $statusClass = 'status-partial';
                            $statusText = 'Dibayar Sebagian';
                        } elseif ($status === 'cancel' || $status === 'canceled' || $status === 'cancelled') {
                            $statusClass = 'status-canceled';
                            $statusText = 'Dibatalkan';
                        } elseif ($status === 'need_refund') {
                            $statusClass = 'status-refund';
                            $statusText = 'Butuh Refund';
                        } elseif ($status === 'refunded') {
                            $statusClass = 'status-refund';
                            $statusText = 'Refund Selesai';
                        }
                        ?>
                        <span class="status-badge <?= $statusClass ?>"
                            style="padding: 8px 20px; font-size: 14px; border: 1px solid #ddd;">
                            <?= $statusText ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <h3>Kepada</h3>
                <p><strong>
                        <?= esc($transaction['meta']['customer_name']) ?>
                    </strong></p>
                <p>
                    <?= esc($transaction['meta']['alamat'] ?? '') ?>
                </p>
                <p>
                    <?= esc($transaction['meta']['provinsi'] ?? '') ?>,
                    <?= esc($transaction['meta']['kota_kabupaten'] ?? '') ?>
                </p>
                <p>
                    <?= esc($transaction['meta']['kode_pos'] ?? '') ?>
                </p>
                <p>Telp:
                    <?= esc($transaction['meta']['customer_phone'] ?? '') ?>
                </p>
            </div>

            <div class="info-box shipping-highlight">
                <h3>Informasi Pengiriman</h3>
                <p style="margin-top: 5px;"><strong>Ekspedisi:</strong>
                    <span
                        style="font-size: 14px; font-weight: bold; color: #364fc7;"><?= esc($transaction['meta']['courier'] ?? $transaction['meta']['pengiriman'] ?? '-') ?></span>
                </p>
                <p><strong>No. Resi:</strong>
                    <span
                        style="font-size: 14px; font-weight: bold;"><?= esc($transaction['meta']['resi'] ?? '-') ?></span>
                </p>
                <?php
                $delStatus = str_replace('_', ' ', strtoupper($transaction['delivery_status'] ?? $transaction['meta']['shipping_status'] ?? 'BELUM DIKIRIM'));
                ?>
                <p><strong>Status:</strong> <span class="status-badge"
                        style="background: #364fc7; color: white;"><?= esc($delStatus) ?></span></p>
            </div>


        </div>

        <!-- Items Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 40%;">Nama Barang</th>
                    <th style="width: 10%;" class="text-center">Jumlah</th>
                    <th style="width: 20%;" class="text-right">Harga Satuan</th>
                    <th style="width: 10%;" class="text-center">Diskon</th>
                    <th style="width: 20%;" class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                $subtotal = 0;
                foreach ($transaction['items'] as $item):
                    $itemTotal = $item['actual_total'] ?? (($item['harga_jual'] * $item['jumlah']) - ($item['diskon'] ?? 0));
                    $subtotal += $itemTotal;
                    ?>
                    <tr>
                        <td class="text-center">
                            <?= $no++ ?>
                        </td>
                        <td>
                            <strong>
                                <?= esc($item['nama_lengkap_barang']) ?>
                            </strong>
                            <?php if (!empty($item['keterangan'])): ?>
                                <br><small style="color: #777;">
                                    <?= esc($item['keterangan']) ?>
                                </small>
                                <?php
                            endif; ?>
                        </td>
                        <td class="text-center qty-text">
                            <?= number_format($item['jumlah'], 0, ',', '.') ?>
                        </td>
                        <td class="text-right">Rp
                            <?= number_format($item['harga_jual'], 0, ',', '.') ?>
                        </td>
                        <td class="text-center">
                            <?= !empty($item['diskon']) ? 'Rp ' . number_format($item['diskon'], 0, ',', '.') : '-' ?>
                        </td>
                        <td class="text-right"><strong>Rp
                                <?= number_format($itemTotal, 0, ',', '.') ?>
                            </strong></td>
                    </tr>
                    <?php
                endforeach; ?>
            </tbody>
        </table>

        <!-- Summary -->
        <div class="summary-section">
            <div class="summary-box">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span>Rp
                        <?= number_format($subtotal, 0, ',', '.') ?>
                    </span>
                </div>
                <?php if (!empty($transaction['discount_type'])): ?>
                    <div class="summary-row">
                        <span>Diskon Tambahan:</span>
                        <span>
                            <?= $transaction['discount_type'] == 'PERCENTAGE' ? $transaction['discount_amount'] . '%' : '' ?>
                        </span>
                        <span>- Rp
                            <?= number_format($transaction['meta']['tx_discount_value'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <?php
                endif; ?>
                <?php if (!empty($transaction['meta']['ppn']) && !empty($transaction['meta']['ppn_value'])): ?>
                    <div class="summary-row">
                        <span>Pajak (PPN
                            <?= esc($transaction['meta']['ppn']) ?>%):
                        </span>
                        <span>Rp
                            <?= number_format($transaction['meta']['ppn_value'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <?php
                elseif (!empty($transaction['ppn'])): ?>
                    <div class="summary-row">
                        <span>Pajak:</span>
                        <span>Rp
                            <?= number_format($transaction['ppn'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <?php
                endif; ?>
                <?php if (!empty($transaction['meta']['biaya_pengiriman'])): ?>
                    <div class="summary-row">
                        <span>Ongkos Kirim:</span>
                        <span>
                            <?php if ($transaction['meta']['free_ongkir'] == 1): ?>
                                <del style="color: #999;">Rp
                                    <?= number_format($transaction['meta']['biaya_pengiriman'], 0, ',', '.') ?>
                                </del>
                                <span style="color: #27ae60; font-weight: bold; margin-left: 8px;">GRATIS</span>
                                <?php
                            else: ?>
                                Rp
                                <?= number_format($transaction['meta']['biaya_pengiriman'] ?? 0, 0, ',', '.') ?>
                                <?php
                            endif; ?>
                        </span>
                    </div>
                    <?php
                endif; ?>

                <?php
                if (!empty($transaction['meta']['adjustments'])):
                    $adjustments = json_decode($transaction['meta']['adjustments'], true) ?? [];
                    foreach ($adjustments as $adj):
                        if (($adj['category'] ?? '') === 'PPN')
                            continue;
                        $isSubtraction = (($adj['type'] ?? '') === 'subtraction' || ($adj['type'] ?? '') === 'reduction');
                        $color = $isSubtraction ? '#059669' : '#6b7280';
                        $sign = $isSubtraction ? '- ' : '+ ';
                        ?>
                        <div class="summary-row" style="color: <?= $color ?>;">
                            <span>
                                <?= esc($adj['component_name'] ?? $adj['category'] ?? 'Penyesuaian') ?>:
                            </span>
                            <span>
                                <?= $sign ?>Rp
                                <?= number_format($adj['amount'], 0, ',', '.') ?>
                            </span>
                        </div>
                        <?php
                    endforeach;
                endif;
                ?>
                <div class="summary-row total">
                    <span>TOTAL:</span>
                    <span>Rp
                        <?= number_format($transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal, 0, ',', '.') ?>
                    </span>
                </div>
                <?php if (isset($transaction['total_paid']) && $transaction['total_paid'] > 0): ?>
                    <div class="summary-row" style="color: #059669; font-weight: 500;">
                        <span>Dibayar:</span>
                        <span>Rp
                            <?= number_format($transaction['total_paid'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <div class="summary-row" style="color: #dc2626; font-weight: 600;">
                        <span>Sisa:</span>
                        <span>Rp
                            <?= number_format(($transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal) - $transaction['total_paid'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <?php
                endif; ?>

                <!-- Bank Info exactly below Totals -->
                <div class="bank-summary">
                    <h4>Informasi Pembayaran (Transfer)</h4>
                    <?php if (!empty($transaction['bank'])): ?>
                        <p style="font-size: 14px; margin-bottom: 5px;"><strong>Bank:</strong>
                            <span style="color: #2c3e50;"><?= esc($transaction['bank']) ?></span>
                        </p>
                        <?php
                    endif; ?>
                    <?php if (!empty($transaction['nomer_rekening'])): ?>
                        <p style="font-size: 18px; margin-bottom: 5px; color: #c92a2a;"><strong>No. Rekening:</strong>
                            <span
                                style="font-weight: 800; letter-spacing: 1px;"><?= esc($transaction['nomer_rekening']) ?></span>
                        </p>
                        <?php
                    endif; ?>
                    <?php if (!empty($transaction['nama_pemilik'])): ?>
                        <p style="font-size: 14px;"><strong>Atas Nama:</strong>
                            <?= esc($transaction['nama_pemilik']) ?>
                        </p>
                        <?php
                    endif; ?>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Notes -->
    <?php if (!empty($transaction['notes']) || !empty($transaction['meta']['notes'])): ?>
        <div class="notes">
            <h4>Catatan</h4>
            <p>
                <?= esc($transaction['notes'] ?? $transaction['meta']['notes'] ?? '') ?>
            </p>
        </div>
        <?php
    endif; ?>

    <!-- Footer -->
    <div class="footer">
        <p>Terima kasih atas kepercayaan Anda</p>
        <p style="margin-top: 5px;">Invoice ini dicetak secara otomatis dan sah tanpa tanda tangan</p>
        <p style="margin-top: 10px; font-size: 10px;">Dicetak pada:
            <?= date('d F Y H:i:s') ?>
        </p>
    </div>
    </div>

    <script>
        // Auto print when loaded (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>

</html>