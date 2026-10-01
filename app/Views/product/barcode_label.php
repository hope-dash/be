<?php
$safeKode = htmlspecialchars($kode_barang ?? '');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    body {
        font-family: DejaVu Sans, sans-serif;
    }
    .label {
        width: <?= $width_mm ?>mm;
        height: <?= $height_mm ?>mm;
        text-align: center;
    }
    .label-break {
        page-break-after: always;
    }
    .barcode-img {
        display: block;
        margin: 0.5mm auto 0;
        width: auto;
        height: <?= max(round($height_mm * 0.5), 5) ?>mm;
        max-width: 92%;
    }
    .kode-barang {
        margin-top: 0.3mm;
        font-size: 7px;
        line-height: 1;
        letter-spacing: 0.5px;
    }
</style>
</head>
<body>
<?php for ($i = 0; $i < $qty; $i++): ?>
    <div class="label<?= $i < $qty - 1 ? ' label-break' : '' ?>">
        <img class="barcode-img" src="data:image/png;base64,<?= $barcodeBase64 ?>" alt="barcode">
        <div class="kode-barang"><?= $safeKode ?></div>
    </div>
<?php endfor; ?>
</body>
</html>
