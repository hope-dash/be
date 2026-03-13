<?php

namespace App\Models;

class SalesProductModel extends TenantScopedModel
{
    protected $table = 'sales_product';
    protected $primaryKey = 'id';
    protected $allowedFields = ['tenant_id', 'id_transaction', 'actual_per_piece', 'actual_total', 'kode_barang', 'jumlah', 'harga_system', 'harga_jual', 'total', 'modal_system', 'total_modal', 'margin', 'closing', 'dropship_suplier', 'discount_type', 'discount_amount'];
}
