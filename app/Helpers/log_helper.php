<?php

use Config\Database;
use App\Libraries\TenantContext;

if (!function_exists('log_aktivitas')) {
    /**
     * Simpan log aktivitas ke tabel log_aktivitas
     * @param array $data Contoh keys: user_id, action_type, target_table, target_id, description, detail
     * @return bool
     */
    function log_aktivitas(array $data)
    {
        $db = Database::connect();
        $builder = $db->table('log_aktivitas');

        $logData = [
            'tenant_id' => TenantContext::id() ?: null,
            'user_id' => $data['user_id'] ?? null,
            'action_type' => $data['action_type'] ?? null,
            'target_table' => $data['target_table'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'description' => $data['description'] ?? '',
            'detail' => isset($data['detail']) ? json_encode($data['detail']) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        return $builder->insert($logData);
    }
}

if (!function_exists('log_barang_cacat_transaksi')) {
    /** Record damaged stock from a transaction with its invoice reference. */
    function log_barang_cacat_transaksi(array $data, $transactionId)
    {
        $transaction = Database::connect()->table('transaction')
            ->select('id, invoice')
            ->where('id', $transactionId)
            ->where('tenant_id', TenantContext::id())
            ->get()->getRowArray();

        $invoice = $transaction['invoice'] ?? null;
        $data['action_type'] = 'MOVE_TO_CACAT';
        $data['description'] = ($data['description'] ?? '')
            . ($invoice ? " Invoice: {$invoice}." : " Transaksi: #{$transactionId}.");
        $data['detail'] = array_merge($data['detail'] ?? [], [
            'transaction_id' => $transactionId,
            'invoice' => $invoice,
        ]);

        return log_aktivitas($data);
    }
}
