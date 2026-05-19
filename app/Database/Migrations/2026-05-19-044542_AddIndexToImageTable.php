<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexToImageTable extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('index', 'image')) {
            $this->forge->addColumn('image', [
                'index' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'default'    => 0,
                    'null'       => false,
                    'after'      => 'url'
                ]
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('index', 'image')) {
            $this->forge->dropColumn('image', 'index');
        }
    }
}
