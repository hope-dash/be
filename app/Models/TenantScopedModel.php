<?php

namespace App\Models;

use App\Libraries\TenantContext;
use CodeIgniter\Model;

/**
 * Base model that automatically scopes queries by `tenant_id`.
 *
 * Important:
 * - Requires `tenant_id` column on `$table`.
 * - Scope is applied only when `TenantContext::id()` is available (API requests).
 */
class TenantScopedModel extends Model
{
    protected bool $useTenantScope = true;
    protected $beforeInsert = ['applyTenantId'];
    protected $beforeUpdate = ['preventTenantChange'];

    public function builder($table = null)
    {
        $builder = parent::builder($table);

        if (
            $this->useTenantScope
            && TenantContext::id() > 0
            && ($table === null || $table === $this->table)
        ) {
            $builder->where($this->table . '.tenant_id', TenantContext::id());
        }

        return $builder;
    }

    protected function applyTenantId(array $data): array
    {
        if (!$this->useTenantScope || TenantContext::id() <= 0) {
            return $data;
        }

        if (!isset($data['data'])) {
            return $data;
        }

        // insert()
        if (is_array($data['data']) && (empty($data['data']) || array_is_list($data['data']) === false)) {
            $data['data']['tenant_id'] = $data['data']['tenant_id'] ?? TenantContext::id();
            return $data;
        }

        // insertBatch()
        if (is_array($data['data']) && array_is_list($data['data'])) {
            foreach ($data['data'] as &$row) {
                if (is_array($row)) {
                    $row['tenant_id'] = $row['tenant_id'] ?? TenantContext::id();
                }
            }
            unset($row);
        }

        return $data;
    }

    protected function preventTenantChange(array $data): array
    {
        if (isset($data['data']) && is_array($data['data']) && array_key_exists('tenant_id', $data['data'])) {
            unset($data['data']['tenant_id']);
        }

        return $data;
    }
}
