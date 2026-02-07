<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTransactionMetaTable extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('transaction_meta')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'transaction_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                ],
                'key' => [
                    'type'       => 'VARCHAR',
                    'constraint' => '100',
                ],
                'value' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('transaction_id');
            $this->forge->createTable('transaction_meta');
        }

        // Add 'po' column to Transaction if not exists
        // Add 'po' column to Transaction if not exists
        $fields = $this->db->getFieldData('transaction');
        $hasPo = false;
        foreach ($fields as $field) {
            if ($field->name === 'po') {
                $hasPo = true;
                break;
            }
        }
        
        if (!$hasPo) {
             try {
                 $this->forge->addColumn('transaction', [
                     'po' => [
                         'type' => 'BOOLEAN',
                         'default' => false,
                         'after' => 'status'
                     ]
                 ]);
             } catch (\Throwable $e) {
                 // Ignore if exists
             }
        }
    }

    public function down()
    {
        // Dropping is risky if table existed before, but for revert:
        if ($this->db->tableExists('transaction_meta')) {
             $this->forge->dropTable('transaction_meta');
        }
        if ($this->db->fieldExists('po', 'transaction')) {
             $this->forge->dropColumn('transaction', 'po');
        }
    }
}
