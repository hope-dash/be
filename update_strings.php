<?php
$files = [
    '/Users/mac/Documents/Freelance/hope/be/app/Controllers/TransactionControllerV2.php',
    '/Users/mac/Documents/Freelance/hope/be/app/Controllers/CustomerTransactionControllerV2.php'
];

$replacements = [
    "Items cannot be empty" => "Item tidak boleh kosong",
    "Item code/id missing" => "Kode/ID item tidak ditemukan",
    "Product %s not found" => "Produk %s tidak ditemukan",
    "Product {$idBarang} not found" => "Produk {$idBarang} tidak ditemukan",
    "Insufficient stock for " => "Stok kurang untuk ",
    "Transaction not found" => "Transaksi tidak ditemukan",
    "Transaction ID required" => "ID Transaksi wajib diisi",
    "Transaction ID and proof image are required" => "ID Transaksi dan bukti pembayaran wajib diisi",
    "Transaction failed to save" => "Transaksi gagal disimpan",
    "Amount must be greater than 0" => "Jumlah harus lebih besar dari 0",
    "Transaction adjusted successfully" => "Transaksi berhasil disesuaikan",
    "Return processed successfully" => "Retur berhasil diproses",
    "Refund processed successfully" => "Refund berhasil diproses",
    "At least one field (status, resi, courier) is required" => "Parameter status, resi, atau kurir wajib diisi",
    "Delivery status updated successfully" => "Status pengiriman berhasil diperbarui",
    "ID is required" => "ID wajib diisi",
    "Unauthorized: You do not have permission to view this transaction." => "Akses Ditolak: Anda tidak memiliki izin untuk melihat transaksi ini.",
    "Key and value are required" => "Key dan value wajib diisi",
    "Transaction meta updated successfully" => "Meta transaksi berhasil diperbarui",
    "Payment amount cannot be empty or zero" => "Jumlah pembayaran tidak boleh kosong atau nol",
    "Payment added successfully" => "Pembayaran berhasil ditambahkan",
    "Payment verified successfully" => "Pembayaran berhasil diverifikasi",
    "Payment record not found" => "Data pembayaran tidak ditemukan",
    "Failed to update transaction status" => "Gagal memperbarui status transaksi",
    "Transaction cancelled successfully" => "Transaksi berhasil dibatalkan",
    "Transaction created successfully" => "Transaksi berhasil dibuat",
    "Product ID and quantity are required" => "ID Produk dan jumlah wajib diisi",
    "Cart item incremented" => "Jumlah item keranjang berhasil ditambah",
    "Item added to cart" => "Item berhasil ditambahkan ke keranjang",
    "Quantity is required" => "Jumlah wajib diisi",
    "Item not found in your cart" => "Item tidak ditemukan di keranjang Anda",
    "Cart item updated" => "Item keranjang berhasil diperbarui",
    "Cart cleared" => "Keranjang berhasil dikosongkan",
    "Item removed from cart" => "Item berhasil dihapus dari keranjang",
    "Customer not found" => "Pelanggan tidak ditemukan",
    "No cart items selected" => "Tidak ada item keranjang yang dipilih",
    "Selected cart items not found" => "Item keranjang yang dipilih tidak ditemukan",
    "Database transaction failed" => "Transaksi database gagal",
    "Checkout successful" => "Checkout berhasil",
    "Unauthorized access to this transaction" => "Akses tidak sah ke transaksi ini",
    "Payment proof uploaded successfully. Waiting for verification." => "Bukti pembayaran berhasil diunggah. Menunggu verifikasi.",
    "Transactions fetched" => "Data transaksi berhasil diambil",
    "Success" => "Sukses"
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    foreach ($replacements as $eng => $ind) {
        $content = str_replace('"' . $eng . '"', '"' . $ind . '"', $content);
        $content = str_replace("'" . $eng . "'", "'" . $ind . "'", $content);
    }
    file_put_contents($file, $content);
}
echo "Done replacing strings\n";
