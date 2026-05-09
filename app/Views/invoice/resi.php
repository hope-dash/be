<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Resi Pengiriman #<?= $transaction['invoice_number'] ?? $transaction['id'] ?></title>
    <style>
        @page {
            size: A6 portrait;
            margin: 3mm;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 7.2pt;
            color: #000;
            line-height: 1.2;
            margin: 0;
            padding: 0;
        }

        .container {
            border: 1px solid #000;
            width: 100%;
        }

        .header {
            display: table;
            width: 100%;
            border-bottom: 1px solid #000;
            padding: 1.5mm 2mm;
        }

        .header-left {
            display: table-cell;
            font-size: 11pt;
            font-weight: bold;
            vertical-align: middle;
        }

        .header-right {
            display: table-cell;
            text-align: right;
            font-size: 8.5pt;
            font-weight: bold;
            vertical-align: middle;
            color: #e00;
        }

        .resi-box {
            display: table;
            width: 100%;
            border-bottom: 1px solid #000;
        }

        .resi-cell {
            display: table-cell;
            padding: 1.2mm;
            border-right: 1px solid #000;
            width: 50%;
            text-align: center;
        }

        .resi-cell:last-child {
            border-right: none;
        }

        .resi-label {
            font-size: 5.5pt;
            text-transform: uppercase;
            display: block;
            margin-bottom: 0.3mm;
        }

        .resi-value {
            font-size: 8.5pt;
            font-weight: bold;
        }

        .address-box {
            display: table;
            width: 100%;
            border-bottom: 1px solid #000;
        }

        .address-cell {
            display: table-cell;
            padding: 1.5mm 2mm;
            width: 50%;
            vertical-align: top;
            border-right: 1px solid #000;
        }

        .address-cell:last-child {
            border-right: none;
        }

        .addr-label {
            font-weight: bold;
            display: block;
            margin-bottom: 0.8mm;
            text-transform: uppercase;
            font-size: 6pt;
        }

        .addr-name {
            font-weight: bold;
            font-size: 8pt;
            display: block;
        }

        .addr-detail {
            font-size: 6.8pt;
            margin-top: 0.8mm;
            display: block;
        }

        .info-row {
            display: table;
            width: 100%;
            border-bottom: 1px solid #000;
        }

        .info-cell {
            display: table-cell;
            padding: 1.2mm 2mm;
            border-right: 1px solid #000;
            vertical-align: middle;
        }

        .info-cell:last-child {
            border-right: none;
        }

        .status-badge {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 7.5pt;
        }

        .note-text {
            font-style: italic;
            font-size: 6pt;
        }

        .order-details {
            padding: 1.5mm 2mm;
            background: #fff;
            border-bottom: 1px solid #000;
        }

        .detail-item {
            display: inline-block;
            margin-right: 4mm;
            font-size: 6.8pt;
        }

        .detail-item strong {
            font-size: 7.2pt;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th {
            text-align: left;
            border-bottom: 1px solid #000;
            padding: 0.8mm 2mm;
            font-size: 6pt;
            background: #eee;
        }

        .items-table td {
            padding: 1.2mm 2mm;
            border-bottom: 0.5px solid #eee;
            vertical-align: top;
            font-size: 6.8pt;
        }

        .text-right {
            text-align: right;
        }

        .footer {
            padding: 1.5mm;
            text-align: center;
            font-size: 5.5pt;
            color: #888;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <?= strtoupper(esc($transaction['toko_name'] ?? 'HOPE')) ?>
            </div>
            <div class="header-right">
                <?= strtoupper(esc($transaction['meta']['courier'] ?? $transaction['meta']['pengiriman'] ?? 'REGULER')) ?>
            </div>
        </div>

        <!-- Resi & Service -->
        <div class="resi-box">
            <div class="resi-cell">
                <span class="resi-label">No. Invoice</span>
                <span
                    class="resi-value"><?= esc($transaction['invoice_number'] ?? $transaction['invoice'] ?? 'INV-' . $transaction['id']) ?></span>
            </div>
            <div class="resi-cell">
                <span class="resi-label">No. Resi</span>
                <span class="resi-value">
                    <?= strtoupper(esc($transaction['meta']['courier'] ?? $transaction['meta']['pengiriman'] ?? 'REGULER')) ?>
                    <?= esc($transaction['meta']['resi'] ?? '-') ?>
                </span>
            </div>
        </div>

        <!-- Addresses -->
        <div class="address-box">
            <!-- Penerima -->
            <div class="address-cell">
                <span class="addr-label">Penerima:</span>
                <span
                    class="addr-name"><?= esc($transaction['customer']['nama_customer'] ?? $transaction['meta']['customer_name'] ?? 'Guest') ?></span>
                <span
                    class="addr-detail"><?= esc($transaction['customer']['phone_number'] ?? $transaction['meta']['customer_phone'] ?? '-') ?></span>
                <span class="addr-detail">
                    <?= esc($transaction['customer']['alamat'] ?? '') ?>
                    <?php if (!empty($transaction['meta']['kecamatan'])): ?>
                        <?= esc($transaction['meta']['kecamatan']) ?>,
                    <?php endif; ?>
                    <?php if (!empty($transaction['meta']['kota_kabupaten'])): ?>
                        <?= esc($transaction['meta']['kota_kabupaten']) ?>
                    <?php endif; ?>
                    <?php if (!empty($transaction['meta']['provinsi'])): ?>
                        , <?= esc($transaction['meta']['provinsi']) ?>
                    <?php endif; ?>
                </span>
            </div>
            <!-- Pengirim -->
            <div class="address-cell">
                <span class="addr-label">Pengirim:</span>
                <span class="addr-name"><?= esc($transaction['toko_name'] ?? 'HOPE') ?></span>
                <span class="addr-detail"><?= esc($transaction['toko_phone'] ?? '-') ?></span>
                <span class="addr-detail"><?= esc($transaction['toko_alamat'] ?? '-') ?></span>
            </div>
        </div>

        <!-- Status & Help 
        <div class="info-row">
            <div class="info-cell" style="width: 30%;">
                <span class="status-badge"><?= strtoupper(esc($transaction['status'] ?? 'PAID')) ?></span>
            </div>
            <div class="info-cell">
                <span class="note-text">-</span>
            </div>
        </div>
        -->

        <!-- Order Metadata -->
        <?php
        $totalWeight = 0;
        foreach ($transaction['items'] as $item) {
            $totalWeight += ($item['berat'] ?? 0) * ($item['jumlah'] ?? 0);
        }
        if ($totalWeight == 0)
            $totalWeight = 1000; // Default 1kg if not specified
        ?>
        <div class="order-details">
            <div class="detail-item">Berat: <strong><?= number_format($totalWeight, 0, ',', '.') ?> gr</strong></div>
            <div class="detail-item">Tgl: <strong><?= date('d-m-Y', strtotime($transaction['created_at'])) ?></strong>
            </div>
        </div>

        <!-- Items -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th>Nama Produk</th>
                    <th style="width: 10%;">Qty</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transaction['items'] as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td>
                            <strong><?= esc($item['nama_lengkap_barang']) ?></strong>
                            <?php if (!empty($item['sku'])): ?>
                                <br><span style="font-size: 6pt; color: #666;">SKU: <?= esc($item['sku']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?= number_format($item['jumlah'], 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            Terima kasih telah berbelanja di <?= esc($transaction['toko_name'] ?? 'HOPE') ?>
        </div>
    </div>
</body>

</html>