<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTenantDomainsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tenant_code' => ['type' => 'VARCHAR', 'constraint' => 50],
            'domain' => ['type' => 'VARCHAR', 'constraint' => 255],
            'type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'is_verified' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'vercel_domain_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('domain');
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('tenant_domains');
    }

    public function down()
    {
        $this->forge->dropTable('tenant_domains');
    }
}
