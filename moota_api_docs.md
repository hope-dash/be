# Dokumentasi API Integrasi Moota (Local Proxy)

Berikut adalah daftar endpoint API lokal yang kita miliki untuk berinteraksi dengan Moota API:

---

## 1. Konfigurasi Environment (`.env`)
Pastikan variabel berikut sudah terkonfigurasi di file `.env` backend Anda:

```env
MOOTA_TOKEN=your_bearer_token_here
MOOTA_SECRET=your_webhook_secret_here
```

---

## 2. Daftar API Endpoints (Local Proxy)

| No | Nama API | Method | Endpoint | Keterangan |
|---|---|---|---|---|
| 1 | **Tambah Rekening Bank** | `POST` | `/api/v2/moota/bank` | Mendaftarkan akun bank baru ke sistem Moota secara remote. |
| 2 | **List Rekening Bank** | `GET` | `/api/v2/moota/bank` | Mengambil seluruh data rekening terdaftar dari Moota. |
| 3 | **Moota Webhook Receiver** | `POST` | `/api/v2/moota/webhook` | Menerima & memverifikasi callback mutasi masuk secara otomatis dari server Moota. |

---

### Detail Endpoint:

### 1. Tambah Rekening Bank
*   **Endpoint:** `/api/v2/moota/bank`
*   **Method:** `POST`
*   **Headers:**
    *   `Authorization: Bearer <JWT_Token>`
    *   `X-Tenant: <Tenant_ID>`
    *   `Content-Type: application/json`
*   **Request Body (JSON):**
    ```json
    {
      "bank_type": "bca",
      "account_number": "1234567890",
      "username": "myibankinguser",
      "password": "mypassword",
      "pin": "123456"
    }
    ```
*   **Response (201 Created):**
    ```json
    {
      "status": "Success",
      "message": "Bank account successfully added to Moota.",
      "data": {
        "bank_id": "ab12cd34-ef56-78gh-ij90-kl12md34ef56",
        "bank_type": "bca",
        "account_number": "1234567890",
        "account_name": "JOHN DOE",
        "username": "myibankinguser",
        "status": "ACTIVE"
      }
    }
    ```

---

### 2. List Rekening Bank
*   **Endpoint:** `/api/v2/moota/bank`
*   **Method:** `GET`
*   **Headers:**
    *   `Authorization: Bearer <JWT_Token>`
    *   `X-Tenant: <Tenant_ID>`
*   **Response (200 OK):**
    ```json
    {
      "status": "Success",
      "message": "Success fetching bank accounts from Moota.",
      "data": [
        {
          "bank_id": "ab12cd34-ef56-78gh-ij90-kl12md34ef56",
          "bank_type": "bca",
          "account_number": "1234567890",
          "account_name": "JOHN DOE",
          "username": "myibankinguser",
          "status": "ACTIVE"
        }
      ]
    }
    ```

---

### 3. Moota Webhook Receiver
*   **Endpoint:** `/api/v2/moota/webhook`
*   **Method:** `POST`
*   **Headers:**
    *   `Signature: <hmac_sha256_signature_from_moota>`
*   **Request Body (JSON dari Moota):**
    ```json
    [
      {
        "mutation_id": "1234567",
        "amount": 100245,
        "type": "CR",
        "note": "TRANSFER DARI JOHN DOE UNTUK INV01",
        "bank_id": "ab12cd34-ef56-78gh-ij90-kl12md34ef56",
        "date": "2026-06-11 12:00:00"
      }
    ]
    ```
*   **Response (200 OK):**
    ```json
    {
      "status": true,
      "message": "Webhook verified and logged successfully",
      "data": [
        {
          "mutation_id": "1234567",
          "amount": 100245,
          "type": "CR",
          "note": "TRANSFER DARI JOHN DOE UNTUK INV01",
          "bank_id": "ab12cd34-ef56-78gh-ij90-kl12md34ef56",
          "date": "2026-06-11 12:00:00"
        }
      ]
    }
    ```
