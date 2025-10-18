<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDeletedAtToProductTable extends Migration
{
    public function up()
    {
        $fields = [
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'updated_by'
            ]
        ];
        $this->forge->addColumn('product', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('product', 'deleted_at');
    }
}
