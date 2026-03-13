<?php

namespace App\Models;

class JournalItemModel extends TenantScopedModel
{
    protected $table = 'journal_items';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
        'journal_id', 'account_id', 'debit', 'credit', 
        'created_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = ''; // No updated_at needed for items usually
}
