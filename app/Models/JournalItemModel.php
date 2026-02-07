<?php

namespace App\Models;

use CodeIgniter\Model;

class JournalItemModel extends Model
{
    protected $table = 'journal_items';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'journal_id', 'account_id', 'debit', 'credit', 
        'created_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = ''; // No updated_at needed for items usually
}
