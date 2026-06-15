<?php

namespace App\Models;

class StockModel extends TenantScopedModel
{
    protected $table = 'stock';
    protected $primaryKey = 'id';
    protected $allowedFields = ['tenant_id', 'id_barang', 'id_toko', 'stock', 'barang_cacat', 'dropship', 'tiktok_product_id', 'product_tiktok_status', 'product_tokopedia_status'];

    protected $afterInsert = ['syncToTiktok'];
    protected $afterUpdate = ['syncToTiktok'];

    protected function syncToTiktok(array $data)
    {
        $ids = [];
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
        }

        if (empty($ids) && isset($data['data']['id'])) {
            $ids = [$data['data']['id']];
        }

        if (empty($ids)) {
            return $data;
        }

        $db = \Config\Database::connect();
        foreach ($ids as $id) {
            $stock = $db->table($this->table)->where('id', $id)->get()->getRowArray();
            if ($stock) {
                $product = $db->table('product')
                              ->where('id_barang', $stock['id_barang'])
                              ->where('tenant_id', $stock['tenant_id'])
                              ->get()
                              ->getRowArray();

                if ($product && !empty($product['tiktok_product_id'])) {
                    try {
                        $tiktokService = new \App\Libraries\TiktokService();
                        $tiktokService->syncProductStock((int)$product['id'], (int)$stock['id_toko']);
                    } catch (\Exception $ex) {
                        log_message('error', '[StockModel Hook] Failed to auto-sync stock to TikTok: ' . $ex->getMessage());
                    }
                }
            }
        }

        return $data;
    }
}
