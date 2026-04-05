<?php

namespace App\Controllers;

use App\Libraries\TenantContext;
use App\Models\CustomerModel;
use App\Models\WhatsAppChatLabelModel;
use App\Models\WhatsAppChatModel;
use App\Models\WhatsAppLabelModel;
use App\Models\WhatsAppMessageModel;

class WhatsAppChatController extends BaseController
{
    private WhatsAppChatModel $chatModel;
    private WhatsAppMessageModel $messageModel;
    private WhatsAppLabelModel $labelModel;
    private WhatsAppChatLabelModel $chatLabelModel;
    private CustomerModel $customerModel;
    private $db;

    public function __construct()
    {
        $this->chatModel = new WhatsAppChatModel();
        $this->messageModel = new WhatsAppMessageModel();
        $this->labelModel = new WhatsAppLabelModel();
        $this->chatLabelModel = new WhatsAppChatLabelModel();
        $this->customerModel = new CustomerModel();
        $this->db = \Config\Database::connect();
    }

    public function index()
    {
        $tenantId = $this->tenantId();

        $chats = $this->chatModel
            ->select('whatsapp_chats.*, customer.nama_customer AS customer_name')
            ->join('customer', 'customer.no_hp_customer = whatsapp_chats.phone AND customer.tenant_id = whatsapp_chats.tenant_id', 'left')
            ->where('whatsapp_chats.tenant_id', $tenantId)
            ->orderBy('last_message_at', 'DESC')
            ->findAll();

        // Attach labels per chat
        $labelsByChat = $this->fetchLabelsByChat($tenantId);
        foreach ($chats as &$chat) {
            $chat['labels'] = $labelsByChat[$chat['id']] ?? [];
        }
        unset($chat);

        return $this->response->setJSON([
            'total_chats' => count($chats),
            'chats' => $chats,
        ]);
    }

    public function show($chatId)
    {
        $tenantId = $this->tenantId();

        $chat = $this->chatModel
            ->select('whatsapp_chats.*, customer.nama_customer AS customer_name')
            ->join('customer', 'customer.no_hp_customer = whatsapp_chats.phone AND customer.tenant_id = whatsapp_chats.tenant_id', 'left')
            ->where('whatsapp_chats.tenant_id', $tenantId)
            ->find($chatId);

        if (!$chat) {
            return $this->response->setStatusCode(404)->setJSON(['message' => 'Chat not found']);
        }

        $messages = $this->messageModel
            ->where('tenant_id', $tenantId)
            ->where('chat_id', $chatId)
            ->orderBy('received_at', 'ASC')
            ->findAll();

        $labels = $this->fetchLabelsByChat($tenantId)[$chatId] ?? [];

        // reset unread count when viewed
        $this->chatModel->update($chatId, ['unread_count' => 0]);

        return $this->response->setJSON([
            'chat' => $chat,
            'messages' => $messages,
            'labels' => $labels,
        ]);
    }

    public function listLabels()
    {
        $tenantId = $this->tenantId();
        $labels = $this->labelModel->where('tenant_id', $tenantId)->orderBy('name')->findAll();
        return $this->response->setJSON(['labels' => $labels]);
    }

    public function createLabel()
    {
        $tenantId = $this->tenantId();
        $json = $this->request->getJSON(true);
        
        $name = trim($json['name'] ?? $this->request->getPost('name') ?? '');
        $color = $json['color'] ?? $this->request->getPost('color') ?? '#E5E7EB'; // Default gray

        if ($name === '') {
            return $this->response->setStatusCode(400)->setJSON(['message' => 'name is required']);
        }

        $id = $this->labelModel->insert([
            'tenant_id' => $tenantId,
            'name' => $name,
            'color' => $color,
        ], true);

        return $this->response->setJSON([
            'success' => true,
            'id' => $id,
            'name' => $name,
            'color' => $color,
            'tenant_id' => $tenantId
        ]);
    }

    public function updateLabel($id = null)
    {
        $tenantId = $this->tenantId();
        if (!$id) return $this->response->setStatusCode(400)->setJSON(['message' => 'ID is required']);

        $json = $this->request->getJSON(true);
        $name = trim($json['name'] ?? $this->request->getPost('name') ?? '');
        $color = $json['color'] ?? $this->request->getPost('color') ?? '';

        $label = $this->labelModel->where('tenant_id', $tenantId)->find($id);
        if (!$label) {
            return $this->response->setStatusCode(404)->setJSON(['message' => 'Label not found']);
        }

        $updateData = [];
        if ($name !== '') $updateData['name'] = $name;
        if ($color !== '') $updateData['color'] = $color;

        if (empty($updateData)) {
            return $this->response->setStatusCode(400)->setJSON(['message' => 'Nothing to update']);
        }

        $this->labelModel->update($id, $updateData);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Label updated successfully',
            'id' => $id,
            'update_data' => $updateData
        ]);
    }

    public function deleteLabel($id = null)
    {
        $tenantId = $this->tenantId();
        if (!$id) return $this->response->setStatusCode(400)->setJSON(['message' => 'ID is required']);

        $label = $this->labelModel->where('tenant_id', $tenantId)->find($id);
        if (!$label) {
            return $this->response->setStatusCode(404)->setJSON(['message' => 'Label not found']);
        }

        $this->db->transStart();
        $this->db->table('whatsapp_chat_labels')->where('tenant_id', $tenantId)->where('label_id', $id)->delete();
        $this->labelModel->delete($id);
        $this->db->transComplete();

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Label deleted successfully'
        ]);
    }

    public function attachLabel($chatId)
    {
        $tenantId = $this->tenantId();
        $json = $this->request->getJSON(true);
        $labelId = (int)($json['label_id'] ?? $this->request->getPost('label_id') ?? 0);

        if (!$labelId) {
            return $this->response->setStatusCode(400)->setJSON(['message' => 'label_id is required']);
        }

        // Verify if chat exists for this tenant
        $chat = $this->chatModel->where(['id' => $chatId, 'tenant_id' => $tenantId])->first();
        if (!$chat) {
            return $this->response->setStatusCode(404)->setJSON([
                'message' => 'Chat not found for this tenant',
                'chat_id' => $chatId,
                'tenant_id' => $tenantId
            ]);
        }

        // Verify if label exists for this tenant
        $label = $this->labelModel->where(['id' => $labelId, 'tenant_id' => $tenantId])->first();
        if (!$label) {
            return $this->response->setStatusCode(404)->setJSON([
                'message' => 'Label not found for this tenant',
                'label_id' => $labelId,
                'tenant_id' => $tenantId
            ]);
        }

        // Check if already attached
        $existing = $this->chatLabelModel
            ->where('tenant_id', $tenantId)
            ->where('chat_id', (int)$chatId)
            ->where('label_id', (int)$labelId)
            ->first();

        if (!$existing) {
            $this->db->table('whatsapp_chat_labels')->insert([
                'tenant_id' => $tenantId,
                'chat_id' => (int)$chatId,
                'label_id' => (int)$labelId,
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Label attached successfully'
        ]);
    }

    public function detachLabel($chatId, $labelId)
    {
        $tenantId = $this->tenantId();
        
        if (!$labelId) {
            return $this->response->setStatusCode(400)->setJSON(['message' => 'label_id is required']);
        }

        // Verify if relationship exists
        $existing = $this->chatLabelModel
            ->where('tenant_id', $tenantId)
            ->where('chat_id', (int)$chatId)
            ->where('label_id', (int)$labelId)
            ->first();

        if (!$existing) {
            return $this->response->setStatusCode(404)->setJSON(['message' => 'Label not attached to this chat']);
        }

        $this->db->table('whatsapp_chat_labels')
            ->where('tenant_id', $tenantId)
            ->where('chat_id', (int)$chatId)
            ->where('label_id', (int)$labelId)
            ->delete();

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Label detached successfully'
        ]);
    }

    private function fetchLabelsByChat($tenantId): array
    {
        $rows = $this->chatLabelModel
            ->select('whatsapp_chat_labels.chat_id, whatsapp_labels.id, whatsapp_labels.name, whatsapp_labels.color')
            ->join('whatsapp_labels', 'whatsapp_labels.id = whatsapp_chat_labels.label_id', 'left')
            ->where('whatsapp_chat_labels.tenant_id', $tenantId)
            ->findAll();

        $byChat = [];
        foreach ($rows as $row) {
            $byChat[$row['chat_id']][] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'color' => $row['color'],
            ];
        }
        return $byChat;
    }

    private function tenantId(): ?int
    {
        return TenantContext::id() ?: null;
    }
}
