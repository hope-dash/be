<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFieldsToSubscriptionPackages extends Migration
{
    public function up()
    {
        $fields = [
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'null'       => true,
                'after'      => 'name'
            ],
            'wording' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
                'after'      => 'type'
            ],
            'description' => [
                'type'       => 'TEXT',
                'null'       => true,
                'after'      => 'wording'
            ],
        ];
        $this->forge->addColumn('subscription_packages', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('subscription_packages', ['type', 'wording', 'description']);
    }
}
