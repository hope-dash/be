<?php

if (!function_exists('generateTransactionLog')) {
    /**
     * Membuat deskripsi perubahan transaksi dalam bentuk string naratif
     *
     * @param int $userId
     * @param array $oldTransaction
     * @param array $newTransaction
     * @param array $oldItems (kode_barang => item array)
     * @param array $newItems (array of stdClass dari request)
     * @return string
     */
    function generateTransactionLog(int $userId, array $oldTransaction, array $newTransaction, array $oldItems, array $newItems): string
    {
        $logDescription = "Transaksi diperbarui oleh user {$userId}. ";

        // Bandingkan field transaksi
        foreach ($newTransaction as $key => $newVal) {
            $oldVal = $oldTransaction[$key] ?? null;
            if ($oldVal != $newVal && !in_array($key, ['updated_by', 'date_time'])) {
                $logDescription .= ucfirst($key) . " berubah dari {$oldVal} menjadi {$newVal}. ";
            }
        }

        // Buat map item baru (kode_barang => object)
        $newItemMap = [];
        foreach ($newItems as $item) {
            $newItemMap[$item->kode_barang] = $item;
        }

        // Cek perubahan dan penambahan item
        foreach ($newItemMap as $kode => $item) {
            if (!isset($oldItems[$kode])) {
                $logDescription .= "Item {$kode} ditambahkan dengan jumlah {$item->jumlah} dan harga jual {$item->harga_jual}. ";
            } else {
                $oldItem = $oldItems[$kode];
                if ($oldItem['jumlah'] != $item->jumlah) {
                    $logDescription .= "Item {$kode} jumlah berubah dari {$oldItem['jumlah']} menjadi {$item->jumlah}. ";
                }
                if ($oldItem['harga_jual'] != $item->harga_jual) {
                    $logDescription .= "Item {$kode} harga jual berubah dari {$oldItem['harga_jual']} menjadi {$item->harga_jual}. ";
                }
            }
        }

        // Cek item yang dihapus
        foreach ($oldItems as $kode => $item) {
            if (!isset($newItemMap[$kode])) {
                $logDescription .= "Item {$kode} dihapus dari transaksi. ";
            }
        }

        return trim($logDescription);
    }
}
