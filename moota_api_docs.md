# Dokumentasi API Integrasi Moota (Multi-Application/Multi-Toko)

Sistem ini mendukung pemisahan integrasi Moota per aplikasi/toko (`id_toko`). Setiap toko dapat memiliki setting kredensial Moota masing-masing yang disimpan di tabel `toko` atau dikonfigurasi langsung sebagai bank utama Toko tersebut. Setiap tenant mengelola autentikasi Moota menggunakan token unik (`moota_token`).

---

## 1. Database Schema Updates

### A. Tabel `tenants`
Ditambahkan kolom `moota_token` (TEXT) untuk menampung token akses Moota per tenant.
*   **Migration:** `2026-06-11-170000_AddMootaAppIdToTenants.php`

### B. Tabel `toko`
Ditambahkan kolom-kolom berikut untuk mengintegrasikan rekening bank Toko ke Moota secara otomatis:
*   `moota_connection` (TINYINT 1, default 0): Menentukan apakah rekening bank Toko dihubungkan ke Moota.
*   `moota_bank_type` (VARCHAR 50): Tipe bank yang didaftarkan ke Moota.
*   `moota_username` (VARCHAR 100): Username iBanking (atau nomor ponsel untuk Gojek/Ovo).
*   `moota_password` (VARCHAR 255): Password iBanking.
*   `moota_bank_id` (VARCHAR 100): ID Bank yang didapatkan setelah sukses didaftarkan ke Moota.
*   **Migration:** `2026-06-11-171000_AddMootaFieldsToToko.php`

---

## 2. API Endpoints

### A. Konfigurasi Bank Toko & Koneksi Moota
Menyimpan konfigurasi rekening bank Toko. Jika `moota_connection` diset ke `true`, sistem akan otomatis mendaftarkan rekening tersebut ke API Moota menggunakan token (`moota_token`) milik Tenant saat ini dengan parameter `corporate_id` dikosongkan.

*   **Endpoint:** `POST /api/v2/toko/(:num)/bank` (Contoh: `/api/v2/toko/5/bank`)
*   **Headers:**
    *   `Authorization: Bearer <JWT_Token>`
    *   `X-Tenant: <Tenant_ID>`
    *   `Content-Type: application/json`
*   **Request Body (JSON):**
    ```json
    {
      "bank_type": "bca",
      "account_number": 16899030,
      "name_holder": "Loream Kasma",
      "moota_connection": true,
      "username": "loream",
      "password": "yourpassword",
      "is_active": true
    }
    ```
    > **Catatan tipe bank (`bank_type`):**
    > `bca`, `bcaSyariah`, `bni`, `bniSyariah`, `bri`, `briCms`, `briGiro`, `briSyariah`, `briSyariahCms`, `mandiriOnline`, `mandiriBisnis`, `mandiriMcm`, `mandiriSyariah`, `mandiriSyariahMcm`, `mandiriSyariahBisnis`, `bniBisnis`, `muamalat`, `bniBisnisSyariah`, `gojek`, `ovo`.
    > *(Khusus tipe `gojek` dan `ovo`, isi field `username` dengan nomor ponsel Anda).*

*   **Response (200 OK):**
    ```json
    {
      "status": "Success",
      "message": "Toko bank configuration updated successfully",
      "data": {
        "bank": "bca",
        "nama_pemilik": "Loream Kasma",
        "nomer_rekening": 16899030,
        "moota_connection": 1,
        "moota_bank_type": "bca",
        "moota_username": "loream",
        "moota_password": "yourpassword",
        "moota_bank_id": "moota-bank-uuid-from-response"
      }
    }
    ```

---

### B. Ambil Konfigurasi Bank Toko
Mengambil konfigurasi bank Toko termasuk informasi koneksi Moota.

*   **Endpoint:** `GET /api/v2/toko/(:num)/bank` (Contoh: `/api/v2/toko/5/bank`)
*   **Headers:**
    *   `Authorization: Bearer <JWT_Token>`
    *   `X-Tenant: <Tenant_ID>`
*   **Response (200 OK):**
    ```json
    {
      "status": "Success",
      "message": "Success fetching bank config",
      "data": {
        "bank_type": "bca",
        "username": "loream",
        "password": "yourpassword",
        "name_holder": "Loream Kasma",
        "account_number": "16899030",
        "moota_connection": true,
        "moota_bank_id": "moota-bank-uuid"
      }
    }
    ```

---

### C. Putus Koneksi / Hapus Konfigurasi Bank Toko
Memutuskan hubungan Moota dan menghapus data konfigurasi bank Toko. Jika sebelumnya terhubung, API juga akan otomatis menghapus bank tersebut dari server Moota menggunakan endpoint `POST /bank/{bank_id}/destroy`.

*   **Endpoint:** `DELETE /api/v2/toko/(:num)/bank` (Contoh: `/api/v2/toko/5/bank`)
*   **Headers:**
    *   `Authorization: Bearer <JWT_Token>`
    *   `X-Tenant: <Tenant_ID>`
*   **Response (200 OK):**
    ```json
    {
      "status": "Success",
      "message": "Bank configuration cleared successfully",
      "data": null
    }
    ```

---

### D. Create Transaction dengan Integrasi Moota
Membuat transaksi baru. Jika toko (`id_toko`) memiliki `moota_connection` aktif, maka request ke API Moota `/create-transaction` akan dipicu. Response Moota yang berisi `unique_code`, `trx_id`, dan `payment_url` akan diproses. Kode unik ditambahkan langsung ke `actual_total` transaksi, dan data-data Moota tersebut disimpan ke metadata transaksi.

*   **Endpoint:** `POST /api/v2/transaction`
*   **Headers:**
    *   `Authorization: Bearer <JWT_Token>`
    *   `X-Tenant: <Tenant_ID>`
    *   `Content-Type: application/json`
*   **Request Body (JSON):**
    *   Sama seperti request create transaction standar (berisi `id_toko`, `products`, `customer_name`, dll).
*   **Hasil Integrasi Moota di Database (Tabel `transaction_meta`):**
    *   `moota_unique_code`: Kode unik yang di-generate Moota (menambahkan nominal total tagihan).
    *   `moota_bank_id`: ID Bank tujuan transfer.
    *   `moota_bank_type`: Jenis bank (misal: `bca`).
    *   `moota_nomer_rekening`: Nomor rekening tujuan transfer.
    *   `moota_trx_id`: ID transaksi pembayaran Moota.
    *   `moota_payment_url`: Tautan/URL halaman pembayaran Moota.
    *   `moota_expired_at`: Batas waktu aktif kode unik (di-set otomatis 1 tahun).
