<?php

use Config\Database;

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
