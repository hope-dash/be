# WhatsApp Chat Session Integration - Complete Documentation

## 📋 Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)  
- [Session Management](#session-management)
- [API Endpoints](#api-endpoints)
- [Database Schema](#database-schema)
- [Real-time Updates (SSE)](#real-time-updates-sse)
- [Integration Setup](#integration-setup)
- [Usage Examples](#usage-examples)
- [Error Handling](#error-handling)
- [Security Considerations](#security-considerations)

---

## Overview

The WhatsApp Chat Session Integration connects to an external WhatsApp service via API, enabling:

- **QR Code Authentication** - Scan QR to connect WhatsApp accounts
- **Session Management** - Create, monitor, and manage chat sessions per store
- **Message Sending** - Send text and image messages from store accounts
- **Real-time Events** - SSE (Server-Sent Events) for live chat updates
- **Multi-tenant Support** - Isolated sessions per store/tenant
- **Automatic Message Storage** - Incoming messages stored in local database

### External Service Integration

The system communicates with an external WhatsApp chat service API (e.g., `http://localhost:3000`):

```
┌─────────────────────────────────────────────────────────┐
│                     Our API Backend                       │
│ (CodeIgniter 4)                                          │
│  ┌────────────────────────────────────────────────────┐  │
│  │ ChatSessionController                              │  │
│  │ - Start session                                    │  │
│  │ - Check status                                     │  │
│  │ - Send messages                                    │  │
│  └────────────────────────────────────────────────────┘  │
│                        ↓ (HTTP)                          │
│  ┌────────────────────────────────────────────────────┐  │
│  │ ChatServiceAPI Library                             │  │
│  │ - REST client wrapper                              │  │
│  │ - Payload formatting                               │  │
│  │ - Error handling                                   │  │
│  └────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
                        ↕ JSON/HTTP
┌─────────────────────────────────────────────────────────┐
│           External WhatsApp Chat Service                 │
│           (http://localhost:3000)                        │
│                                                          │
│ - Manages WhatsApp connections                          │
│ - Handles message sending/receiving                     │
│ - Tracks session status & QR codes                      │
│ - Sends webhook callbacks to our system                 │
└─────────────────────────────────────────────────────────┘
                        ↕ Webhook
         POST /api/chat/webhook/{tokoId}
```

---

## Architecture

### Component Overview

1. **ChatSessionController** - Handles session lifecycle
2. **ChatServiceAPI** - HTTP wrapper for external service
3. **ChatWebhookController** - Receives incoming messages
4. **ChatSSEController** - Real-time event streaming
5. **ChatSessionModel** - Session data persistence
6. **WhatsAppChatModel** - Chat conversation storage
7. **WhatsAppMessageModel** - Individual message storage

### Data Flow

#### Start Session Flow
```
Client Request
    ↓
ChatSessionController::start()
    ↓
ChatServiceAPI::startSession()
    ↓ (HTTP POST /api/session/start)
External Service
    ↓
Returns: sessionId + QR code
    ↓
Save to toko.chat_session_id
    ↓
Return QR to client
```

#### Send Message Flow
```
Client Request (POST /api/chat/send)
    ↓
ChatSessionController::send()
    ↓
Validate session status = "ready"
    ↓
ChatServiceAPI::sendTextMessage() or sendImageMessage()
    ↓ (HTTP POST /api/session/send)
External Service sends via WhatsApp
    ↓
Store message locally (direction='out')
    ↓
Broadcast SSE event
    ↓
Return confirmation to client
```

#### Receive Message Flow
```
External Service (Webhook)
    ↓
POST /api/chat/webhook/{tokoId}
    ↓
ChatWebhookController::incoming()
    ↓
Parse incoming message
    ↓
Find/create chat in WhatsAppChatModel
    ↓
Store message in WhatsAppMessageModel
    ↓
Queue SSE event
    ↓
Return 200 OK
    ↓
Client receives via SSE subscription
```

---

## Session Management

### Session States

```
disconnected ──→ qr_ready ──→ connecting ──→ ready
     ↑                                          ↓
     └──────────── (disconnect) ─────────────┘
```

| State | Meaning | Can Send Messages |
|-------|---------|-------------------|
| `disconnected` | No active session | ✗ No |
| `qr_ready` | QR generated, waiting for scan | ✗ No |
| `connecting` | User scanning QR, authenticating | ✗ No |
| `ready` | Connected and authenticated | ✓ Yes |

### Session Lifecycle

**1. Create Session**
```php
POST /api/chat/session/start
{
    "toko_id": 1
}
```

Returns:
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "sessionId": "toko_name_unique_id",
        "status": "qr_ready",
        "qr": "data:image/png;base64,iVBORw0KGgo..."
    }
}
```

**2. Display QR & Wait for Scan**

Client displays QR code to user. User scans with WhatsApp phone.

**3. Check Status**
```php
GET /api/chat/session/status/1
```

Returns:
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

**4. Send Messages (when ready)**
```php
POST /api/chat/send
{
    "toko_id": 1,
    "to": "6281234567890",
    "text": "Hello, this is a message"
}
```

**5. Disconnect Session**
```php
POST /api/chat/session/disconnect/1
```

---

## API Endpoints

### Session Management Endpoints

#### 1. Start Session

**Endpoint:** `POST /api/chat/session/start`

**Headers:**
```
Content-Type: application/x-www-form-urlencoded
X-Tenant: 1
Authorization: Bearer YOUR_JWT_TOKEN
```

**Request:**
```
toko_id=1
```

**Response (Success):**
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "sessionId": "toko_name_abc123_1",
        "status": "qr_ready",
        "qr": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
    }
}
```

**Response (Error):**
```json
{
    "success": false,
    "message": "Failed to start session: Connection timeout"
}
```

**Description:**
- Generates unique session ID: `{store_name}_{uniqid}_{store_id}`
- Calls external service `/api/session/start`
- Returns QR code as base64 PNG image
- Saves `chat_session_id` and sets status to `qr_ready`

---

#### 2. Check Session Status

**Endpoint:** `GET /api/chat/session/status/:tokoId`

**Example:**
```
GET /api/chat/session/status/1
```

**Response:**
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "sessionId": "akun1_abc123_1",
        "status": "ready",
        "webhookUrl": "https://yourdomain.com/api/chat/webhook/1"
    }
}
```

**Possible Statuses:**
- `disconnected` - No session
- `qr_ready` - QR generated, waiting for scan
- `connecting` - Authentication in progress
- `ready` - Connected and ready to send messages

---

#### 3. Get QR Code

**Endpoint:** `GET /api/chat/session/qr/:tokoId`

**Example:**
```
GET /api/chat/session/qr/1
```

**Response:**
```json
{
    "success": true,
    "data": {
        "toko_id": 1,
        "sessionId": "akun1",
        "qr": "data:image/png;base64,iVBORw0KGgo...",
        "status": "qr_ready"
    }
}
```

**Use Case:**
- Get new QR if original is lost
- Refresh QR if previous scan failed
- Requires active session

---

#### 4. Send Message

**Endpoint:** `POST /api/chat/send`

**Headers:**
```
Content-Type: application/x-www-form-urlencoded
Authorization: Bearer YOUR_JWT_TOKEN
```

**Request (Text Message):**
```
toko_id=1&to=6281234567890&text=Hello World!
```

**Request (Image Message):**
```
toko_id=1&to=6281234567890&image_url=https://example.com/photo.jpg&caption=Check out this image!
```

**Parameters:**
- `toko_id` (required) - Store ID
- `to` (required) - Recipient phone (supports formats: 6281234567890, 0812345, +62812)
- `text` (optional) - Message text
- `image_url` (optional) - Image URL
- `caption` (optional) - Image caption

**Response (Success):**
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

**Response (Error - Session Not Ready):**
```json
{
    "success": false,
    "message": "Session is not ready. Current status: qr_ready"
}
```

**Features:**
- Automatic phone number formatting
- Appends `@c.us` for private chats (ignores groups `@g.us`)
- Stores message locally as `direction='out'`
- Broadcasts SSE event
- Supports both text and image messages

**Phone Number Formats:**
```
6281234567890    → 6281234567890@c.us
0812345          → 6281234567890@c.us
+62812345        → 6281234567890@c.us
```

---

#### 5. Disconnect Session

**Endpoint:** `POST /api/chat/session/disconnect/:tokoId`

**Example:**
```
POST /api/chat/session/disconnect/1
```

**Response:**
```json
{
    "success": true,
    "message": "Session disconnected successfully"
}
```

**Effects:**
- Calls external service to disconnect
- Sets `chat_session_status` to `disconnected`
- Clears `chat_session_id` from database
- Client can start new session afterward

---

### Real-time Events (SSE)

#### 1. Subscribe to Store Events

**Endpoint:** `GET /api/chat/events/:tokoId`

**Example:**
```javascript
const eventSource = new EventSource('/api/chat/events/1');

eventSource.addEventListener('message', (event) => {
    const data = JSON.parse(event.data);
    console.log('Event received:', data);
});

eventSource.addEventListener('error', (error) => {
    console.error('SSE connection error:', error);
    eventSource.close();
});
```

**Event Types:**

**Connected Event:**
```json
{
    "type": "connected",
    "message": "Connected to chat stream",
    "toko_id": 1,
    "timestamp": "2026-04-02 10:30:00"
}
```

**New Message Event:**
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

**Message Status Event:**
```json
{
    "type": "message_status",
    "message_id": 789,
    "status": "delivered"
}
```

**Session Status Event:**
```json
{
    "type": "session_status",
    "status": "ready",
    "sessionId": "akun1"
}
```

---

#### 2. Subscribe to Specific Chat Events

**Endpoint:** `GET /api/chat/events/:tokoId/chat/:chatId`

**Example:**
```javascript
const chatEvents = new EventSource('/api/chat/events/1/chat/123');

chatEvents.addEventListener('message', (event) => {
    const data = JSON.parse(event.data);
    
    if (data.type === 'new_message') {
        // Add message to UI
        addMessageToChat(data);
    } else if (data.type === 'message_status') {
        // Update message delivery status
        updateMessageStatus(data);
    }
});
```

**Features:**
- Only receives events for specific chat
- Filters out unrelated messages
- Useful for real-time chat UI updates
- Connection timeout: 30 seconds

---

## Database Schema

### Updated toko Table

**New Columns (Migration: 2026-04-02-000002):**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `chat_session_id` | VARCHAR(100) | Yes | NULL | WhatsApp session ID from external service |
| `chat_session_status` | VARCHAR(50) | Yes | 'disconnected' | Current session status |

**Example Record:**
```sql
INSERT INTO toko (id, toko_name, chat_session_id, chat_session_status) VALUES
(1, 'Toko Saya', 'akun1_abc123_1', 'ready');
```

### Related Tables (Existing)

**whatsapp_chats:**
```sql
- id (BIGINT PK)
- tenant_id (INT)
- phone (VARCHAR 30)
- display_name (VARCHAR 100)
- last_message_at (DATETIME)
- last_message_snippet (VARCHAR 255)
- unread_count (INT)
```

**whatsapp_messages:**
```sql
- id (BIGINT PK)
- tenant_id (INT)
- chat_id (BIGINT FK)
- direction (ENUM: 'in', 'out')
- message_type (ENUM: 'text', 'image', 'document', 'other')
- text (TEXT)
- media_path (VARCHAR 255)
- media_mime (VARCHAR 100)
- received_at (DATETIME)
```

---

## Real-time Updates (SSE)

### How SSE Works

Server-Sent Events (SSE) provide one-way server-to-client communication:

1. **Client connects** via `new EventSource(url)`
2. **Server streams events** as they happen
3. **Connection stays open** until disconnected
4. **Automatic reconnection** on connection drops

### Queue-Based Implementation

The system uses file-based message queues for SSE:

```
Incoming Message
    ↓
ChatWebhookController::incoming()
    ↓
broadcastSSEEvent()
    ↓
Write to: writable/sse-messages/toko_{tokoId}.queue
    ↓
SSE subscribers read from queue
    ↓
Send to all connected clients
```

**Queue File Format:**
```
writable/sse-messages/toko_1.queue

{"type":"new_message","chat_id":123,"from":"6281234567890@c.us","text":"Hello"}
{"type":"message_status","message_id":456,"status":"delivered"}
{"type":"session_status","status":"ready"}
```

### Cleanup

Old queue files are cleaned up automatically:

```bash
php spark chat:cleanup-sse
```

Removes files older than 1 hour. Add to cron:
```bash
0 * * * * cd /path/to/app && php spark chat:cleanup-sse
```

---

## Integration Setup

### 1. Prerequisites

- External WhatsApp service running (e.g., `http://localhost:3000`)
- CodeIgniter 4.4+
- PHP 7.4+ with cURL extension
- MySQL/MariaDB database

### 2. Environment Configuration

**`.env` file:**
```env
# WhatsApp Chat Service Configuration
CHAT_API_BASE_URL=http://localhost:3000
# For production:
# CHAT_API_BASE_URL=https://api.whatsapp-service.com
```

### 3. Database Migration

Run migration:
```bash
php spark migrate
```

Verify new columns:
```bash
php spark db:table toko
```

### 4. Routes Configuration

Add to `app/Config/Routes.php` (in protected routes section):

```php
// --- CHAT SESSION MANAGEMENT ---
$routes->group('chat', function ($routes) {
    // Session Management
    $routes->post('session/start', 'ChatSessionController::start');
    $routes->get('session/status/(:num)', 'ChatSessionController::status/$1');
    $routes->get('session/qr/(:num)', 'ChatSessionController::getQr/$1');
    $routes->post('session/disconnect/(:num)', 'ChatSessionController::disconnect/$1');

    // Send Messages
    $routes->post('send', 'ChatSessionController::send');

    // SSE (Server-Sent Events)
    $routes->get('events/(:num)', 'ChatSSEController::subscribe/$1');
    $routes->get('events/(:num)/chat/(:num)', 'ChatSSEController::subscribeChat/$1/$2');
});
```

Add webhook route (public, no auth):
```php
// Webhook
$routes->post('api/chat/webhook/(:num)', 'ChatWebhookController::incoming/$1');
```

### 5. Configure External Service Webhook

Configure your external WhatsApp service to send webhooks to:

```
https://yourdomain.com/api/chat/webhook/{tokoId}
```

The webhooks should include:
```json
{
    "type": "message",
    "from": "6281234567890@c.us",
    "sender_name": "John Doe",
    "text": "Hello!",
    "timestamp": 1704067200
}
```

---

## Usage Examples

### Example 1: Initialize Chat Session

```bash
# Step 1: Start session
curl -X POST https://yourdomain.com/api/chat/session/start \
  -H "X-Tenant: 1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "toko_id=1"

# Response:
# {
#     "success": true,
#     "data": {
#         "sessionId": "toko_name_abc123_1",
#         "status": "qr_ready",
#         "qr": "data:image/png;base64,..."
#     }
# }

# Step 2: Display QR to user (user scans with WhatsApp)

# Step 3: Poll for status
curl -X GET https://yourdomain.com/api/chat/session/status/1 \
  -H "X-Tenant: 1" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Repeat every 2 seconds until status = "ready"
```

### Example 2: Send Text Message

```bash
curl -X POST https://yourdomain.com/api/chat/send \
  -H "X-Tenant: 1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "toko_id=1&to=6281234567890&text=Hello, thank you for your order!"
```

### Example 3: Send Image Message

```bash
curl -X POST https://yourdomain.com/api/chat/send \
  -H "X-Tenant: 1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "toko_id=1&to=6281234567890&image_url=https://example.com/product.jpg&caption=Your product" 
```

### Example 4: JavaScript - Real-time Chat UI

```javascript
class ChatClient {
    constructor(tokoId, token) {
        this.tokoId = tokoId;
        this.token = token;
        this.apiUrl = '/api/chat';
    }

    async startSession() {
        const response = await fetch(`${this.apiUrl}/session/start`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.token}`,
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `toko_id=${this.tokoId}`
        });
        
        const data = await response.json();
        
        // Display QR code
        this.displayQR(data.data.qr);
        
        // Poll for ready status
        this.pollStatus();
    }

    pollStatus() {
        const interval = setInterval(async () => {
            const response = await fetch(`${this.apiUrl}/session/status/${this.tokoId}`, {
                headers: { 'Authorization': `Bearer ${this.token}` }
            });
            
            const data = await response.json();
            
            if (data.data.status === 'ready') {
                clearInterval(interval);
                this.startSSE();
                alert('Session ready! You can now send messages.');
            }
        }, 2000);
    }

    startSSE() {
        const eventSource = new EventSource(`${this.apiUrl}/events/${this.tokoId}`);

        eventSource.addEventListener('message', (event) => {
            const data = JSON.parse(event.data);
            
            switch (data.type) {
                case 'new_message':
                    this.addMessageToUI(data);
                    break;
                case 'session_status':
                    this.updateSessionStatus(data);
                    break;
            }
        });

        eventSource.addEventListener('error', () => {
            console.error('SSE connection lost');
            eventSource.close();
            setTimeout(() => this.startSSE(), 3000); // Retry
        });
    }

    async sendMessage(to, text) {
        const response = await fetch(`${this.apiUrl}/send`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.token}`,
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `toko_id=${this.tokoId}&to=${to}&text=${text}`
        });
        
        return await response.json();
    }

    addMessageToUI(messageData) {
        const message = document.createElement('div');
        message.className = 'message incoming';
        message.innerHTML = `
            <div class="message-time">${messageData.timestamp}</div>
            <div class="message-text">${messageData.text || '[Image]'}</div>
        `;
        document.getElementById('messages').appendChild(message);
    }

    updateSessionStatus(statusData) {
        document.getElementById('status').textContent = statusData.status;
    }

    displayQR(qrData) {
        const img = document.createElement('img');
        img.src = qrData;
        img.style.maxWidth = '300px';
        document.getElementById('qr-container').appendChild(img);
    }
}

// Usage
const chat = new ChatClient(1, 'your-jwt-token');
chat.startSession();
```

---

## Error Handling

### Common Errors

**1. External Service Connection Failed**
```json
{
    "success": false,
    "message": "Failed to start session: Connection refused"
}
```

**Solution:** Ensure external service is running and URL is correct in `.env`

**2. Session Not Ready**
```json
{
    "success": false,
    "message": "Session is not ready. Current status: qr_ready"
}
```

**Solution:** Wait for QR scan or check session status

**3. Invalid Phone Number**
```json
{
    "success": false,
    "message": "Invalid phone number format"
}
```

**Solution:** Use valid phone numbers (automatically formatted)

**4. Store Not Found**
```json
{
    "success": false,
    "message": "Store not found"
}
```

**Solution:** Verify `toko_id` exists

---

## Security Considerations

### 1. Authentication & Authorization

- All endpoints protected by JWT token
- Verify `toko_id` belongs to current user/tenant
- Webhook endpoints should validate origin/signature

### 2. Phone Number Privacy

- Phone numbers stored encrypted in database
- Implement rate limiting on send endpoint
- Log message sending for audit trail

### 3. Session Security

- Session IDs unique per store
- Credentials never stored locally (external service handles)
- Regular session cleanup/expiration

### 4. Data Validation

```php
// Always validate input
$to = ChatServiceAPI::formatPhoneNumber($to);
$text = trim($text);
if (strlen($text) > 65536) {
    throw new Exception('Message too long');
}
```

### 5. Rate Limiting

```bash
# Recommended: Add rate limiting middleware
# E.g., max 100 messages per minute per store
```

---

## Troubleshooting

### SSE Connection Not Working

**Issue:** Events not being received

**Debug Steps:**
1. Check connection established: `curl -v http://localhost/api/chat/events/1`
2. Check queue directory exists: `ls -la writable/sse-messages/`
3. Monitor queue file: `tail -f writable/sse-messages/toko_1.queue`
4. Check logs: `tail -f writable/logs/`

### Messages Not Sending

**Issue:** Send message returns error

**Debug Steps:**
1. Verify session status: `GET /api/chat/session/status/1`
2. Check external service logs
3. Verify phone number format
4. Check webhook URL in external service config

### QR Code Expires

**Issue:** QR scan fails after 5 minutes

**Solution:** Call `GET /api/chat/session/qr/:tokoId` to refresh

---

## Performance Optimization

### 1. SSE Scalability

For high traffic, consider:
- Message queue (Redis/RabbitMQ) instead of file-based
- Database-backed SSE instead of files
- Load balancing across multiple workers

### 2. Message Storage

- Add pagination to chat list/messages
- Implement message archival
- Regular database cleanup

### 3. Image Handling

- Limit image size (max 5MB)
- Cache converted WebP images
- CDN for image serving

---

## Files Created/Modified

### New Files
- `app/Controllers/ChatSessionController.php`
- `app/Controllers/ChatWebhookController.php`
- `app/Controllers/ChatSSEController.php`
- `app/Libraries/ChatServiceAPI.php`
- `app/Models/ChatSessionModel.php`
- `app/Database/Migrations/2026-04-02-000002_AddChatSessionToToko.php`
- `app/Commands/CleanupSSECommand.php`

### Configuration
- `.env` - Add `CHAT_API_BASE_URL`
- `app/Config/Routes.php` - Add chat routes

### Documentation
- `CHAT_ROUTES_CONFIG.md` - Routes setup guide

---

**Last Updated:** April 2, 2026
