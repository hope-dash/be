# Dokumentasi API Integrasi Moota (Multi-Application/Multi-Toko)

Sistem ini mendukung pemisahan integrasi Moota per aplikasi/toko (`id_toko`). Setiap toko dapat memiliki setting kredensial Moota masing-masing yang disimpan di tabel `toko_meta` atau dikonfigurasi langsung sebagai bank utama Toko tersebut.

---

## 1. Database Schema Updates

### A. Tabel `tenants`
Ditambahkan kolom `moota_app_id` (VARCHAR 100) untuk menampung Application ID dari Moota.
*   **Query / Migration:** `2026-06-11-170000_AddMootaAppIdToTenants.php`

### B. Tabel `toko`
Ditambahkan kolom-kolom berikut untuk mengintegrasikan rekening bank Toko ke Moota secara otomatis:
*   `moota_connection` (TINYINT 1, default 0): Menentukan apakah rekening bank Toko dihubungkan ke Moota.
*   `moota_bank_type` (VARCHAR 50): Tipe bank yang didaftarkan ke Moota.
*   `moota_username` (VARCHAR 100): Username iBanking (atau nomor ponsel untuk Gojek/Ovo).
*   `moota_password` (VARCHAR 255): Password iBanking.
*   `moota_bank_id` (VARCHAR 100): ID Bank yang didapatkan setelah sukses didaftarkan ke Moota.
*   **Query / Migration:** `2026-06-11-171000_AddMootaFieldsToToko.php`

---

## 2. API Endpoints

### A. Konfigurasi Bank Toko & Koneksi Moota
Menyimpan konfigurasi rekening bank Toko. Jika `moota_connection` diset ke `true`, sistem akan otomatis mendaftarkan rekening tersebut ke API Moota menggunakan `MOOTA_APP_ID` milik Tenant saat ini sebagai `corporate_id`.

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
Mengambil konfigurasi bank Toko termasuk informasi koneksi Moota dan `corporate_id` (diisi `moota_app_id` milik Tenant).

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
        "corporate_id": "your-moota-app-id",
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
Memutuskan hubungan Moota dan menghapus data konfigurasi bank Toko. Jika sebelumnya terhubung, API juga akan otomatis menghapus bank tersebut dari server Moota.

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
