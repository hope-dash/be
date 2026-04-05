<?php

namespace App\Models;

use CodeIgniter\Model;

class TokoMetaModel extends Model
{
    protected $table = 'toko_meta';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_toko',
        'meta_key',
        'meta_value'
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Set a meta value for a shop
     * 
     * @param int $id_toko
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setMeta(int $id_toko, string $key, $value): bool
    {
        $existing = $this->where([
            'id_toko' => $id_toko,
            'meta_key' => $key
        ])->first();

        $data = [
            'id_toko' => $id_toko,
            'meta_key' => $key,
            'meta_value' => is_array($value) ? json_encode($value) : $value
        ];

        if ($existing) {
            return $this->update($existing['id'], $data);
        }

        return $this->insert($data) !== false;
    }

    /**
     * Get a meta value for a shop
     * 
     * @param int $id_toko
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMeta(int $id_toko, string $key, $default = null)
    {
        $row = $this->where([
            'id_toko' => $id_toko,
            'meta_key' => $key
        ])->first();

        if (!$row) return $default;

        $value = $row['meta_value'];
        
        // Try to json decode if it looks like json
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
            return $decoded;
        }

        return $value;
    }
}
