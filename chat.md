# WhatsApp Chat Integration - Complete API & SSE Documentation

## 📋 Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Database Schema](#database-schema)
- [API Endpoints](#api-endpoints)
  - [Session Management](#session-management)
  - [Message Sending](#message-sending)
  - [Real-time Events (SSE)](#real-time-events-sse)
  - [Chat Management](#chat-management)
  - [Webhooks](#webhooks)
- [Setup & Configuration](#setup--configuration)
- [Usage Examples](#usage-examples)
- [Error Handling](#error-handling)

---

## Overview

WhatsApp Chat Integration provides:

1. **External Session Management** - Connect WhatsApp accounts via QR code with external service
2. **Message API** - Send/receive text and image messages
3. **Real-time Events (SSE)** - Stream incoming messages and status updates to clients
4. **Local Storage** - Store all chats and messages in database
5. **Multi-tenant** - Isolated sessions per store/tenant

### External Service Integration

```
Our API ↔ External WhatsApp Service (e.g., http://localhost:3000)
  ├─ POST /api/session/start → Get QR code
  ├─ GET /api/session/status → Check connection status
  └─ POST /api/session/send → Send message
       ↓
   External Service sends webhooks back
       ↓
   POST /api/chat/webhook/{tokoId}
```

---

## Quick Start

### 1. Run Migration
```bash
php spark migrate
```

### 2. Add Routes to app/Config/Routes.php

In protected routes section (with `['filter' => ['tenant', 'jwtAuth']]`):
```php
// Chat Session Management
$routes->group('chat', function ($routes) {
    $routes->post('session/start', 'ChatSessionController::start');
    $routes->get('session/status/(:num)', 'ChatSessionController::status/$1');
    $routes->get('session/qr/(:num)', 'ChatSessionController::getQr/$1');
    $routes->post('session/disconnect/(:num)', 'ChatSessionController::disconnect/$1');
    $routes->post('send', 'ChatSessionController::send');
    $routes->get('events/(:num)', 'ChatSSEController::subscribe/$1');
    $routes->get('events/(:num)/chat/(:num)', 'ChatSSEController::subscribeChat/$1/$2');
});

// Existing chat routes (no auth)
$routes->group('wa', function ($routes) {
    $routes->get('chats', 'WhatsAppChatController::index');
    $routes->get('chats/(:num)', 'WhatsAppChatController::show/$1');
    $routes->get('labels', 'WhatsAppChatController::listLabels');
    $routes->post('labels', 'WhatsAppChatController::createLabel');
    $routes->post('chats/(:num)/labels', 'WhatsAppChatController::attachLabel/$1');
});
```

In public routes section (before protected routes):
```php
// Chat Webhook
$routes->post('api/chat/webhook/(:num)', 'ChatWebhookController::incoming/$1');

// Existing webhook
$routes->post('api/webhook/whatsapp', 'WebhookController::whatsappGateway');
```

### 3. Configure .env
```env
CHAT_API_BASE_URL=http://localhost:3000
# Production:
# CHAT_API_BASE_URL=https://api.whatsapp-service.com
```

### 4. Setup External Service Webhook
Configure external service to POST to:
```
https://yourdomain.com/api/chat/webhook/{tokoId}
```

### 5. Schedule Cleanup (Optional)
```bash
# Add to cron (hourly)
0 * * * * cd /path/to/app && php spark chat:cleanup-sse

# Or run manually
php spark chat:cleanup-sse
```

---

## Architecture

### API Flow Diagram

```
[Client] 
  ├─ POST /api/chat/session/start → Get QR
  ├─ GET /api/chat/session/status/{id} → Check status
  ├─ POST /api/chat/send → Send message
  └─ GET /api/chat/events/{id} → Subscribe (SSE)
       ↓↑
   [Our Backend]
       ↓↑
   [External Service]
       ├─ Manages WhatsApp connections
       ├─ Sends/receives messages
       └─ Sends webhooks → /api/chat/webhook/{id}
```

### Component Hierarchy

```
ChatSessionController (session lifecycle)
  ├─ ChatServiceAPI (HTTP client)
  ├─ ChatSessionModel (session storage)
  └─ WhatsAppChatModel/MessageModel (message storage)

ChatWebhookController (receive messages)
  ├─ WhatsAppChatModel
  ├─ WhatsAppMessageModel
  └─ SSE broadcast (file-based queue)

ChatSSEController (stream events)
  └─ Read from sse-messages/toko_{id}.queue
```

---

## Database Schema

### toko Table (Updated)
```sql
ALTER TABLE toko ADD COLUMN chat_session_id VARCHAR(100);
ALTER TABLE toko ADD COLUMN chat_session_status VARCHAR(50) DEFAULT 'disconnected';
```

| Column | Type | Purpose |
|--------|------|---------|
| `chat_session_id` | VARCHAR(100) | External service session ID |
| `chat_session_status` | VARCHAR(50) | Status: disconnected, qr_ready, connecting, ready |

### whatsapp_chats Table
```sql
CREATE TABLE whatsapp_chats (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  tenant_id INT,
  phone VARCHAR(30) NOT NULL,
  display_name VARCHAR(100),
  last_message_at DATETIME,
  last_message_snippet VARCHAR(255),
  unread_count INT DEFAULT 0,
  created_at DATETIME,
  updated_at DATETIME,
  KEY (tenant_id, phone)
);
```

### whatsapp_messages Table
```sql
CREATE TABLE whatsapp_messages (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  tenant_id INT,
  chat_id BIGINT NOT NULL,
  direction ENUM('in', 'out') DEFAULT 'in',
  message_type ENUM('text', 'image', 'document', 'other') DEFAULT 'text',
  text TEXT,
  media_path VARCHAR(255),
  media_mime VARCHAR(100),
  received_at DATETIME,
  created_at DATETIME,
  updated_at DATETIME,
  KEY (tenant_id, chat_id)
);
```

### whatsapp_labels & whatsapp_chat_labels
For chat organization (optional labeling system)

---

## API Endpoints

### Session Management

#### POST /api/chat/session/start
Start new WhatsApp session and get QR code

**Request:**
```
POST /api/chat/session/start
Authorization: Bearer JWT_TOKEN
X-Tenant: 1
Content-Type: application/x-www-form-urlencoded

toko_id=1
```

**Response (200):**
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "sessionId": "toko_name_abc123_1",
        "status": "qr_ready",
        "qr": "data:image/png;base64,iVBORw0KGgo..."
    }
}
```

**Response (400/500):**
```json
{
    "success": false,
    "message": "Failed to start session: Connection refused"
}
```

---

#### GET /api/chat/session/status/{tokoId}
Check current session status

**Request:**
```
GET /api/chat/session/status/1
Authorization: Bearer JWT_TOKEN
```

**Response:**
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "sessionId": "akun1_abc123_1",
        "status": "ready"
    }
}
```

**Status Values:**
- `disconnected` - No active session
- `qr_ready` - QR generated, waiting for scan
- `connecting` - Authentication in progress
- `ready` - Connected and ready to send

---

#### GET /api/chat/session/qr/{tokoId}
Get fresh QR code (if original scan failed)

**Request:**
```
GET /api/chat/session/qr/1
Authorization: Bearer JWT_TOKEN
```

**Response:**
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "sessionId": "akun1",
        "qr": "data:image/png;base64,..."
    }
}
```

---

#### POST /api/chat/session/disconnect/{tokoId}
Disconnect and close session

**Request:**
```
POST /api/chat/session/disconnect/1
Authorization: Bearer JWT_TOKEN
```

**Response:**
```json
{
    "success": true,
    "message": "Session disconnected successfully"
}
```

---

### Message Sending

#### POST /api/chat/send
Send text or image message

**Request (Text):**
```
POST /api/chat/send
Authorization: Bearer JWT_TOKEN
Content-Type: application/x-www-form-urlencoded

toko_id=1&to=6281234567890&text=Hello World!
```

**Request (Image):**
```
toko_id=1&to=6281234567890&image_url=https://example.com/photo.jpg&caption=Check this!
```

**Parameters:**
- `toko_id` (required) - Store ID
- `to` (required) - Phone number (auto-formatted)
- `text` (optional) - Message text
- `image_url` (optional) - Image URL
- `caption` (optional) - Image caption

**Phone Formats (all auto-converted):**
- `6281234567890` → `6281234567890@c.us` (private)
- `0812345` → `6281234567890@c.us` (private)
- `+6281234567890` → `6281234567890@c.us` (private)
- Auto-appends `@c.us` for private chats (ignores `@g.us` groups)

**Response:**
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "to": "6281234567890@c.us",
        "messageId": "msg_123456",
        "status": "sent"
    }
}
```

**Errors:**
```json
{
    "success": false,
    "message": "Session is not ready. Current status: qr_ready"
}
```

---

### Real-time Events (SSE)

#### GET /api/chat/events/{tokoId}
Subscribe to all store events via Server-Sent Events

**Request:**
```javascript
const eventSource = new EventSource('/api/chat/events/1', {
    headers: { 'Authorization': 'Bearer JWT_TOKEN' }
});

eventSource.addEventListener('message', (event) => {
    const data = JSON.parse(event.data);
    console.log('Event:', data.type, data);
});

eventSource.addEventListener('error', () => {
    eventSource.close();
});
```

**Event Types:**

**1. Connected**
```json
{
    "type": "connected",
    "message": "Connected to chat stream",
    "toko_id": 1,
    "timestamp": "2026-04-02 10:30:00"
}
```

**2. New Message (Incoming)**
```json
{
    "type": "new_message",
    "chat_id": 123,
    "message_id": 456,
    "from": "6281234567890@c.us",
    "text": "Hello, how can I help?",
    "image_url": null,
    "timestamp": 1704067200,
    "unread_count": 3
}
```

**3. Message Status**
```json
{
    "type": "message_status",
    "message_id": 789,
    "status": "delivered"
}
```

**4. Session Status**
```json
{
    "type": "session_status",
    "status": "ready",
    "sessionId": "akun1"
}
```

---

#### GET /api/chat/events/{tokoId}/chat/{chatId}
Subscribe to specific chat events only

**Request:**
```javascript
const chatEvents = new EventSource('/api/chat/events/1/chat/123', {
    headers: { 'Authorization': 'Bearer JWT_TOKEN' }
});

chatEvents.addEventListener('message', (event) => {
    const data = JSON.parse(event.data);
    if (data.type === 'new_message') {
        updateChatUI(data);
    }
});
```

Filters events to only those for chat ID 123

---

### Chat Management (Legacy)

#### GET /api/wa/chats
List all chats

**Response:**
```json
{
    "total_chats": 2,
    "chats": [
        {
            "id": 1,
            "phone": "6281234567890",
            "display_name": "John Doe",
            "last_message_at": "2026-04-02 10:30:00",
            "last_message_snippet": "Thank you for your order",
            "unread_count": 0,
            "labels": []
        }
    ]
}
```

---

#### GET /api/wa/chats/{chatId}
Get specific chat with messages

**Response:**
```json
{
    "chat": {
        "id": 1,
        "phone": "6281234567890",
        "display_name": "John",
        "unread_count": 0
    },
    "messages": [
        {
            "id": 1,
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

#### GET /api/wa/labels
List all labels

#### POST /api/wa/labels
Create new label

Request:
```
name=Support&color=%23FF5733
```

#### POST /api/wa/chats/{chatId}/labels
Attach label to chat

Request:
```
label_id=1
```

---

### Webhooks

#### POST /api/chat/webhook/{tokoId}
Receive incoming messages from external service (no auth required)

**Payload from external service:**
```json
{
    "type": "message",
    "from": "6281234567890@c.us",
    "sender_name": "John Doe",
    "text": "Hello!",
    "timestamp": 1704067200
}
```

**Or Image:**
```json
{
    "type": "message",
    "from": "6281234567890@c.us",
    "message_type": "image",
    "media_url": "https://service.com/image.jpg",
    "media_mime": "image/jpeg",
    "timestamp": 1704067200
}
```

**Response:**
```json
{
    "success": true,
    "message": "Webhook processed"
}
```

**Automatically:**
- Creates/updates chat
- Stores message
- Broadcasts SSE events
- Increments unread count
- Ignores group messages (@g.us)
- Converts images to WebP

---

## Setup & Configuration

### Files Created

| File | Purpose |
|------|---------|
| `app/Controllers/ChatSessionController.php` | Session management API |
| `app/Controllers/ChatWebhookController.php` | Webhook receiver |
| `app/Controllers/ChatSSEController.php` | SSE streaming |
| `app/Libraries/ChatServiceAPI.php` | External service HTTP client |
| `app/Models/ChatSessionModel.php` | Session data model |
| `app/Database/Migrations/2026-04-02-000002_AddChatSessionToToko.php` | Database migration |
| `app/Commands/CleanupSSECommand.php` | SSE cleanup command |

### Installation Steps

1. **Database Migration**
```bash
php spark migrate
```

2. **Add Routes** (see Quick Start)

3. **Configure Environment**
```bash
echo "CHAT_API_BASE_URL=http://localhost:3000" >> .env
```

4. **Create Writable Directories**
```bash
mkdir -p writable/sse-messages writable/uploads/wa
chmod 777 writable/sse-messages writable/uploads/wa
```

5. **Test External Service**
```bash
curl -X GET http://localhost:3000/api/session/status/test
```

---

## Usage Examples

### Example 1: Start Session & Display QR

```bash
# Start session
curl -X POST http://yourdomain.com/api/chat/session/start \
  -H "Authorization: Bearer TOKEN" \
  -d "toko_id=1"
```

Response includes QR code (base64). Display it to user for scanning.

### Example 2: Poll for Connection Status

```bash
# Check every 2 seconds until status = "ready"
for i in {1..30}; do
  curl -s -X GET http://yourdomain.com/api/chat/session/status/1 \
    -H "Authorization: Bearer TOKEN" | jq '.data.status'
  sleep 2
done
```

### Example 3: Send Message

```bash
curl -X POST http://yourdomain.com/api/chat/send \
  -H "Authorization: Bearer TOKEN" \
  -d "toko_id=1&to=6281234567890&text=Hello World!"
```

### Example 4: Send Image

```bash
curl -X POST http://yourdomain.com/api/chat/send \
  -H "Authorization: Bearer TOKEN" \
  -d "toko_id=1&to=6281234567890&image_url=https://example.com/photo.jpg&caption=Check this!"
```

### Example 5: Subscribe to Events (JavaScript)

```javascript
const eventSource = new EventSource('/api/chat/events/1', {
    headers: { 'Authorization': 'Bearer TOKEN' }
});

eventSource.addEventListener('message', (event) => {
    const data = JSON.parse(event.data);
    console.log(`[${data.type}]`, data);
    
    if (data.type === 'new_message') {
        console.log(`Message from ${data.from}: ${data.text}`);
        addToChat(data);
    } else if (data.type === 'session_status') {
        console.log(`Session is now: ${data.status}`);
    }
});

eventSource.addEventListener('error', () => {
    console.log('Connection lost, will auto-reconnect...');
});
```

### Example 6: Listen to Specific Chat

```javascript
const chatEvents = new EventSource('/api/chat/events/1/chat/123');

chatEvents.addEventListener('message', (event) => {
    const data = JSON.parse(event.data);
    
    // Only new messages for this chat will arrive here
    if (data.type === 'new_message') {
        updateChatWindow(data.text, data.from);
    }
});
```

### Example 7: Simulate Incoming Webhook

```bash
# Test webhook receiver (simulate external service sending message)
curl -X POST http://yourdomain.com/api/chat/webhook/1 \
  -H "Content-Type: application/json" \
  -d '{
    "type": "message",
    "from": "6281234567890@c.us",
    "sender_name": "John Doe",
    "text": "Hi, I have a question about your product",
    "timestamp": '$(date +%s)'
  }'
```

### Example 8: Complete Flow (Bash)

```bash
#!/bin/bash

DOMAIN="http://yourdomain.com"
TOKEN="your_jwt_token"
TOKO_ID=1

echo "1. Starting session..."
START=$(curl -s -X POST $DOMAIN/api/chat/session/start \
  -H "Authorization: Bearer $TOKEN" \
  -d "toko_id=$TOKO_ID")

SESSION_ID=$(echo $START | jq -r '.data.sessionId')
QR=$(echo $START | jq -r '.data.qr')

echo "Session ID: $SESSION_ID"
echo "Display QR to user (scan with WhatsApp)"

echo "2. Waiting for connection..."
for i in {1..60}; do
  STATUS=$(curl -s -X GET $DOMAIN/api/chat/session/status/$TOKO_ID \
    -H "Authorization: Bearer $TOKEN" | jq -r '.data.status')
  
  echo "Status: $STATUS"
  [ "$STATUS" = "ready" ] && break
  sleep 1
done

echo "3. Session ready! Sending test message..."
curl -X POST $DOMAIN/api/chat/send \
  -H "Authorization: Bearer $TOKEN" \
  -d "toko_id=$TOKO_ID&to=6281234567890&text=Test message from API"

echo "Done!"
```

---

## Error Handling

### Common Errors

**External Service Unreachable**
```json
{
    "success": false,
    "message": "Failed to start session: Connection refused"
}
```
→ Check `CHAT_API_BASE_URL` in `.env`, ensure service is running

**Session Not Ready**
```json
{
    "success": false,
    "message": "Session is not ready. Current status: qr_ready"
}
```
→ Wait for user to scan QR or check status

**Invalid Phone Format**
→ System auto-formats, but ensure valid numbers (with or without country code)

**Store Not Found**
```json
{
    "success": false,
    "message": "Store not found"
}
```
→ Verify `toko_id` exists in database

**SSE Connection Timeout**
→ Normal, client will auto-reconnect. Add error handler in JavaScript

### Debug Commands

```bash
# Check service connectivity
curl -v http://localhost:3000/api/session/status/test

# View logs
tail -f writable/logs/

# Check SSE queue files
ls -lah writable/sse-messages/

# Monitor queue in real-time
tail -f writable/sse-messages/toko_1.queue

# Test webhook manually
curl -X POST http://yourdomain.com/api/chat/webhook/1 \
  -H "Content-Type: application/json" \
  -d '{"type":"message","from":"6281234567890@c.us","text":"Test"}'

# Run cleanup
php spark chat:cleanup-sse
```

---

**Last Updated:** April 2, 2026
