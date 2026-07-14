<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLaporanServiceOnStoreQuotaToPackages extends Migration
{
    public function up()
    {
        $packageFields = [
            'laporan'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'service_on'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'store_quota' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'default' => null],
        ];

        if ($this->db->tableExists('subscription_packages')) {
            $existing = $this->db->getFieldNames('subscription_packages');
            $toAdd = [];
            foreach ($packageFields as $col => $def) {
                if (!in_array($col, $existing)) {
                    $toAdd[$col] = $def;
                }
            }
            if (!empty($toAdd)) {
                $this->forge->addColumn('subscription_packages', $toAdd);
            }
        }

        $quotaFields = [
            'laporan'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'service_on' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ];

        if ($this->db->tableExists('tenant_quota')) {
            $existing = $this->db->getFieldNames('tenant_quota');
            $toAdd = [];
            foreach ($quotaFields as $col => $def) {
                if (!in_array($col, $existing)) {
                    $toAdd[$col] = $def;
                }
            }
            if (!empty($toAdd)) {
                $this->forge->addColumn('tenant_quota', $toAdd);
            }
        }
    }

    public function down()
    {
        $packageCols = ['laporan', 'service_on', 'store_quota'];
        if ($this->db->tableExists('subscription_packages')) {
            $existing = $this->db->getFieldNames('subscription_packages');
            $toDrop = array_intersect($packageCols, $existing);
            if (!empty($toDrop)) {
                $this->forge->dropColumn('subscription_packages', $toDrop);
            }
        }

        $quotaCols = ['laporan', 'service_on'];
        if ($this->db->tableExists('tenant_quota')) {
            $existing = $this->db->getFieldNames('tenant_quota');
            $toDrop = array_intersect($quotaCols, $existing);
            if (!empty($toDrop)) {
                $this->forge->dropColumn('tenant_quota', $toDrop);
            }
        }
    }
}
