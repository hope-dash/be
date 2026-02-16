<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk #
        <?= $transaction['invoice_number'] ?? $transaction['id'] ?>
    </title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            color: #000;
            background: #fff;
            line-height: 1.4;
            width: 70mm;
            margin: 0 auto;
        }

        .receipt-container {
            width: 70mm;
            padding: 3mm;
        }

        .header {
            text-align: center;
            margin-bottom: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }

        .header h1 {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .header p {
            font-size: 10px;
            margin: 2px 0;
        }

        .info-section {
            margin-bottom: 10px;
            font-size: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
        }

        .info-label {
            font-weight: bold;
        }

        .customer-section {
            margin-bottom: 10px;
            font-size: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .customer-section p {
            margin: 2px 0;
        }

        .items-table {
            width: 100%;
            margin-bottom: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .item-row {
            margin-bottom: 8px;
        }

        .item-name {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 2px;
        }

        .item-details {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
        }

        .item-qty-price {
            display: flex;
            gap: 10px;
        }

        .summary {
            margin-bottom: 10px;
            font-size: 11px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
        }

        .summary-row.total {
            font-weight: bold;
            font-size: 12px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 5px 0;
            margin-top: 5px;
        }

        .payment-section {
            margin-bottom: 10px;
            font-size: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
        }

        .footer {
            text-align: center;
            margin-top: 10px;
            font-size: 9px;
        }

        .footer p {
            margin: 3px 0;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .separator {
            border-bottom: 1px dashed #000;
            margin: 8px 0;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }

            .receipt-container {
                padding: 3mm;
            }

            @page {
                margin: 0;
            }
        }
    </style>
</head>

<body>
    <div class="receipt-container">
        <!-- Header -->
        <div class="header">
            <h1>
                <?= strtoupper(esc($transaction['toko_name'] ?? 'NAMA TOKO')) ?>
            </h1>
            <p>
                <?= esc($transaction['toko_alamat'] ?? '') ?>
            </p>
            <p>Telp:
                <?= esc($transaction['toko_phone'] ?? '') ?>
            </p>
            <?php if (!empty($transaction['email_toko'])): ?>
                <p>
                    <?= esc($transaction['email_toko']) ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Transaction Info -->
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">No. Struk:</span>
                <span>
                    <?= esc($transaction['invoice_number'] ?? 'INV-' . str_pad($transaction['id'], 6, '0', STR_PAD_LEFT)) ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Tanggal:</span>
                <span>
                    <?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Kasir:</span>
                <span>
                    <?= esc($transaction['meta']['kasir'] ?? 'Admin') ?>
                </span>
            </div>
        </div>

        <!-- Customer Info -->
        <?php if (!empty($transaction['meta']['customer_name'])): ?>
            <div class="customer-section">
                <p class="bold">KEPADA:</p>
                <p>
                    <?= esc($transaction['meta']['customer_name']) ?>
                </p>
                <?php if (!empty($transaction['meta']['customer_phone'])): ?>
                    <p>Telp:
                        <?= esc($transaction['meta']['customer_phone']) ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($transaction['meta']['kota_kabupaten'])): ?>
                    <p>
                        <?= esc($transaction['meta']['kota_kabupaten']) ?>,
                        <?= esc($transaction['meta']['provinsi'] ?? '') ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Items -->
        <div class="items-table">
            <?php
            $subtotal = 0;
            foreach ($transaction['items'] as $item):
                $itemTotal = ($item['harga_jual'] * $item['jumlah']) - ($item['diskon'] ?? 0);
                $subtotal += $itemTotal;
                ?>
                <div class="item-row">
                    <div class="item-name">
                        <?= esc($item['nama_lengkap_barang']) ?>
                    </div>
                    <div class="item-details">
                        <div class="item-qty-price">
                            <span>
                                <?= number_format($item['jumlah'], 0, ',', '.') ?> x
                            </span>
                            <span>Rp
                                <?= number_format($item['harga_jual'], 0, ',', '.') ?>
                            </span>
                        </div>
                        <span class="bold">Rp
                            <?= number_format($itemTotal, 0, ',', '.') ?>
                        </span>
                    </div>
                    <?php if (!empty($item['diskon'])): ?>
                        <div class="item-details">
                            <span>Diskon:</span>
                            <span>- Rp
                                <?= number_format($item['diskon'], 0, ',', '.') ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Summary -->
        <div class="summary">
            <div class="summary-row">
                <span>Subtotal:</span>
                <span>Rp
                    <?= number_format($subtotal, 0, ',', '.') ?>
                </span>
            </div>
            <?php if (!empty($transaction['discount_type'])): ?>
                <div class="summary-row">
                    <span>Diskon
                        <?= $transaction['discount_type'] == 'PERCENTAGE' ? '(' . $transaction['discount_amount'] . '%)' : '' ?>:
                    </span>
                    <span>- Rp
                        <?= number_format($transaction['meta']['tx_discount_value'] ?? 0, 0, ',', '.') ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if (!empty($transaction['ppn'])): ?>
                <div class="summary-row">
                    <span>PPN:</span>
                    <span>Rp
                        <?= number_format($transaction['ppn'], 0, ',', '.') ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if (!empty($transaction['meta']['biaya_pengiriman'])): ?>
                <div class="summary-row">
                    <span>Ongkir:</span>
                    <span>
                        <?php if ($transaction['meta']['free_ongkir'] == 1): ?>
                            GRATIS
                        <?php else: ?>
                            Rp <?= number_format($transaction['meta']['biaya_pengiriman'], 0, ',', '.') ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
            <div class="summary-row total">
                <span>TOTAL:</span>
                <span>Rp
                    <?= number_format($transaction['total_harga'] ?? $subtotal, 0, ',', '.') ?>
                </span>
            </div>
        </div>

        <!-- Payment Info -->
        <?php if (!empty($transaction['total_paid']) || !empty($transaction['payments'])): ?>
            <div class="payment-section">
                <?php if (!empty($transaction['total_paid'])): ?>
                    <div class="payment-row">
                        <span class="bold">Dibayar:</span>
                        <span>Rp
                            <?= number_format($transaction['total_paid'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <div class="payment-row">
                        <span class="bold">Kembalian:</span>
                        <span>Rp
                            <?= number_format(max(0, $transaction['total_paid'] - ($transaction['total_harga'] ?? $subtotal)), 0, ',', '.') ?>
                        </span>
                    </div>
                    <?php if ($transaction['total_paid'] < ($transaction['total_harga'] ?? $subtotal)): ?>
                        <div class="payment-row">
                            <span class="bold">Sisa:</span>
                            <span>Rp
                                <?= number_format(($transaction['total_harga'] ?? $subtotal) - $transaction['total_paid'], 0, ',', '.') ?>
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

        <!-- Bank Info -->
        <?php if (!empty($transaction['bank']) || !empty($transaction['nomer_rekening'])): ?>
            <div class="payment-section">
                <p class="bold text-center">INFORMASI TRANSFER</p>
                <?php if (!empty($transaction['bank'])): ?>
                    <div class="payment-row">
                        <span>Bank:</span>
                        <span>
                            <?= esc($transaction['bank']) ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($transaction['nomer_rekening'])): ?>
                    <div class="payment-row">
                        <span>No. Rek:</span>
                        <span>
                            <?= esc($transaction['nomer_rekening']) ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($transaction['nama_pemilik'])): ?>
                    <div class="payment-row">
                        <span>A/n:</span>
                        <span>
                            <?= esc($transaction['nama_pemilik']) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if (!empty($transaction['notes']) || !empty($transaction['meta']['notes'])): ?>
            <div class="separator"></div>
            <div style="margin-bottom: 10px; font-size: 10px;">
                <p class="bold">Catatan:</p>
                <p>
                    <?= esc($transaction['notes'] ?? $transaction['meta']['notes'] ?? '') ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p class="bold">TERIMA KASIH</p>
            <p>Barang yang sudah dibeli tidak dapat ditukar/dikembalikan</p>
            <div class="separator"></div>
            <p>
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