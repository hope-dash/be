<?php
$files = [
    '/Users/mac/Documents/Freelance/hope/be/app/Controllers/TransactionControllerV2.php',
    '/Users/mac/Documents/Freelance/hope/be/app/Controllers/CustomerTransactionControllerV2.php'
];

$replacements = [
    "Invalid action: \$action. Use ACCEPT or REJECT." => "Aksi tidak valid: \$action. Gunakan ACCEPT atau REJECT.",
    "Database transaction failed" => "Transaksi database gagal",
    "Failed to update transaction" => "Gagal memperbarui transaksi",
    "Calculation successful" => "Kalkulasi berhasil",
    "No pending payment found for this transaction" => "Tidak ada pembayaran tertunda yang ditemukan untuk transaksi ini",
    "Payment \" . strtolower(\$action) . \"ed successfully" => "Pembayaran berhasil di\" . strtolower(\$action)",
];

foreach ($files as $file) {
    if (!file_exists($file))
        continue;
    $content = file_get_contents($file);
    foreach ($replacements as $eng => $ind) {
        $content = str_replace('"' . $eng . '"', '"' . $ind . '"', $content);
        $content = str_replace("'" . $eng . "'", "'" . $ind . "'", $content);
    }
    // Handle the complex string on line 605 separately if needed:
    $content = str_replace('"Payment " . strtolower($action) . "ed successfully"', '"Pembayaran berhasil di" . strtolower($action)', $content);
    file_put_contents($file, $content);
}
echo "Done replacing remaining strings\n";
