# Chat Session Integration - Implementation Checklist & Quick Start

## ✅ Implementation Checklist

### Phase 1: Database & Models
- [x] Create migration: `AddChatSessionToToko.php`
- [x] Create model: `ChatSessionModel.php`
- [ ] Run migration: `php spark migrate`
- [ ] Verify toko table has new columns

### Phase 2: Libraries & Controllers  
- [x] Create library: `ChatServiceAPI.php`
- [x] Create controller: `ChatSessionController.php`
- [x] Create controller: `ChatWebhookController.php`
- [x] Create controller: `ChatSSEController.php`
- [x] Create command: `CleanupSSECommand.php`

### Phase 3: Configuration
- [ ] Add routes to `app/Config/Routes.php`
- [ ] Update `.env` with `CHAT_API_BASE_URL`
- [ ] Test external service connectivity

### Phase 4: Testing
- [ ] Test session start endpoint
- [ ] Test QR generation
- [ ] Test session status polling
- [ ] Test message sending
- [ ] Test SSE subscription
- [ ] Test webhook integration

### Phase 5: Deployment
- [ ] Set up cron for cleanup: `php spark chat:cleanup-sse`
- [ ] Configure external service webhooks
- [ ] Set up monitoring/alerts
- [ ] Document for team

---

## 🚀 Quick Start Guide

### Step 1: Run Database Migration

```bash
cd /path/to/project
php spark migrate --namespace App
```

**Verify:**
```bash
php spark db:table toko
```

You should see these new columns:
```
- chat_session_id (VARCHAR 100)
- chat_session_status (VARCHAR 50)
```

---

### Step 2: Update Routes Configuration

Edit `app/Config/Routes.php`

Find the line with `'/ 4. ADMIN PROTECTED ROUTES`  and add this BEFORE the end of that group (before the closing brace):

```php
    // --- CHAT SESSION MANAGEMENT (NEW) ---
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
```

Also add webhook route to **public routes section** (before protected routes):

```php
// --- WEBHOOKS (No Auth Required) ---
$routes->post('api/chat/webhook/(:num)', 'ChatWebhookController::incoming/$1');
```

---

### Step 3: Configure Environment Variables

Edit `.env` file and add:

```env
# WhatsApp Chat Service Configuration
CHAT_API_BASE_URL=http://localhost:3000
```

For production:
```env
CHAT_API_BASE_URL=https://chat-api.yourdomain.com
```

---

### Step 4: Test Connectivity

```bash
# Test external service is reachable
curl -X GET http://localhost:3000/api/session/status/test

# If successful, you should get a response (may be error but connection works)
# If fails, check:
# 1. Is external service running?
# 2. Is URL correct?
# 3. Network/firewall issues?
```

---

### Step 5: Test Session Start (via API)

```bash
# Start a session for store ID 1
curl -X POST http://yourdomain.com/api/chat/session/start \
  -H "X-Tenant: 1" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "toko_id=1"

# Response should look like:
# {
#     "success": true,
#     "data": {
#         "toko_id": 1,
#         "sessionId": "toko_name_abc123_1",
#         "status": "qr_ready",
#         "qr": "data:image/png;base64,..."
#     }
# }
```

---

### Step 6: Configure External Service Webhooks

In your external WhatsApp service admin panel, configure webhook:

**Webhook URL:** `https://yourdomain.com/api/chat/webhook/{tokoId}`

**Example:**
- For store 1: `https://yourdomain.com/api/chat/webhook/1`
- For store 2: `https://yourdomain.com/api/chat/webhook/2`

**Webhook Events to Enable:**
- Incoming messages
- Message status (delivered, read, failed)
- Session status changes

**Example Webhook Payload:**
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

### Step 7: Set Up Cron for SSE Cleanup

Add to system crontab or use CI scheduler:

```bash
# Crontab (run every hour)
0 * * * * cd /path/to/app && php spark chat:cleanup-sse

# Or via CI cronjob
php spark commands -l | grep chat
```

Manual cleanup:
```bash
php spark chat:cleanup-sse
```

---

## 📋 Testing Scenarios

### Scenario 1: Start and Connect Session

```bash
# 1. Start session
curl -X POST http://yourdomain.com/api/chat/session/start \
  -H "Authorization: Bearer TOKEN" \
  -d "toko_id=1"

# 2. Extract and display QR from response

# 3. User scans QR with WhatsApp phone

# 4. Check status every 2 seconds
curl -X GET http://yourdomain.com/api/chat/session/status/1 \
  -H "Authorization: Bearer TOKEN"

# Continue until status = "ready"
```

### Scenario 2: Send Message

```bash
# Once session is ready, send message
curl -X POST http://yourdomain.com/api/chat/send \
  -H "Authorization: Bearer TOKEN" \
  -d "toko_id=1&to=6281234567890&text=Hello from WhatsApp API!"

# Check logs to confirm message was sent
tail -f writable/logs/
```

### Scenario 3: Receive and Stream Messages

**Terminal 1: Subscribe to events**
```bash
curl -N http://yourdomain.com/api/chat/events/1 \
  -H "Authorization: Bearer TOKEN"

# Will stream events as they arrive
# Wait for new messages...
```

**Terminal 2: Webhook simulation (external service sends)**
```bash
curl -X POST http://yourdomain.com/api/chat/webhook/1 \
  -H "Content-Type: application/json" \
  -d '{
    "type": "message",
    "from": "6281234567890@c.us",
    "text": "Hello back!",
    "timestamp": 1704067200
  }'
```

**Terminal 1 Output:**
```
data: {"type":"new_message","chat_id":1,"from":"6281234567890@c.us","text":"Hello back!","timestamp":1704067200}
```

---

## 🐛 Troubleshooting

### Issue: Controllers not found

**Error:**
```
Class not found: App\Controllers\ChatSessionController
```

**Solution:**
1. Verify files are in correct directory: `app/Controllers/`
2. Check class namespace: `namespace App\Controllers;`
3. Clear cache: `php spark cache:clear`
4. Regenerate autoload: `composer dump-autoload`

---

### Issue: Routes not working

**Error:**
```
404 Not Found: /api/chat/session/start
```

**Solution:**
1. Verify routes added to `app/Config/Routes.php`
2. Remove trailing whitespace in routes file
3. Test route exists: `php spark routes | grep chat`
4. Ensure namespace matches in Routes.php

---

### Issue: External service connection fails

**Error:**
```
Failed to start session: Connection refused
```

**Solution:**
1. Check external service is running
2. Verify URL in `.env`: `CHAT_API_BASE_URL`
3. Test curl: `curl http://localhost:3000/api/session/status/test`
4. Check firewall/network ACL

---

### Issue: SSE not receiving events

**Error:**
No events received when connected via `curl` or browser

**Solution:**
1. Check queue directory: `ls -la writable/sse-messages/`
2. Manually trigger webhook: See Scenario 3
3. Check logs: `tail -f writable/logs/`
4. Verify queue file is being created: `ls -lah writable/sse-messages/toko_1.queue`

---

### Issue: Permission denied on writable directory

**Error:**
```
Permission denied: writable/sse-messages/
```

**Solution:**
```bash
chmod 777 writable/sse-messages/
chmod 777 writable/uploads/wa/
chmod 777 writable/logs/
```

Or with correct user:
```bash
sudo chown www-data:www-data writable/ -R
chmod 755 writable/ -R
```

---

## 📊 Database Verification

### Check migrated columns

```sql
DESC toko;

-- Should show:
-- ... existing columns ...
-- chat_session_id        | varchar(100)  | YES  |
-- chat_session_status    | varchar(50)   | YES  |
```

### Query active sessions

```sql
SELECT id, toko_name, chat_session_id, chat_session_status, updated_at
FROM toko
WHERE chat_session_status = 'ready';
```

### Check message history

```sql
SELECT wm.id, wm.direction, wm.text, wm.received_at
FROM whatsapp_messages wm
WHERE wm.chat_id = 1
ORDER BY wm.received_at DESC
LIMIT 10;
```

---

## 🔍 Logging & Monitoring

### Check application logs

```bash
tail -f writable/logs/
```

### Check WhatsApp-specific logs

```bash
grep -r "ChatServiceAPI\|ChatSession\|ChatWebhook" writable/logs/
```

### Monitor SSE queue

```bash
watch -n 1 'ls -lah writable/sse-messages/'
wc -l writable/sse-messages/toko_*.queue
```

---

## 📱 Frontend Integration Example

### React Hook for Chat

```typescript
import { useEffect, useState } from 'react';

export function useWhatsAppChat(tokoId: number, token: string) {
    const [status, setStatus] = useState('disconnected');
    const [qr, setQr] = useState<string | null>(null);
    const [messages, setMessages] = useState([]);
    const [eventSource, setEventSource] = useState<EventSource | null>(null);

    const startSession = async () => {
        try {
            const response = await fetch('/api/chat/session/start', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `toko_id=${tokoId}`
            });

            const data = await response.json();
            setQr(data.data.qr);
            pollStatus();
        } catch (error) {
            console.error('Failed to start session:', error);
        }
    };

    const pollStatus = async () => {
        const interval = setInterval(async () => {
            try {
                const response = await fetch(`/api/chat/session/status/${tokoId}`, {
                    headers: { 'Authorization': `Bearer ${token}` }
                });

                const data = await response.json();
                setStatus(data.data.status);

                if (data.data.status === 'ready') {
                    clearInterval(interval);
                    subscribeToEvents();
                }
            } catch (error) {
                console.error('Failed to get status:', error);
            }
        }, 2000);
    };

    const subscribeToEvents = () => {
        const es = new EventSource(`/api/chat/events/${tokoId}`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });

        es.addEventListener('message', (event) => {
            const data = JSON.parse(event.data);
            if (data.type === 'new_message') {
                setMessages(prev => [...prev, data]);
            }
        });

        es.addEventListener('error', () => {
            es.close();
        });

        setEventSource(es);
    };

    const sendMessage = async (to: string, text: string) => {
        try {
            await fetch('/api/chat/send', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `toko_id=${tokoId}&to=${to}&text=${text}`
            });
        } catch (error) {
            console.error('Failed to send message:', error);
        }
    };

    useEffect(() => {
        return () => {
            eventSource?.close();
        };
    }, [eventSource]);

    return {
        status,
        qr,
        messages,
        startSession,
        sendMessage,
    };
}
```

---

## 📞 Support & References

- External Service Docs: See provider documentation
- CodeIgniter Docs: https://codeigniter.com/user_guide/
- SSE Reference: https://html.spec.whatwg.org/multipage/server-sent-events.html

---

**Last Updated:** April 2, 2026
