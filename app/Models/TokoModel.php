<?php

namespace App\Models;

class TokoModel extends TenantScopedModel
{
    protected $table = 'toko';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $allowedFields = [
        "tenant_id",
        "toko_name",
        "alamat",
        "phone_number",
        "email_toko",
        "image_logo",
        "bank",
        "nama_pemilik",
        "nomer_rekening",
        "bank_account_id",
        "cash_account_id",
        "provinsi",
        "kota_kabupaten",
        "kecamatan",
        "kelurahan",
        "kode_pos",
        "type",
        "tiktok_code",
        "tiktok_shop_cipher",
        "tiktok_access_token",
        "tiktok_refresh_token"
    ];
    protected $useSoftDeletes = true;
    protected $protectFields = true;

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}
