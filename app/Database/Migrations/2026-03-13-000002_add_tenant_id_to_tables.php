<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTenantIdToTables extends Migration
{
    /**
     * Tables that should be tenant-scoped.
     *
     * Notes:
     * - Geographical reference tables (province/city/etc) are intentionally excluded.
     * - This migration is defensive: it checks table/field existence before altering.
     */
    private array $tenantTables = [
        'users',
        'toko',
        'customer',
        'suplier',
        'model_barang',
        'seri',
        'product',
        'stock',
        'transaction',
        'sales_product',
        'transaction_meta',
        'transaction_payments',
        'cashflow',
        'retur',
        'pembelian',
        'pembelian_detail',
        'pembelian_biaya',
        'transaction_closing',
        'closing_detail',
        'supplier_closing',
        'voucher',
        'cart',
        'expenses',
        'accounts',
        'journals',
        'journal_items',
        'stock_ledgers',
        'log_aktivitas',
        'email_queue',
        'image',
    ];

    public function up()
    {
        // Ensure default tenant exists for backfill.
        $hopeId = $this->ensureTenant('hope', 'HOPE');

        foreach ($this->tenantTables as $table) {
            if (!$this->db->tableExists($table)) {
                continue;
            }

            if (!$this->db->fieldExists('tenant_id', $table)) {
                $after = $this->guessAfterColumn($table);
                $column = [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ];
                if ($after !== '') {
                    $column['after'] = $after;
                }

                $this->forge->addColumn($table, ['tenant_id' => $column]);

                // Index for scoping queries.
                $indexName = "idx_{$table}_tenant_id";
                if (!$this->indexExists($table, $indexName)) {
                    $this->db->query("CREATE INDEX {$indexName} ON {$table}(tenant_id)");
                }
            }

            // Backfill existing rows to HOPE tenant.
            if ($hopeId > 0) {
                $this->db->query("UPDATE {$table} SET tenant_id = {$hopeId} WHERE tenant_id IS NULL");
            }
        }
    }

    public function down()
    {
        foreach ($this->tenantTables as $table) {
            if (!$this->db->tableExists($table)) {
                continue;
            }

            if ($this->db->fieldExists('tenant_id', $table)) {
                // Drop index if exists (MySQL).
                $indexName = "idx_{$table}_tenant_id";
                if ($this->indexExists($table, $indexName)) {
                    try {
                        $this->db->query("DROP INDEX {$indexName} ON {$table}");
                    } catch (\Throwable $e) {
                        // Best-effort rollback.
                    }
                }
                $this->forge->dropColumn($table, 'tenant_id');
            }
        }
    }

    private function ensureTenant(string $code, string $name): int
    {
        if (!$this->db->tableExists('tenants')) {
            return 0;
        }

        $row = $this->db->table('tenants')->where('code', $code)->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        $this->db->table('tenants')->insert([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) ($this->db->insertID() ?: 0);
    }

    private function guessAfterColumn(string $table): string
    {
        // Keep schema changes minimally invasive. Try common PK columns first.
        $common = ['id', 'user_id'];
        foreach ($common as $col) {
            if ($this->db->fieldExists($col, $table)) {
                return $col;
            }
        }

        // Fallback: MySQL requires an existing column name for "after".
        // If no common PK found, omit "after" by returning an empty string
        // and letting CodeIgniter place it at the end.
        return '';
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $rows = $this->db->query("SHOW INDEX FROM {$table} WHERE Key_name = " . $this->db->escape($indexName))->getResultArray();
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
