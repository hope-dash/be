# Dokumentasi API Integrasi Moota (Multi-Application/Multi-Toko)

Sistem ini mendukung pemisahan integrasi Moota per aplikasi/toko (`id_toko`). Setiap toko dapat memiliki setting kredensial Moota masing-masing yang disimpan di tabel `toko_meta`.

---

## 1. Konfigurasi Kredensial Per Toko
Simpan kredensial Moota per Toko di tabel `toko_meta` dengan keys berikut:
*   `moota_token`: Token API Moota untuk Toko tersebut.
*   `moota_secret`: Webhook Secret Token Moota untuk Toko tersebut.

> **Catatan:** Jika kredensial per toko kosong, sistem akan menggunakan variabel global `MOOTA_TOKEN` dan `MOOTA_SECRET` dari file `.env` sebagai fallback.

---

## 2. API Endpoints

### A. Tambah Rekening Bank ke Moota (Per Toko)
Mendaftarkan akun iBanking baru ke Moota menggunakan kredensial Toko tertentu.

*   **Endpoint:** `POST /api/v2/moota/bank`
*   **Request Body (JSON):**
    ```json
    {
      "id_toko": 5,
      "bank_type": "bca",
      "account_number": "1234567890",
      "username": "myibankinguser",
      "password": "mypassword",
      "pin": "123456"
    }
    ```
*   **Keterangan:** Ganti `id_toko` dengan ID Toko yang sesuai. API akan otomatis mengambil token Moota milik Toko 5 di database.

---

### B. List Rekening Bank Terdaftar (Per Toko)
Mengambil daftar seluruh rekening bank terdaftar di akun Moota milik Toko tertentu.

*   **Endpoint:** `GET /api/v2/moota/bank?id_toko=5`
*   **Keterangan:** Sertakan query parameter `id_toko` agar sistem menggunakan token API Moota dari Toko tersebut.

---

### C. Webhook Callback (Auto-Detect Toko)
Moota akan mengirimkan notifikasi mutasi masuk ke endpoint tunggal ini. Sistem akan otomatis memverifikasi signature menggunakan secret key masing-masing toko secara dinamis untuk mendeteksi asal Toko.

*   **Webhook URL:** `https://your-domain.com/api/v2/moota/webhook`
*   **Headers:**
    *   `Signature: <hmac_sha256_signature_from_moota>`
*   **Response (200 OK):**
    ```json
    {
      "status": true,
      "message": "Webhook verified and logged successfully",
      "id_toko": 5,
      "data": [
        {
          "mutation_id": "1234567",
          "amount": 100245,
          "type": "CR",
          "note": "TRANSFER DARI JOHN DOE",
          "bank_id": "ab12cd34-ef56-78gh-ij90-kl12md34ef56",
          "date": "2026-06-11 12:00:00"
        }
      ]
    }
    ```
    *(Response sukses akan mengembalikan `"id_toko": 5` yang menandakan mutasi ini milik Toko ID 5)*
