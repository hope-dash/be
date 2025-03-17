<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
class Migration_Create_image_table extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => array(
                'type' => 'INT',
                'auto_increment' => TRUE,
            ),
            'type' => array(
                'type' => 'VARCHAR',
                'constraint' => '50',
            ),
            'kode' => array(
                'type' => 'VARCHAR',
                'constraint' => '255',
            ),
            'url' => array(
                'type' => 'TEXT',
            ),
        ]);

        $this->forge->addKey("id", primary: true);
        $this->forge->createTable("image");


    }

    public function down()
    {
        $this->forge->dropTable("image");
    }
}
