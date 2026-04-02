# WhatsApp Chat Feature Documentation

## 📋 Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Database Schema](#database-schema)
- [Models](#models)
- [Controllers](#controllers)
- [Webhook Integration](#webhook-integration)
- [API Endpoints](#api-endpoints)
- [Key Features](#key-features)
- [Setup and Configuration](#setup-and-configuration)
- [Usage Examples](#usage-examples)

---

## Overview

The WhatsApp Chat feature is a multi-tenant messaging system that integrates with WhatsApp gateway to receive and manage customer messages. The system automatically:

- Receives incoming WhatsApp messages via webhook
- Stores messages and chat conversations
- Tracks unread message counts
- Supports message labeling and organization
- Manages customer associations
- Handles media attachments (images converted to WebP)
- Maintains tenant isolation for multi-tenancy

### Core Components

1. **WebhookController** - Handles incoming webhook payloads from WhatsApp gateway
2. **WhatsAppChatController** - Manages chat operations (list, show, labels)
3. **Models** - Data models for chats, messages, and labels
4. **Database** - 4 main tables for data persistence

---

## Architecture

### High-Level Flow

```
WhatsApp Gateway
       ↓
   Webhook Request
       ↓
WebhookController::whatsappGateway()
       ↓
Parse & Validate Payload
       ↓
Store in Database (Chat & Message)
       ↓
Return JSON Response
```

### Component Hierarchy

```
WhatsAppChatController
├── WhatsAppChatModel
├── WhatsAppMessageModel
├── WhatsAppLabelModel
└── WhatsAppChatLabelModel

WebhookController
├── WhatsAppChatModel
├── WhatsAppMessageModel
└── TenantContext (for multi-tenancy)
```

---

## Database Schema

### 1. `whatsapp_chats` Table

Stores conversation threads with customers.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | BIGINT (PK) | No | Auto-increment primary key |
| `tenant_id` | INT | Yes | For multi-tenancy |
| `phone` | VARCHAR(30) | No | Normalized customer phone number |
| `display_name` | VARCHAR(100) | Yes | Customer's display name |
| `last_message_at` | DATETIME | Yes | Timestamp of last message |
| `last_message_snippet` | VARCHAR(255) | Yes | Preview of last message (max 120 chars) |
| `unread_count` | INT | No | Count of unread messages (default: 0) |
| `created_at` | DATETIME | Yes | Record creation timestamp |
| `updated_at` | DATETIME | Yes | Record update timestamp |

**Indexes:**
- Primary: `id`
- Composite: `(tenant_id, phone)`

---

### 2. `whatsapp_messages` Table

Stores individual messages in conversations.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | BIGINT (PK) | No | Auto-increment primary key |
| `tenant_id` | INT | Yes | For multi-tenancy |
| `chat_id` | BIGINT | No | Foreign key to `whatsapp_chats.id` |
| `direction` | ENUM | No | 'in' (received) or 'out' (sent) |
| `message_type` | ENUM | No | 'text', 'image', 'document', 'other' |
| `text` | TEXT | Yes | Message text content |
| `media_path` | VARCHAR(255) | Yes | Path to stored media file |
| `media_mime` | VARCHAR(100) | Yes | MIME type of media |
| `received_at` | DATETIME | Yes | When message was received |
| `created_at` | DATETIME | Yes | Record creation timestamp |
| `updated_at` | DATETIME | Yes | Record update timestamp |

**Indexes:**
- Primary: `id`
- Composite: `(tenant_id, chat_id)`

---

### 3. `whatsapp_labels` Table

Stores message labels/categories for chat organization.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | INT (PK) | No | Auto-increment primary key |
| `tenant_id` | INT | Yes | For multi-tenancy |
| `name` | VARCHAR(50) | No | Label name (e.g., "Support", "Sales") |
| `color` | VARCHAR(20) | Yes | Color hex code for UI display |
| `created_at` | DATETIME | Yes | Record creation timestamp |
| `updated_at` | DATETIME | Yes | Record update timestamp |

**Indexes:**
- Primary: `id`
- Composite: `(tenant_id, name)`

---

### 4. `whatsapp_chat_labels` Table

Junction table for many-to-many relationship between chats and labels.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `tenant_id` | INT | Yes | For multi-tenancy |
| `chat_id` | BIGINT | No | Foreign key to `whatsapp_chats.id` |
| `label_id` | INT | No | Foreign key to `whatsapp_labels.id` |

**Indexes:**
- Composite (Primary): `(chat_id, label_id)`
- Single: `tenant_id`

---

## Models

### WhatsAppChatModel

```php
namespace App\Models;

class WhatsAppChatModel extends Model
{
    protected $table = 'whatsapp_chats';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $allowedFields = [
        'tenant_id',
        'phone',
        'display_name',
        'last_message_at',
        'last_message_snippet',
        'unread_count',
    ];
    protected $useTimestamps = true;
}
```

**Usage:**
```php
$chatModel = new WhatsAppChatModel();
$chats = $chatModel->where('tenant_id', $tenantId)->findAll();
```

---

### WhatsAppMessageModel

```php
namespace App\Models;

class WhatsAppMessageModel extends Model
{
    protected $table = 'whatsapp_messages';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $allowedFields = [
        'tenant_id',
        'chat_id',
        'direction',
        'message_type',
        'text',
        'media_path',
        'media_mime',
        'received_at',
    ];
    protected $useTimestamps = true;
}
```

**Usage:**
```php
$messageModel = new WhatsAppMessageModel();
$messages = $messageModel->where('chat_id', $chatId)->orderBy('received_at', 'ASC')->findAll();
```

---

### WhatsAppLabelModel

```php
namespace App\Models;

class WhatsAppLabelModel extends Model
{
    protected $table = 'whatsapp_labels';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $allowedFields = [
        'tenant_id',
        'name',
        'color',
    ];
    protected $useTimestamps = true;
}
```

---

### WhatsAppChatLabelModel

```php
namespace App\Models;

class WhatsAppChatLabelModel extends Model
{
    protected $table = 'whatsapp_chat_labels';
    protected $primaryKey = null;
    protected $useAutoIncrement = false;
    protected $allowedFields = [
        'tenant_id',
        'chat_id',
        'label_id',
    ];
    public $timestamps = false;
}
```

---

## Controllers

### WebhookController

Handles incoming WhatsApp webhook payloads.

#### `whatsappGateway()` (POST)

**Purpose:** Main webhook endpoint that receives messages from WhatsApp gateway.

**Request Format:**
- Accepts JSON, form-data, or query parameters
- Gateway flexibility to support various message formats

**Payload Structure (Expected):**
```json
{
  "tenant_id": 1,
  "from": "6281234567890",
  "phone": "6281234567890",
  "wa_id": "6281234567890",
  "name": "Customer Name",
  "text": "Hello, I have a question...",
  "message": { "body": "Alternative text format" },
  "media_url": "https://example.com/image.jpg",
  "image": { "url": "https://example.com/image.jpg", "mime_type": "image/jpeg" },
  "media_mime": "image/jpeg",
  "timestamp": 1704067200
}
```

**Response:**
```json
{
  "status": "ok",
  "chat_id": 123,
  "message_id": 456
}
```

**Features:**
- Handles multiple payload formats
- Normalizes phone numbers (removes non-digits)
- Auto-creates chats or updates existing ones
- Converts images to WebP format
- Logs all payloads for debugging
- Multi-tenant support

**Logging:**
- Application log: `php spark serve` console output
- Dedicated log file: `writable/logs/whatsapp-gateway.log`

---

### WhatsAppChatController

Manages chat operations, viewing, and labeling.

#### `index()` (GET)

**Purpose:** Retrieve all chats for current tenant.

**Response:**
```json
{
  "total_chats": 42,
  "chats": [
    {
      "id": 1,
      "tenant_id": 1,
      "phone": "6281234567890",
      "display_name": "John Doe",
      "last_message_at": "2026-04-02 10:30:00",
      "last_message_snippet": "Great! Can you help me with...",
      "unread_count": 3,
      "customer_name": "John Doe",
      "labels": [
        {
          "id": 1,
          "name": "Support",
          "color": "#FF5733"
        }
      ],
      "created_at": "2026-04-01 09:00:00",
      "updated_at": "2026-04-02 10:30:00"
    }
  ]
}
```

**Features:**
- Lists chats sorted by `last_message_at` (newest first)
- Joins with `customer` table to get customer names
- Includes attached labels for each chat
- Tenant-filtered results

---

#### `show($chatId)` (GET)

**Purpose:** Retrieve specific chat with all messages and labels.

**Parameters:**
- `$chatId` - Chat ID to retrieve

**Response:**
```json
{
  "chat": {
    "id": 123,
    "tenant_id": 1,
    "phone": "6281234567890",
    "display_name": "John Doe",
    "last_message_at": "2026-04-02 10:30:00",
    "last_message_snippet": "Great! Can you help me with...",
    "unread_count": 0,
    "customer_name": "John Doe",
    "created_at": "2026-04-01 09:00:00",
    "updated_at": "2026-04-02 10:30:00"
  },
  "messages": [
    {
      "id": 456,
      "tenant_id": 1,
      "chat_id": 123,
      "direction": "in",
      "message_type": "text",
      "text": "Hello, I need help",
      "media_path": null,
      "media_mime": null,
      "received_at": "2026-04-02 10:00:00",
      "created_at": "2026-04-02 10:00:00",
      "updated_at": "2026-04-02 10:00:00"
    },
    {
      "id": 457,
      "tenant_id": 1,
      "chat_id": 123,
      "direction": "in",
      "message_type": "image",
      "text": null,
      "media_path": "uploads/wa/wa_1704067890.webp",
      "media_mime": "image/webp",
      "received_at": "2026-04-02 10:15:00",
      "created_at": "2026-04-02 10:15:00",
      "updated_at": "2026-04-02 10:15:00"
    }
  ],
  "labels": [
    {
      "id": 1,
      "name": "Support",
      "color": "#FF5733"
    }
  ]
}
```

**Features:**
- Resets `unread_count` to 0 when chat is viewed
- Returns all messages sorted chronologically (oldest first)
- Includes chat metadata and labels

---

#### `listLabels()` (GET)

**Purpose:** Retrieve all labels for current tenant.

**Response:**
```json
{
  "labels": [
    {
      "id": 1,
      "tenant_id": 1,
      "name": "Support",
      "color": "#FF5733",
      "created_at": "2026-03-15 09:00:00",
      "updated_at": "2026-03-15 09:00:00"
    },
    {
      "id": 2,
      "tenant_id": 1,
      "name": "Sales",
      "color": "#33B5FF",
      "created_at": "2026-03-15 09:15:00",
      "updated_at": "2026-03-15 09:15:00"
    }
  ]
}
```

---

#### `createLabel()` (POST)

**Purpose:** Create a new label for organizing chats.

**Request Parameters:**
- `name` (required, string) - Label name (max 50 chars)
- `color` (optional, string) - Hex color code (e.g., #FF5733)

**Request:**
```
POST /whatsappchat/createLabel
Content-Type: application/x-www-form-urlencoded

name=Support&color=%23FF5733
```

**Response:**
```json
{
  "id": 1,
  "name": "Support",
  "color": "#FF5733"
}
```

**Validation:**
- Returns `400` if `name` is missing or empty

---

#### `attachLabel($chatId)` (POST)

**Purpose:** Attach a label to a chat.

**Parameters:**
- `$chatId` - Chat ID to attach label to

**Request Parameters:**
- `label_id` (required, integer) - Label ID to attach

**Request:**
```
POST /whatsappchat/attachLabel/123
Content-Type: application/x-www-form-urlencoded

label_id=1
```

**Response:**
```json
{
  "status": "ok"
}
```

**Features:**
- Prevents duplicate label assignments
- Validates chat and label existence
- Returns `404` if chat or label not found
- Returns `400` if `label_id` is missing

---

## Webhook Integration

### Setup

1. **Configure WhatsApp Gateway URL**

   Point your WhatsApp gateway to: `https://yourdomain.com/webhook/whatsappGateway`

2. **Tenant Context**

   The system uses `TenantContext::id()` for multi-tenancy. Ensure your middleware or gateway includes `tenant_id` in the webhook payload.

### Payload Processing Flow

```
Raw Payload
    ↓
Accept multiple formats (JSON, form-data, query)
    ↓
Normalize phone number (remove non-digits)
    ↓
Extract fields: phone, text, media_url, timestamp
    ↓
Handle media (convert to WebP if image)
    ↓
Find or create chat
    ↓
Update chat metadata (last_message_at, unread_count)
    ↓
Store message record
    ↓
Return response with IDs
```

### Error Handling

- Missing phone: Returns `[null, null]` without error
- Invalid media URL: Logs error, continues without media
- Image conversion failure: Logs error, message stored without media

### Media Handling

- **Supported:** Images (auto-converted to WebP at 75% quality)
- **Storage:** `writable/uploads/wa/wa_[uniqid].webp`
- **MIME Type:** Tracked in database for reference

---

## API Endpoints

### Summary

| Method | Endpoint | Controller | Purpose |
|--------|----------|-----------|---------|
| POST | `/webhook/whatsappGateway` | WebhookController | Receive messages from gateway |
| GET | `/whatsappchat` | WhatsAppChatController | List all chats |
| GET | `/whatsappchat/:id` | WhatsAppChatController | Get specific chat & messages |
| GET | `/whatsappchat/labels` | WhatsAppChatController | List all labels |
| POST | `/whatsappchat/createLabel` | WhatsAppChatController | Create new label |
| POST | `/whatsappchat/:id/attachLabel` | WhatsAppChatController | Attach label to chat |

---

## Key Features

### 1. **Multi-Tenant Support**

Each chat and message is isolated by `tenant_id`. The system automatically:
- Associates data with current tenant context
- Filters queries by tenant
- Prevents cross-tenant data access

---

### 2. **Message Media Support**

- **Image Conversion:** Automatically converts incoming images to WebP format
- **MIME Type Tracking:** Stores media type for UI rendering
- **Storage Path:** Organized in `writable/uploads/wa/` directory

---

### 3. **Unread Message Tracking**

- Increments `unread_count` when new message arrives
- Resets to 0 when chat is viewed via `show()` endpoint
- Helps identify active conversations

---

### 4. **Chat Labeling System**

- Create custom labels per tenant
- Attach multiple labels to single chat
- Organized by `whatsapp_chat_labels` junction table
- Supports color coding for UI

---

### 5. **Phone Number Normalization**

- Removes all non-digit characters
- Supports international formats
- Ensures consistent phone lookups

---

### 6. **Message Snippeting**

- Stores first 120 characters of message
- Shows in chat list preview
- Truncates long messages automatically

---

### 7. **Customer Integration**

- Joins with `customer` table when available
- Shows `customer_name` in responses
- Matches by phone number

---

### 8. **Comprehensive Logging**

- Application logs (visible in console)
- Dedicated WhatsApp log file
- Includes full payload for debugging

---

## Setup and Configuration

### Prerequisites

- CodeIgniter 4.x
- MySQL/MariaDB
- PHP 7.4+

### Installation Steps

1. **Run Migration**

   ```bash
   php spark migrate
   ```

   This creates 4 tables:
   - `whatsapp_chats`
   - `whatsapp_messages`
   - `whatsapp_labels`
   - `whatsapp_chat_labels`

2. **Verify Tables**

   ```bash
   php spark db:table whatsapp_chats
   ```

3. **Configure Routes** (if not auto-routed)

   In `app/Config/Routes.php`:
   ```php
   $routes->post('webhook/whatsappGateway', 'WebhookController::whatsappGateway');
   $routes->group('whatsappchat', ['controller' => 'WhatsAppChatController'], function($routes) {
       $routes->get('/', 'index');
       $routes->get('(:num)', 'show/$1');
       $routes->get('labels', 'listLabels');
       $routes->post('createLabel', 'createLabel');
       $routes->post('(:num)/attachLabel', 'attachLabel/$1');
   });
   ```

4. **Configure Webhook URL**

   In your WhatsApp gateway settings:
   ```
   https://yourdomain.com/webhook/whatsappGateway
   ```

5. **Ensure Writable Directories**

   ```bash
   chmod 775 writable/logs/
   chmod 775 writable/uploads/
   ```

---

## Usage Examples

### Example 1: Webhook Payload

```bash
curl -X POST https://yourdomain.com/webhook/whatsappGateway \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_id": 1,
    "from": "+6281234567890",
    "name": "John Doe",
    "text": "Hello, I need help with my order",
    "timestamp": 1704067200
  }'
```

**Response:**
```json
{
  "status": "ok",
  "chat_id": 123,
  "message_id": 456
}
```

---

### Example 2: Retrieve All Chats

```bash
curl -X GET https://yourdomain.com/whatsappchat \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
{
  "total_chats": 2,
  "chats": [
    {
      "id": 123,
      "phone": "6281234567890",
      "display_name": "John Doe",
      "last_message_at": "2026-04-02 10:30:00",
      "last_message_snippet": "Thank you for your help",
      "unread_count": 0,
      "customer_name": "John Doe",
      "labels": []
    }
  ]
}
```

---

### Example 3: View Chat with Messages

```bash
curl -X GET https://yourdomain.com/whatsappchat/123 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
{
  "chat": {
    "id": 123,
    "phone": "6281234567890",
    "display_name": "John Doe",
    "unread_count": 0
  },
  "messages": [
    {
      "id": 456,
      "direction": "in",
      "message_type": "text",
      "text": "Hello!",
      "received_at": "2026-04-02 10:00:00"
    }
  ],
  "labels": []
}
```

---

### Example 4: Create and Apply Labels

**Create Label:**
```bash
curl -X POST https://yourdomain.com/whatsappchat/createLabel \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "name=Support&color=%23FF5733"
```

**Attach Label to Chat:**
```bash
curl -X POST https://yourdomain.com/whatsappchat/123/attachLabel \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "label_id=1"
```

---

### Example 5: Image Message Webhook

```bash
curl -X POST https://yourdomain.com/webhook/whatsappGateway \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_id": 1,
    "from": "+6281234567890",
    "name": "Customer",
    "message_type": "image",
    "media_url": "https://gateway.example.com/image.jpg",
    "media_mime": "image/jpeg",
    "timestamp": 1704067200
  }'
```

---

## Troubleshooting

### 1. Messages Not Appearing

**Check:**
- Webhook endpoint is accessible
- Tenant ID is being passed correctly
- Check logs: `tail writable/logs/whatsapp-gateway.log`

---

### 2. Image Conversion Fails

**Check:**
- GD or ImageMagick extension is installed
- `writable/uploads/wa/` directory is writable
- Check application logs for error messages

---

### 3. Phone Number Issues

- Ensure phone numbers include country code
- System automatically removes non-digit characters

---

### 4. Multi-Tenant Isolation

- Verify `TenantContext::id()` is correctly set
- All queries should filter by `tenant_id`
- Check middleware for tenant context setup

---

## Performance Considerations

### Indexes

The tables have strategic indexes for common queries:
- `(tenant_id, phone)` on `whatsapp_chats`
- `(tenant_id, chat_id)` on `whatsapp_messages`
- `(tenant_id, name)` on `whatsapp_labels`

### Optimization Tips

1. **Pagination for large chat lists** - Add limit/offset to `index()`
2. **Message pagination** - Limit messages returned in `show()`
3. **Archive old chats** - Periodically move inactive chats
4. **Media cleanup** - Delete old WebP files periodically

---

## Future Enhancements

- [ ] Outbound message sending
- [ ] Message search and filtering
- [ ] Chat archiving
- [ ] Typing indicators
- [ ] Message reactions/emojis
- [ ] File attachments (non-image)
- [ ] Message deletion/editing
- [ ] Bulk label operations
- [ ] Chat export functionality

---

## Related Files

- Migration: `app/Database/Migrations/2026-04-02-000001_CreateWhatsappTables.php`
- Controllers: `app/Controllers/WebhookController.php`, `app/Controllers/WhatsAppChatController.php`
- Models: `app/Models/WhatsApp*.php`
- Logs: `writable/logs/whatsapp-gateway.log`

---

**Last Updated:** April 2, 2026
