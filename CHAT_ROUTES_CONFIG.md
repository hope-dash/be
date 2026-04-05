# Chat Session Integration - Routes Configuration

Add these routes to `app/Config/Routes.php` inside the protected routes section (after the `wa-chats` group):

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

    // SSE (Server-Sent Events) - Real-time Updates
    $routes->get('events/(:num)', 'ChatSSEController::subscribe/$1');
    $routes->get('events/(:num)/chat/(:num)', 'ChatSSEController::subscribeChat/$1/$2');
});

// --- WEBHOOK (No Auth Required) ---
$routes->post('api/chat/webhook/(:num)', 'ChatWebhookController::incoming/$1');
```

Add this to `Public Routes` section (before protected routes):

```php
// Chat Webhook - no authentication required
$routes->post('api/chat/webhook/(:num)', 'ChatWebhookController::incoming/$1');
```

## Environment Configuration

Add to `.env` file:

```env
# WhatsApp Chat Service Configuration
CHAT_API_BASE_URL=http://localhost:3000
# or for production:
# CHAT_API_BASE_URL=https://chat-service.example.com
```

## Database Migration

Run migration to add chat session fields to toko table:

```bash
php spark migrate
```

This will execute:
- `2026-04-02-000002_AddChatSessionToToko.php`

Adds to `toko` table:
- `chat_session_id` (VARCHAR 100) - WhatsApp session ID
- `chat_session_status` (VARCHAR 50) - Session status

## API Endpoints Summary

### Session Management

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/chat/session/start` | Start new session & get QR | ✓ JWT |
| GET | `/api/chat/session/status/:tokoId` | Check session status | ✓ JWT |
| GET | `/api/chat/session/qr/:tokoId` | Get QR code | ✓ JWT |
| POST | `/api/chat/session/disconnect/:tokoId` | Disconnect session | ✓ JWT |

### Messaging

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/chat/send` | Send text/image message | ✓ JWT |

### Real-time Events (SSE)

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/api/chat/events/:tokoId` | Subscribe to store events | ✓ JWT |
| GET | `/api/chat/events/:tokoId/chat/:chatId` | Subscribe to chat events | ✓ JWT |

### Webhooks

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/chat/webhook/:tokoId` | Receive messages from service | ✗ None |

## Scheduled Tasks

Add to your cron or scheduler:

```bash
# Clean up old SSE queue files (run hourly)
php spark chat:cleanup-sse
```

Or in CodeIgniter's scheduler (if using Events):

```php
Events::on('schedule:run', function() {
    \App\Controllers\ChatSSEController::cleanupQueues();
});
```
