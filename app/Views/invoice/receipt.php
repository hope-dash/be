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
                    <?= esc($transaction['creator_name'] ?? 'Admin') ?>
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

        <!-- Service Details (for Service Receipt) -->
        <?php if ((isset($transaction['is_service']) && ($transaction['is_service'] == 1 || $transaction['is_service'] === true || $transaction['is_service'] === '1')) || !empty($transaction['services'])): ?>
            <div style="font-size: 10px; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 10px;">
                <p class="bold">DETAIL SERVICE:</p>
                <?php if (!empty($transaction['meta']['imei'])): ?>
                    <p>IMEI/SN: <?= esc($transaction['meta']['imei']) ?></p>
                <?php endif; ?>
                <?php if (!empty($transaction['nama_teknisi'])): ?>
                    <p>Teknisi: <?= esc($transaction['nama_teknisi']) ?></p>
                <?php endif; ?>
                <?php if (!empty($transaction['meta']['kerusakan'])): ?>
                    <p>Kerusakan: <?= esc($transaction['meta']['kerusakan']) ?></p>
                <?php endif; ?>
                <?php if (!empty($transaction['meta']['estimasi_selesai'])): ?>
                    <p>Estimasi: <?= esc($transaction['meta']['estimasi_selesai']) ?></p>
                <?php endif; ?>
                <?php if (!empty($transaction['meta']['keterangan_teknisi'])): ?>
                    <p>Ket. Teknisi: <?= esc($transaction['meta']['keterangan_teknisi']) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Items -->
        <div class="items-table">
            <?php
            $services = [];
            $products = [];
            foreach ($transaction['items'] as $item) {
                $isService = (isset($item['is_service']) && ($item['is_service'] == 1 || $item['is_service'] === true || $item['is_service'] === '1'));
                if ($isService) {
                    $services[] = $item;
                } else {
                    $products[] = $item;
                }
            }
            $isTrxService = (isset($transaction['is_service']) && ($transaction['is_service'] == 1 || $transaction['is_service'] === true || $transaction['is_service'] === '1')) || !empty($services);
            $subtotal = 0;

            $renderReceiptItems = function($items, $title, $showTech = false) use (&$subtotal) {
                if (empty($items)) return;
                ?>
                <p class="bold" style="font-size: 10px; margin-top: 5px; text-decoration: underline;"><?= esc(strtoupper($title)) ?></p>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 5px;">
                <?php
                foreach ($items as $item):
                    $itemTotal = $item['actual_total'] ?? (($item['harga_jual'] * $item['jumlah']) - ($item['diskon'] ?? 0));
                    $subtotal += $itemTotal;
                    ?>
                    <tr>
                        <td colspan="2" class="bold" style="font-size: 11px; padding-top: 5px;">
                            <?= esc($item['nama_lengkap_barang']) ?>
                            <?php if ($showTech && !empty($item['nama_teknisi'])): ?>
                                <span style="font-weight: normal; font-size: 9px; color: #333;"><br>*Teknisi: <?= esc($item['nama_teknisi']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size: 10px; text-align: left; color: #333; padding-bottom: 5px;">
                            <?= number_format($item['jumlah'], 0, ',', '.') ?> x Rp <?= number_format($item['harga_jual'], 0, ',', '.') ?>
                            <?php if (!empty($item['diskon'])): ?>
                                <br><span style="font-size: 9px; color: #555;">Diskon: - Rp <?= number_format($item['diskon'], 0, ',', '.') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="bold" style="font-size: 10px; text-align: right; vertical-align: top; padding-bottom: 5px;">
                            Rp <?= number_format($itemTotal, 0, ',', '.') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </table>
                <?php
            };

            if ($isTrxService) {
                $renderReceiptItems($services, 'Jasa Service', true);
                $renderReceiptItems($products, 'Produk / Sparepart', false);
            } else {
                $renderReceiptItems($transaction['items'], 'Daftar Produk', false);
            }
            ?>
        </div>

        <!-- Summary -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11px;">
            <tr>
                <td style="text-align: left; padding: 2px 0;">Subtotal:</td>
                <td style="text-align: right; padding: 2px 0;">Rp <?= number_format($subtotal, 0, ',', '.') ?></td>
            </tr>
            <?php if (!empty($transaction['discount_type'])): ?>
                <tr>
                    <td style="text-align: left; padding: 2px 0;">Diskon <?= $transaction['discount_type'] == 'PERCENTAGE' ? '(' . $transaction['discount_amount'] . '%)' : '' ?>:</td>
                    <td style="text-align: right; padding: 2px 0;">- Rp <?= number_format($transaction['meta']['tx_discount_value'] ?? 0, 0, ',', '.') ?></td>
                </tr>
            <?php endif; ?>
            <?php if (!empty($transaction['meta']['ppn']) && !empty($transaction['meta']['ppn_value'])): ?>
                <tr>
                    <td style="text-align: left; padding: 2px 0;">PPN (<?= esc($transaction['meta']['ppn']) ?>%):</td>
                    <td style="text-align: right; padding: 2px 0;">Rp <?= number_format($transaction['meta']['ppn_value'], 0, ',', '.') ?></td>
                </tr>
            <?php elseif (!empty($transaction['ppn'])): ?>
                <tr>
                    <td style="text-align: left; padding: 2px 0;">PPN:</td>
                    <td style="text-align: right; padding: 2px 0;">Rp <?= number_format($transaction['ppn'], 0, ',', '.') ?></td>
                </tr>
            <?php endif; ?>
            <?php if (!empty($transaction['meta']['biaya_pengiriman'])): ?>
                <tr>
                    <td style="text-align: left; padding: 2px 0;">Ongkir:</td>
                    <td style="text-align: right; padding: 2px 0;">
                        <?php if ($transaction['meta']['free_ongkir'] == 1): ?>
                            GRATIS
                        <?php else: ?>
                            Rp <?= number_format($transaction['meta']['biaya_pengiriman'], 0, ',', '.') ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php
            if (!empty($transaction['meta']['adjustments'])):
                $adjustments = json_decode($transaction['meta']['adjustments'], true) ?? [];
                foreach ($adjustments as $adj):
                    $isAddition = ($adj['type'] === 'addition');
                    $sign = $isAddition ? '+ Rp ' : '- Rp ';
                    ?>
                    <tr>
                        <td style="text-align: left; padding: 2px 0;"><?= esc($adj['component_name']) ?>:</td>
                        <td style="text-align: right; padding: 2px 0;"><?= $sign . number_format($adj['amount'], 0, ',', '.') ?></td>
                    </tr>
                    <?php
                endforeach;
            endif;
            ?>

            <?php if (!empty($transaction['meta']['points_used'])): ?>
                <tr>
                    <td style="text-align: left; padding: 2px 0;">Penggunaan Poin:</td>
                    <td style="text-align: right; padding: 2px 0;">- Rp <?= number_format($transaction['meta']['points_used'], 0, ',', '.') ?></td>
                </tr>
            <?php endif; ?>

            <?php if (!empty($transaction['meta']['moota_unique_code'])): ?>
                <tr>
                    <td style="text-align: left; padding: 2px 0;">Kode Unik Transfer:</td>
                    <td style="text-align: right; padding: 2px 0;">+ Rp <?= number_format((int)$transaction['meta']['moota_unique_code'], 0, ',', '.') ?></td>
                </tr>
            <?php endif; ?>

            <tr style="font-weight: bold; border-top: 1px solid #000; border-bottom: 1px solid #000; font-size: 12px;">
                <td style="text-align: left; padding: 5px 0;">TOTAL:</td>
                <td style="text-align: right; padding: 5px 0;">
                    <?php
                    $grandTotal = $transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal;
                    if (!empty($transaction['meta']['moota_unique_code'])) {
                        $grandTotal += (int)$transaction['meta']['moota_unique_code'];
                    }
                    ?>
                    Rp <?= number_format($grandTotal, 0, ',', '.') ?>
                </td>
            </tr>
        </table>

        <!-- Payment Info -->
        <?php if (!empty($transaction['total_paid']) || !empty($transaction['payments'])): ?>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10px; border-bottom: 1px dashed #000; padding-bottom: 8px;">
                <?php if (!empty($transaction['total_paid'])): ?>
                    <tr>
                        <td class="bold" style="text-align: left; padding: 2px 0;">Dibayar:</td>
                        <td style="text-align: right; padding: 2px 0;">Rp <?= number_format($transaction['total_paid'], 0, ',', '.') ?></td>
                    </tr>
                    <tr>
                        <td class="bold" style="text-align: left; padding: 2px 0;">Kembalian:</td>
                        <td style="text-align: right; padding: 2px 0;">Rp <?= number_format(max(0, $transaction['total_paid'] - ($transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal)), 0, ',', '.') ?></td>
                    </tr>
                    <?php if ($transaction['total_paid'] < ($transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal)): ?>
                        <tr>
                            <td class="bold" style="text-align: left; padding: 2px 0;">Sisa:</td>
                            <td style="text-align: right; padding: 2px 0;">Rp <?= number_format(($transaction['actual_total'] ?? $transaction['total_harga'] ?? $subtotal) - $transaction['total_paid'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
            </table>
        <?php endif; ?>

        <!-- Bank Info -->
        <?php
        $trxStatus = strtoupper($transaction['status'] ?? '');
        if ($trxStatus === 'WAITING_PAYMENT' || $trxStatus === 'PARTIALLY_PAID'):
            $bankName = !empty($transaction['meta']['moota_bank_type']) ? $transaction['meta']['moota_bank_type'] : ($transaction['bank'] ?? '');
            $accNo = !empty($transaction['meta']['moota_nomer_rekening']) ? $transaction['meta']['moota_nomer_rekening'] : ($transaction['nomer_rekening'] ?? '');
            $ownerName = $transaction['nama_pemilik'] ?? '';
            $hasMoota = !empty($transaction['meta']['moota_unique_code']);

            if (!empty($bankName) || !empty($accNo)):
                ?>
                <div class="payment-section">
                    <p class="bold text-center">INFORMASI TRANSFER</p>
                    <?php if (!empty($bankName)): ?>
                        <div class="payment-row">
                            <span>Bank:</span>
                            <span>
                                <?= esc($bankName) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($accNo)): ?>
                        <div class="payment-row">
                            <span>No. Rek:</span>
                            <span>
                                <?= esc($accNo) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($ownerName)): ?>
                        <div class="payment-row">
                            <span>A/n:</span>
                            <span>
                                <?= esc($ownerName) ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasMoota): ?>
                        <div style="margin-top: 5px; padding: 5px; border: 1px dashed #000; font-size: 10px;">
                            <div class="payment-row">
                                <span class="bold">Kode Unik:</span>
                                <span class="bold"><?= esc($transaction['meta']['moota_unique_code']) ?></span>
                            </div>
                            <?php if (!empty($transaction['meta']['moota_unique_note'])): ?>
                                <div class="payment-row">
                                    <span>Catatan:</span>
                                    <span class="bold"><?= esc($transaction['meta']['moota_unique_note']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php
                            $totalTransfer = (float) ($transaction['actual_total'] ?? 0) + (int) $transaction['meta']['moota_unique_code'];
                            ?>
                            <div style="margin-top: 3px; text-align: center; font-size: 9px;">
                                Mohon transfer tepat <strong style="text-decoration: underline;">Rp
                                    <?= number_format($totalTransfer, 0, ',', '.') ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
            endif;
        endif;
        ?>

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