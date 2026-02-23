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
            margin: 15mm;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            color: #333;
            background: #fff;
            line-height: 1.6;
            font-size: 11px;
        }

        .invoice-container {
            width: 100%;
            max-width: 170mm;
            margin: 0 auto;
        }

        /* Header using table for better PDF support */
        .header-table {
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 3px solid #2c3e50;
            padding-bottom: 15px;
        }

        .header-table td {
            vertical-align: top;
        }

        .logo {
            max-width: 120px;
            max-height: 60px;
        }

        .company-info h1 {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .company-info p {
            margin: 2px 0;
            font-size: 10px;
            color: #555;
        }

        .invoice-title {
            text-align: right;
        }

        .invoice-title h2 {
            font-size: 28px;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .invoice-meta p {
            margin: 3px 0;
            font-size: 10px;
            color: #555;
        }

        .invoice-meta strong {
            color: #2c3e50;
        }

        /* Info section using table */
        .info-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .info-table td {
            width: 50%;
            padding: 12px;
            background: #f8f9fa;
            vertical-align: top;
        }

        .info-table td:first-child {
            border-right: 8px solid #fff;
        }

        .info-box h3 {
            font-size: 12px;
            color: #2c3e50;
            margin-bottom: 8px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 4px;
        }

        .info-box p {
            font-size: 10px;
            margin: 4px 0;
            color: #555;
        }

        .bank-info {
            background: #e8f4f8;
            padding: 8px;
            border-radius: 3px;
            margin-top: 8px;
        }

        .bank-info p {
            margin: 2px 0;
            font-size: 9px;
        }

        .bank-info strong {
            color: #2c3e50;
        }

        /* Items table */
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        table.items thead {
            background: #2c3e50;
            color: white;
        }

        table.items thead th {
            padding: 10px 6px;
            text-align: left;
            font-size: 10px;
            font-weight: 600;
        }

        table.items tbody td {
            padding: 8px 6px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 10px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Summary table */
        .summary-table {
            width: 100%;
            margin-bottom: 20px;
        }

        .summary-table td {
            padding: 0;
        }

        .summary-box {
            width: 300px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 3px;
            float: right;
        }

        .summary-row {
            width: 100%;
            margin-bottom: 8px;
        }

        .summary-row td {
            padding: 2px 0;
            font-size: 11px;
        }

        .summary-row.total td {
            border-top: 2px solid #2c3e50;
            border-bottom: 2px solid #2c3e50;
            padding: 8px 0;
            font-size: 13px;
            font-weight: bold;
            color: #2c3e50;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 9px;
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

        .notes {
            background: #fffbea;
            padding: 12px;
            border-left: 4px solid #f39c12;
            margin-bottom: 15px;
            border-radius: 2px;
        }

        .notes h4 {
            font-size: 11px;
            color: #2c3e50;
            margin-bottom: 6px;
        }

        .notes p {
            font-size: 9px;
            color: #555;
        }

        .footer {
            clear: both;
            margin-top: 50px;
            padding-top: 15px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            font-size: 9px;
            color: #777;
        }

        .footer p {
            margin: 2px 0;
        }
    </style>
</head>

<body>
    <div class="invoice-container">
        <!-- Header -->
        <table class="header-table" cellpadding="0" cellspacing="0">
            <tr>
                <td style="width: 60%;">
                    <?php if (!empty($transaction['toko_logo'])): ?>
                        <img src="<?= base_url('uploads/toko/' . $transaction['toko_logo']) ?>" alt="Logo" class="logo">
                    <?php endif; ?>
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
                        <?php endif; ?>
                    </div>
                </td>
                <td style="width: 40%;" class="invoice-title">
                    <h2>INVOICE</h2>
                    <div class="invoice-meta">
                        <p><strong>No. Invoice:</strong>
                            <?= esc($transaction['invoice_number'] ?? 'INV-' . str_pad($transaction['id'], 6, '0', STR_PAD_LEFT)) ?>
                        </p>
                        <p><strong>Tanggal:</strong>
                            <?= date('d F Y', strtotime($transaction['created_at'])) ?>
                        </p>
                        <p><strong>Jatuh Tempo:</strong>
                            <?= !empty($transaction['due_date']) ? date('d F Y', strtotime($transaction['due_date'])) : '-' ?>
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Info Section -->
        <table class="info-table" cellpadding="0" cellspacing="0">
            <tr>
                <td>
                    <div class="info-box">
                        <h3>Kepada</h3>
                        <p><strong>
                                <?= esc($transaction['meta']['customer_name'] ?? 'Customer') ?>
                            </strong></p>
                        <p>
                            <?= esc($transaction['meta']['alamat'] ?? '') ?>
                        </p>
                        <p>
                            <?= esc($transaction['meta']['kota_kabupaten'] ?? '') ?>
                            <?= !empty($transaction['meta']['provinsi']) ? ', ' . esc($transaction['meta']['provinsi']) : '' ?>
                        </p>
                        <?php if (!empty($transaction['meta']['kode_pos'])): ?>
                            <p>
                                <?= esc($transaction['meta']['kode_pos']) ?>
                            </p>
                        <?php endif; ?>
                        <p>Telp:
                            <?= esc($transaction['meta']['customer_phone'] ?? '') ?>
                        </p>
                    </div>
                </td>
                <td>
                    <div class="info-box">
                        <h3>Informasi Pembayaran</h3>
                        <div class="bank-info">
                            <?php if (!empty($transaction['bank'])): ?>
                                <p><strong>Bank:</strong>
                                    <?= esc($transaction['bank']) ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($transaction['nomer_rekening'])): ?>
                                <p><strong>No. Rekening:</strong>
                                    <?= esc($transaction['nomer_rekening']) ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($transaction['nama_pemilik'])): ?>
                                <p><strong>Atas Nama:</strong>
                                    <?= esc($transaction['nama_pemilik']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <p style="margin-top: 8px;"><strong>Status:</strong>
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
                            }
                            ?>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= $statusText ?>
                            </span>
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Notes (moved before items to prevent overlap with summary) -->
        <?php if (!empty($transaction['notes']) || !empty($transaction['meta']['notes'])): ?>
            <div class="notes">
                <h4>Catatan</h4>
                <p>
                    <?= esc($transaction['notes'] ?? $transaction['meta']['notes'] ?? '') ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Items Table -->
        <table class="items" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 40%;">Nama Barang</th>
                    <th style="width: 10%;" class="text-center">Jumlah</th>
                    <th style="width: 18%;" class="text-right">Harga Satuan</th>
                    <th style="width: 10%;" class="text-center">Diskon</th>
                    <th style="width: 17%;" class="text-right">Total</th>
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
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= number_format($item['jumlah'], 0, ',', '.') ?>
                        </td>
                        <td class="text-right">
                            <?php if (!empty($item['diskon'])): ?>
                                <small style="color: #999; text-decoration: line-through;">Rp
                                    <?= number_format($item['harga_jual'] * $item['jumlah'], 0, ',', '.') ?></small><br>
                            <?php endif; ?>
                            Rp <?= number_format($item['harga_jual'], 0, ',', '.') ?>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($item['diskon'])): ?>
                                <?php
                                $discountPercent = ($item['diskon'] / ($item['harga_jual'] * $item['jumlah'])) * 100;
                                ?>
                                <span
                                    style="color: #27ae60; font-weight: bold;"><?= number_format($discountPercent, 0) ?>%</span><br>
                                <small>Rp <?= number_format($item['diskon'], 0, ',', '.') ?></small>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><strong>Rp
                                <?= number_format($itemTotal, 0, ',', '.') ?>
                            </strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Summary -->
        <table class="summary-table" cellpadding="0" cellspacing="0" style="clear: both;">
            <tr>
                <td></td>
                <td style="width: 300px;">
                    <div class="summary-box">
                        <table class="summary-row" cellpadding="0" cellspacing="0">
                            <tr>
                                <td>Subtotal:</td>
                                <td class="text-right">Rp
                                    <?= number_format($subtotal, 0, ',', '.') ?>
                                </td>
                            </tr>
                        </table>
                        <?php if (!empty($transaction['discount_type'])): ?>
                            <table class="summary-row" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>Diskon Tambahan:
                                        <?= $transaction['discount_type'] == 'PERCENTAGE' ? '(' . $transaction['discount_amount'] . '%)' : '' ?>
                                    </td>
                                    <td class="text-right">- Rp
                                        <?= number_format($transaction['meta']['tx_discount_value'] ?? 0, 0, ',', '.') ?>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>
                        <?php if (!empty($transaction['meta']['ppn']) && !empty($transaction['meta']['ppn_value'])): ?>
                            <table class="summary-row" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>Pajak (PPN <?= esc($transaction['meta']['ppn']) ?>%):</td>
                                    <td class="text-right">Rp
                                        <?= number_format($transaction['meta']['ppn_value'], 0, ',', '.') ?>
                                    </td>
                                </tr>
                            </table>
                        <?php elseif (!empty($transaction['ppn'])): ?>
                            <table class="summary-row" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>Pajak (PPN):</td>
                                    <td class="text-right">Rp
                                        <?= number_format($transaction['ppn'], 0, ',', '.') ?>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>
                        <?php if (!empty($transaction['meta']['biaya_pengiriman'])): ?>
                            <table class="summary-row" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>Ongkos Kirim:</td>
                                    <td class="text-right">
                                        <?php if ($transaction['meta']['free_ongkir'] == 1): ?>
                                            <span style="text-decoration: line-through; color: #999;">Rp
                                                <?= number_format($transaction['meta']['biaya_pengiriman'], 0, ',', '.') ?></span>
                                            <span style="color: #27ae60; font-weight: bold; margin-left: 5px;">GRATIS</span>
                                        <?php else: ?>
                                            Rp <?= number_format($transaction['meta']['biaya_pengiriman'] ?? 0, 0, ',', '.') ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>
                        <table class="summary-row total" cellpadding="0" cellspacing="0">
                            <tr>
                                <td>TOTAL:</td>
                                <td class="text-right">Rp
                                    <?= number_format($transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal, 0, ',', '.') ?>
                                </td>
                            </tr>
                        </table>
                        <?php if (!empty($transaction['total_paid'])): ?>
                            <table class="summary-row" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="color: #27ae60;">Dibayar:</td>
                                    <td class="text-right" style="color: #27ae60;">Rp
                                        <?= number_format($transaction['total_paid'], 0, ',', '.') ?>
                                    </td>
                                </tr>
                            </table>
                            <table class="summary-row" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="color: #e74c3c;">Sisa:</td>
                                    <td class="text-right" style="color: #e74c3c;">Rp
                                        <?= number_format(($transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal) - $transaction['total_paid'], 0, ',', '.') ?>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>



        <!-- Footer -->
        <div class="footer">
            <p><strong>Terima kasih atas kepercayaan Anda</strong></p>
            <p>Invoice ini dicetak secara otomatis dan sah tanpa tanda tangan</p>
            <p style="margin-top: 8px;">Dicetak pada:
                <?= date('d F Y H:i:s') ?>
            </p>
        </div>
    </div>
</body>

</html>