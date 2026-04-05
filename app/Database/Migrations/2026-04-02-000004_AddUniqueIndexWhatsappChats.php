<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUniqueIndexWhatsappChats extends Migration
{
    public function up()
    {
        // Add unique index for phone per tenant to prevent duplicates
        $this->db->query('ALTER TABLE whatsapp_chats ADD UNIQUE INDEX idx_tenant_phone (tenant_id, phone)');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE whatsapp_chats DROP INDEX idx_tenant_phone');
    }
}
