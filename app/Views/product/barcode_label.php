<?php
$safeKode = htmlspecialchars($kode_barang ?? '');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page {
        margin: 0;
        size: <?= $width_mm ?>mm <?= $height_mm ?>mm;
    }
    body {
        margin: 0;
        padding: 0;
        font-family: DejaVu Sans, sans-serif;
    }
    .label {
        box-sizing: border-box;
        width: <?= $width_mm ?>mm;
        height: <?= $height_mm ?>mm;
        padding: 1mm;
        text-align: center;
        overflow: hidden;
    }
    .label-break {
        page-break-after: always;
    }
    .barcode-img {
        width: auto;
        height: <?= max($height_mm - 4, 6) ?>mm;
        max-width: 100%;
    }
    .kode-barang {
        font-size: 7px;
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
