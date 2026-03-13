# Documentation API V2 (Accounting & Inventory)

Dokumentasi untuk sistem akuntansi V2 yang mencakup Transaksi Penjualan, Pembelian (Purchase), Pengeluaran (Expense), dan Laporan Keuangan (Reporting).

**Base URL**: `/api/v2`  
**Authentication**: Bearer Token (JWT) di Header `Authorization`

---

## 1. PRODUCT (Master Data)

### A. Create Product (V2)

Membuat data master barang baru.
**Note**:

- Stock awal otomatis 0. Penambahan stok harus melalui modul **Purchase (Belanja)**.
- **Kode Barang (`id_barang`)** otomatis dibentuk dari `kode_awal` (dari `id_model`) + Nomor Urut (3 digit). Contoh: Model `IPH` -> `IPH001`.

- **URL**: `POST /v2/product`
- **Body**:

```json
{
  "id_model": 1, // Menentukan kode awal barang (e.g. IPHONE -> IPH)
  "id_seri_barang": 5, // Optional: Seri barang (e.g. 15 Pro, 14, etc - relational)
  "nama_barang": "iPhone 15 Pro Max 256GB",
  "harga_modal": 18500000,
  "harga_jual": 21000000,
  "harga_jual_toko": 20500000, // Optional: Harga khusus toko/reseller
  "suplier": ["Distributor A", "Toko B"], // Optional: Array of supplier names or String
  "description": "Garansi Resmi iBox 1 Tahun",
  "notes": "Hati-hati barang sensitif",
  "berat": 1000,
  "stock": [
    { "id_toko": 1 }, // Stock value ignored (set 0)
    { "id_toko": 2 }
  ]
}
```

### B. Update Product (V2)

Update detail barang.
**Note**: Tidak bisa update stok dari sini. Hanya update info barang dan ketersediaan toko.

- **URL**: `PUT /v2/product/{id}`

---

## 2. TRANSACTION (Penjualan / Invoice)

### A. Create Transaction (Invoice Baru)

Membuat invoice penjualan baru. Stock akan berkurang di `id_toko` yang bersangkutan.

- **URL**: `POST /v2/transaction`
- **Body**:

```json
{
  "id_toko": 1,
  "items": [
    { "id_barang": "A001", "qty": 2, "price": 100000 }, // price override allowed
    { "id_barang": "B002", "qty": 1 }
  ],
  "customer_phone": "08123456789", // Auto create customer if new
  "customer_name": "Budi",
  "biaya_pengiriman": 15000,
  "free_ongkir": false, // Jika true, biaya pengiriman ditanggung toko (Expense)
  "discount_amount": 5000,
  "po": false
}
```

### B. Cancel Transaction

Batalkan transaksi.

- Jika **belum bayar**: Status `CANCEL`.
- Jika **sudah bayar**: Status `NEED_REFUND`. (Journal cancel tercatat, tapi hutang refund muncul).

- **URL**: `POST /v2/transaction/{id}/cancel`
- **Body**:

```json
{
  "cancel_reason": "Customer request / Stock tidak tersedia / etc"
}
```

---

### C. Transaction Status Lifecycle

**Status Transaksi** dan **Status Pengiriman** adalah dua hal yang berbeda.

#### Status Transaksi (Payment Status):

| Status           | Deskripsi                                        | Lifecycle                                                                 |
| ---------------- | ------------------------------------------------ | ------------------------------------------------------------------------- |
| `UNPAID`         | Belum ada pembayaran sama sekali                 | Status awal saat transaksi dibuat                                         |
| `PARTIALLY_PAID` | Sudah bayar DP/sebagian                          | Dari `UNPAID` → `PARTIALLY_PAID` (saat add payment pertama)               |
| `PAID`           | Lunas                                            | Dari `UNPAID`/`PARTIALLY_PAID` → `PAID` (saat total payment = amount)     |
| `CANCEL`         | Dibatalkan (belum bayar)                         | Dari `UNPAID` → `CANCEL` (via cancel endpoint)                            |
| `NEED_REFUND`    | Butuh refund (sudah bayar tapi dibatalkan/retur) | Dari `PAID`/`PARTIALLY_PAID` → `NEED_REFUND` (via cancel/return endpoint) |

#### Contoh Lifecycle:

**Scenario 1: Normal Flow (DP → Lunas)**

```
UNPAID → PARTIALLY_PAID (add payment 50%) → PAID (add payment 50%)
```

**Scenario 2: Langsung Lunas**

```
UNPAID → PAID (add payment 100%)
```

---

## 9. SUBSCRIPTION & TENANT (Multi-Client)

Endpoint di bagian ini dipakai untuk melihat paket subscription tenant, kuota, dan pemakaian.

**Headers wajib (Admin V2)**:

- `Authorization: Bearer <jwt>` (User JWT)
- `X-Tenant: <tenant_code>` (contoh: `hope`)

> Catatan URL: route sebenarnya di server adalah `/api/v2/...` (karena group `api` + `v2`).

### A. Subscription Detail (Active)

Mendapatkan subscription aktif tenant + paketnya.

- **URL**: `GET /api/v2/subscription`
- **Auth**: User JWT Token Required
- **Response**:

```json
{
  "status": true,
  "message": "Sukses",
  "data": {
    "subscription": {
      "id": 1,
      "tenant_id": 1,
      "package_id": 1,
      "status": "active",
      "start_at": "2026-03-13 12:00:00",
      "end_at": "2126-03-13 12:00:00"
    },
    "package": {
      "code": "BASIC_3M",
      "name": "Basic 3 Bulan",
      "duration_months": 3,
      "product_quota": 500,
      "transaction_monthly_quota": 2000
    }
  }
}
```

### B. Subscription Usage (Kuota & Pemakaian)

Mendapatkan kuota & pemakaian tenant:

- `products.used` dihitung dari jumlah row `product` tenant (exclude soft delete).
- `transactions_monthly.used` dihitung dari jumlah row `transaction` tenant di bulan berjalan (reset otomatis tiap bulan karena query range).

- **URL**: `GET /api/v2/subscription/usage`
- **Auth**: User JWT Token Required
- **Response**:

```json
{
  "status": true,
  "message": "Sukses",
  "data": {
    "subscription": {
      "start_at": "2026-03-01 00:00:00",
      "end_at": "2026-03-31 23:59:59",
      "status": "active"
    },
    "period": {
      "month_start": "2026-03-01 00:00:00",
      "month_end": "2026-03-31 23:59:59"
    },
    "package": {
      "code": "BASIC_3M",
      "name": "Basic 3 Bulan",
      "duration_months": 3
    },
    "usage": {
      "products": { "used": 120, "limit": 500, "remaining": 380 },
      "transactions_monthly": { "used": 430, "limit": 2000, "remaining": 1570 }
    }
  }
}
```

Jika kuota unlimited, `limit` dan `remaining` akan bernilai `null`.

### C. List Subscription Packages

List paket subscription yang tersedia (aktif).

- **URL**: `GET /api/v2/subscription/packages`
- **Auth**: User JWT Token Required
- **Response**:

```json
{
  "status": true,
  "message": "Sukses",
  "data": {
    "packages": [
      {
        "id": 1,
        "code": "BASIC_3M",
        "name": "Basic 3 Bulan",
        "price": 1500000,
        "currency": "IDR",
        "duration_months": 3,
        "product_quota": 500,
        "transaction_monthly_quota": 2000,
        "is_active": 1,
        "created_at": "2026-03-13 12:00:00",
        "updated_at": "2026-03-13 12:00:00"
      }
    ]
  }
}
```

### D. Create Subscription Order (Waiting Payment)

Membuat order subscription dengan status `waiting_payment`.

**Catatan**: `transaction_id` dipakai sebagai id transaksi dari payment gateway / sistem pembayaran (harus unique).

- **URL**: `POST /api/v2/subscription/orders`
- **Auth**: User JWT Token Required
- **Body**:

```json
{
  "transaction_id": "PAY-INV-123456",
  "package_code": "BASIC_3M"
}
```

atau bisa pakai `package_id`:

```json
{
  "transaction_id": "PAY-INV-123456",
  "package_id": 1
}
```

- **Response**:

```json
{
  "status": true,
  "message": "Order created",
  "data": {
    "order": {
      "id": 10,
      "tenant_id": 1,
      "package_id": 1,
      "external_transaction_id": "PAY-INV-123456",
      "status": "waiting_payment",
      "amount": 1500000,
      "currency": "IDR",
      "paid_at": null,
      "created_at": "2026-03-13 12:00:00",
      "updated_at": "2026-03-13 12:00:00"
    }
  }
}
```

### E. Execute Payment Success (Mark Order Paid)

Menandai order subscription menjadi `paid`, lalu mengaktifkan / memperpanjang subscription tenant.

- **URL**: `POST /api/v2/subscription/orders/{id}/pay`
- **Auth**: Public (No JWT / No X-Tenant)
- **Optional Security**: jika env `subscription.webhookKey` diset, wajib kirim `X-Webhook-Key: <key>` atau query `?key=<key>`
- **Rules**:
  - Jika tenant masih punya subscription aktif dan beli **package yang sama**, maka `end_at` akan **ditambah durasi package** dari `end_at` saat ini.
  - Jika beli **package berbeda**, maka subscription aktif akan di-`expired` dan dibuat subscription baru dengan `start_at = today` dan `end_at = today + duration_months`.
  - Setelah payment success, kuota tenant akan disesuaikan dengan package aktif.

- **Response**:

```json
{
  "status": true,
  "message": "Payment success",
  "data": {
    "order": {
      "id": 10,
      "tenant_id": 1,
      "package_id": 1,
      "external_transaction_id": "PAY-INV-123456",
      "status": "paid",
      "amount": 1500000,
      "currency": "IDR",
      "paid_at": "2026-03-13 12:05:00",
      "created_at": "2026-03-13 12:00:00",
      "updated_at": "2026-03-13 12:05:00"
    },
    "subscription": {
      "action": "extended",
      "subscription_id": 1,
      "start_at": "2026-01-01 00:00:00",
      "end_at": "2026-06-30 23:59:59"
    }
  }
}
```

### F. Get Tenant Detail by Code

Ambil detail tenant berdasarkan code.

- **URL**: `GET /api/v2/tenant/{code}`
- **Auth**: User JWT Token Required
- **Rules**:
  - Request hanya boleh akses tenant sendiri (`{code}` harus sama dengan `X-Tenant`).
- **Response**:

```json
{
  "status": true,
  "message": "Sukses",
  "data": {
    "tenant": {
      "id": 1,
      "code": "hope",
      "name": "HOPE",
      "logo_url": "https://cdn.example.com/tenant/hope/logo.png",
      "status": "active",
      "created_at": "2026-03-13 12:00:00",
      "updated_at": "2026-03-13 12:00:00"
    },
    "waiting_payment_order": {
      "id": 10,
      "external_transaction_id": "PAY-INV-123456",
      "status": "waiting_payment",
      "amount": 1500000,
      "currency": "IDR",
      "created_at": "2026-03-13 12:00:00"
    }
  }
}
```

Jika tidak ada order yang menunggu pembayaran, `waiting_payment_order` akan bernilai `null`.

### G. Cancel Subscription Order

Cancel order subscription yang statusnya masih `waiting_payment`.

- **URL**: `POST /api/v2/subscription/orders/{id}/cancel`
- **Auth**: User JWT Token Required

### H. Upload Payment Proof (Manual)

Upload bukti pembayaran manual untuk order berdasarkan `transaction_id`. Sistem akan mengirim email bukti pembayaran ke admin.

- **URL**: `POST /api/v2/subscription/orders/upload-proof`
- **Auth**: User JWT Token Required
- **Content-Type**: `multipart/form-data`
- **Form Data**:
  - `transaction_id` (string, required): external transaction id dari order
  - `image` (file, required): jpg/jpeg/png/webp/pdf

**Scenario 3: Cancel sebelum bayar**

```
UNPAID → CANCEL (via cancel endpoint)
```

**Scenario 4: Cancel setelah bayar**

```
PAID → NEED_REFUND (via cancel endpoint, refund_needed disimpan di meta)
```

**Scenario 5: Retur dengan refund**

```
PAID → NEED_REFUND (via return endpoint dengan refund_money: true)
```

**Status Pengiriman** (Shipping Status) disimpan di `transaction_meta` dengan key seperti:

- `resi` (nomor resi pengiriman)
- `shipping_status` (e.g., "PENDING", "SHIPPED", "DELIVERED")
- `courier` (e.g., "JNE", "J&T")

### D. List Transactions by Status

Mendapatkan daftar transaksi berdasarkan status pembayaran.

- **URL**: `GET /v2/transaction/list?status={status}&id_toko={id_toko}&page={page}&limit={limit}`
- **Query Parameters**:
  - `status` (optional): Filter by payment status (PAID, PARTIALLY_PAID, UNPAID, CANCEL, NEED_REFUND)
  - `id_toko` (optional): Filter by store ID
  - `page` (optional): Page number (default: 1)
  - `limit` (optional): Items per page (default: 20)

- **Response**:

```json
{
  "status": 200,
  "message": "",
  "data": [
    {
      "id": 1,
      "invoice": "INV-001",
      "status": "PAID",
      "amount": 1000000,
      "total_payment": 1000000,
      "meta": {
        "resi": "JNE123456789",
        "shipping_status": "SHIPPED",
        "courier": "JNE"
      }
    }
  ],
  "pagination": {
    "total_data": 50,
    "total_page": 3,
    "current_page": 1,
    "per_page": 20
  }
}
```

### E. Add Transaction Meta

Menambahkan metadata ke transaksi (e.g., nomor resi, status pengiriman, catatan).

- **URL**: `POST /v2/transaction/{id}/meta`
- **Body**:

```json
{
  "key": "resi",
  "value": "JNE123456789"
}
```

**Contoh penggunaan lain:**

```json
{
  "key": "shipping_status",
  "value": "SHIPPED"
}
```

```json
{
  "key": "courier",
  "value": "JNE"
}
```

**Note**: Jika key sudah ada, value akan di-update. Jika belum ada, akan dibuat baru.

### F. Update Delivery Status (Status Pengiriman)

Khusus untuk update status pengiriman, resi, dan kurir. Sinkron dengan data internal `delivery_status`.

- **URL**: `POST /v2/transaction/{id}/delivery-status`
- **Body**:

```json
{
  "status": "SHIPPED",
  "resi": "JNE0019283",
  "courier": "JNE"
}
```

- **Note**: Field optional, kirim yang perlu di-update saja.

### G. Get Transaction Detail

Mendapatkan detail lengkap transaksi termasuk items, metadata, dan payment history.

- **URL**: `GET /v2/transaction/{id}`

**Response**:

```json
{
  "status": 200,
  "data": {
    "id": 1,
    "invoice": "INV-001",
    "status": "PAID",
    "amount": 1000000,
    "actual_total": 950000,
    "discount_value": 50000,
    "discount_type": "FIXED",
    "meta": {
      "resi": "JNE123",
      "shipping_status": "SHIPPED",
      "province": "Jawa Barat",
      "city": "Bandung"
    },
    "items": [{ "nama_barang": "Laptop", "quantity": 1, "price": 1000000 }],
    "payments": [
      {
        "amount": 50000,
        "payment_method": "CASH",
        "paid_at": "...",
        "image_url": "..."
      }
    ]
  }
}
```

### H. Cancel Transaction

Membatalkan transaksi. Jika status `PAID` atau `PARTIALLY_PAID`, akan berubah jadi `NEED_REFUND`.
Stock dikembalikan. Jurnal dibalik.

- **URL**: `POST /v2/transaction/{id}/cancel`
- **Body**:

```json
{
  "cancel_reason": "Salah input barang"
}
```

### I. Refund Transaction

Mengembalikan dana customer. Mengurangi `total_payment` (bersih). Status menjadi `PARTIALLY_REFUNDED` atau `REFUNDED` tergantung sisa `refund_needed` (misal 90rb).

- **URL**: `POST /v2/transaction/{id}/refund`
- **Body**:

```json
{
  "amount": 50000,
  "reason": "Barang kosong",
  "image": "https://..." // Bukti transfer refund (optional)
}
```

### J. Return Product (Retur Barang)

Retur barang sebagian atau seluruhnya. Stock bertambah. Mengurangi value sales di jurnal.

- **URL**: `POST /v2/transaction/{id}/return`
- **Body**:

```json
{
  "items": [
    {
      "kode_barang": "PROD001",
      "qty": 1,
      "condition": "good" // good = masuk stock normal, bad = masuk barang cacat
    }
  ],
  "refund_money": true // Jika true, status jadi NEED_REFUND
}
```

      {
        "kode_barang": "A001",
        "nama_lengkap_barang": "iPhone 15 Pro Max",
        "jumlah": 1,
        "harga_system": 100000,
        "harga_jual": 90000,
        "total": 90000
      }
    ],
    "payments": [
      {
        "id": 1,
        "amount": 1000000,
        "method": "BANK",
        "paid_at": "2025-01-01 10:00:00"
      }
    ]

}
}

````

---

## 3. PAYMENTS & REFUNDS

### A. Add Payment (Penerimaan Pembayaran)

Mencatat pembayaran masuk (In).

- **URL**: `POST /v2/transaction/{id}/payment`
- **Body**:

```json
{
  "amount": 5000000,
  "payment_method": "BANK",
  "image": "url_bukti.jpg",
  "note": "Pelunasan"
}
````

### B. Refund Money (Pengembalian Dana)

Mencatat pengembalian uang ke customer (Out).

- **URL**: `POST /v2/transaction/{id}/refund`
- **Body**:

```json
{
  "amount": 500000,
  "reason": "Retur",
  "image": "url_bukti_refund.jpg"
}
```

### C. Return Product (Retur Barang)

Mengembalikan stock barang. Jika ada penggantian uang, set `refund_money: true`.

- **URL**: `POST /v2/transaction/{id}/return`
- **Body**:

```json
{
  "items": [{ "kode_barang": "A001", "qty": 1 }],
  "refund_money": true
}
```

---

## 4. INVENTORY (Stok)

### A. Purchase (Pembelian Barang / Re-stock)

Mencatat pembelian ke supplier. Stock bertambah otomatis dan _Weighted Average Cost_ (Harga Modal) akan dihitung ulang.

#### 1. Create Purchase (Draft)

- **URL**: `POST /v2/purchase`
- **Body**:

```json
{
  "tanggal_belanja": "2025-06-01",
  "supplier_id": 1, // Optional
  "id_toko": 1,
  "catatan": "Restock bulanan",
  "bukti_foto": "uploads/purchase/struk_1.webp",
  "detail": [
    {
      "kode_barang": "A001",
      "jumlah": 50,
      "harga_satuan": 95000,
      "ongkir": 1000 // Ongkir per item (optional)
    }
  ],
  "biaya": [{ "nama_biaya": "Parkir", "jumlah": 5000 }]
}
```

**Response**: `{ "status": 201, "data": { "id": 15 } }`

#### 2. Execute Purchase (Finalize)

Mengunci pembelian, update stok, dan catat jurnal.

- **URL**: `POST /v2/purchase/{id}/execute`
- **Body**: `{}` (Empty)

**Jurnal Otomatis**:

- (Dr) Inventory
- (Cr) Cash / Bank

### B. Stock Transfer (Antar Toko)

Memindahkan stok fisik dan nilai modal antar toko.
Jika ada **ongkos kirim**, biaya ini ditanggung oleh Toko Asal (`source_toko_id`) dan dicatat sebagai Pengeluaran (Expense).

- **URL**: `POST /v2/inventory/transfer`
- **Body**:

```json
{
  "source_toko_id": 1,
  "target_toko_id": 2,
  "items": [{ "kode_barang": "A001", "qty": 10 }],
  "ongkos_kirim": 15000, // Optional: Biaya kirim antar toko
  "payment_method": "CASH" // Sumber dana bayar ongkir (dari Toko 1)
}
```

---

## 5. EXPENSE (Pengeluaran Operasional)

### A. Get Expense Accounts

Mendapatkan daftar akun biaya yang tersedia.

- **URL**: `GET /v2/expense/accounts`

### B. Create Expense

Mencatat pengeluaran operasional. Data disimpan di tabel `expenses` dan otomatis tercatat di Jurnal.

- **URL**: `POST /v2/expense`
- **Body**:

```json
{
  "id_toko": 1,
  "account_code": "703", // Beban Listrik
  "amount": 500000,
  "payment_method": "CASH",
  "date": "2025-05-15",
  "description": "Bayar Token Listrik"
}
```

**Response**:

```json
{
  "status": 201,
  "message": "Expense recorded successfully",
  "data": {
    "expense_id": 15,
    "journal_id": 102
  }
}
```

---

## 6. FINANCE (Keuangan & Cashflow)

### A. Transfer Dana (Setor Bank / Pindah Buku)

Memindahkan uang dari Kas Toko ke Bank, atau antar toko.

- **URL**: `POST /v2/finance/transfer`
- **Body**:

```json
{
  "source_toko_id": 1,
  "target_toko_id": 1,
  "source_account_code": "1001", // Cash
  "target_account_code": "1002", // Bank
  "amount": 1000000
}
```

### B. Distribute Profit (Bagi Hasil Owner)

Digunakan untuk mencatat pengambilan keuntungan oleh pemilik (Prive/Dividen).

- Mengurangi Account Equity (3001).
- Jurnal: Dr Equity (3001) / Cr Bank or Cash.

- **URL**: `POST /v2/finance/distribute-profit`
- **Body**:

```json
{
  "id_toko": 1,
  "account_code": "1002", // Sumber dana (Bank/Cash)
  "amount": 25000000,
  "date": "2025-06-01"
}
```

---

## 7. ACCOUNTING REPORTS (Laporan Keuangan)

Semua report mendukung filter `id_toko`. Jika kosong = Konsolidasi (Gabungan).

### A. Get All Accounts (Dropdown)

Mendapatkan daftar semua akun untuk keperluan dropdown (Jurnal, Expense, dll).

- **URL**: `GET /v2/accounts?type={type}`
- **Query Params**:
  - `type` (optional): Filter account type (e.g., REVENUE, EXPENSE, ASSET, LIABILITY, EQUITY)

### B. Journal Report (Jurnal Umum)

Detail pergerakan debit/kredit setiap transaksi, dikelompokkan per jurnal.

- **URL**: `GET /v2/report/journal?start_date=...&end_date=...&id_toko=...`

**Response**:

```json
{
  "journals": [
    {
      "id": 101,
      "date": "2025-01-01",
      "reference_no": "INV-202501",
      "description": "Sales Invoice #1",
      "items": [
        { "account_code": "1001", "name": "Cash", "debit": 1000, "credit": 0 },
        { "account_code": "4001", "name": "Sales", "debit": 0, "credit": 1000 }
      ]
    }
  ],
  "total_debit": 1000,
  "total_credit": 1000
}
```

### C. General Ledger (Buku Besar)

Mutasi saldo per akun (Opening, Debit, Credit, Closing).

- **URL**: `GET /v2/report/ledger?start_date=...&end_date=...`

### D. General Ledger Detail (Mutasi Saldo per Akun)

Mutasi rinci per transaksi untuk satu akun (seperti di screenshot report).

- **URL**: `GET /v2/report/ledger/detail?account_id={id}&start_date={start}&end_date={end}&id_toko={id}`

**Response**:

```json
{
  "account": { "id": 1, "code": "1101", "name": "Kas Besar", "type": "ASSET" },
  "period": { "start": "2025-01-01", "end": "2025-01-31" },
  "opening_balance": 50000000,
  "transactions": [
    {
      "date": "2025-01-05",
      "reference_no": "INV-2025-001",
      "description": "Penjualan Tunai",
      "debit": 2500000,
      "credit": 0,
      "balance": 52500000
    },
    {
      "date": "2025-01-10",
      "reference_no": "EXP-2025-012",
      "description": "Pembayaran Listrik",
      "debit": 0,
      "credit": 750000,
      "balance": 51750000
    }
  ],
  "closing_balance": 52950000,
  "total_mutation": { "debit": 3700000, "credit": 750000 }
}
```

### E. Income Statement (Laporan Laba Rugi)

Ringkasan Pendapatan vs Pengeluaran dalam periode tertentu.

- **URL**: `GET /v2/report/income-statement?start_date=...&end_date=...&id_toko=...`
- **Response**:

**Response**:

```json
{
  "period": "2025-02-01 to 2025-02-28",
  "prev_period": "2025-01-04 to 2025-01-31",
  "summary_highlights": {
    "gross_margin": 40.5,
    "net_profit_margin": 25.2,
    "expense_ratio": 15.3
  },
  "performance": {
    "title": "Performa Positif",
    "description": "Laba bersih periode ini meningkat 12.5% dibandingkan periode sebelumnya. Pengeluaran operasional terkendali.",
    "is_positive": true
  },
  "revenues": { "total": 50000000, "details": [...] },
  "expenses": { "total": 30000000, "details": [...] },
  "net_income": 20000000
}
```

### F. Balance Sheet (Neraca Saldo)

Posisi Harta, Hutang, dan Modal per tanggal tertentu.

- **URL**: `GET /v2/report/balance-sheet?end_date=2025-12-31&id_toko=...`
- **Response**:

```json
{
  "assets": { "total": 150000000, "details": [...] },
  "liabilities": { "total": 20000000, "details": [...] },
  "equity": { "total": 100000000, "details": [...] }, // Historical Equity
  "current_earnings": 30000000, // Laba Berjalan (Belum Closing)
  "total_equity": 130000000,
  "check_balance": 0 // 0 means Balanced (Assets = Liab + Equity)
}
```

---

## 8. CLOSING (Tutup Buku Bulanan)

Proses memindahkan Laba/Rugi periode ini ke Modal (Retained Earnings).

- **Preview**: `GET /v2/closing/preview`
- **Process**: `POST /v2/closing/process`

---

## APPENDIX: ACCOUNT CODES (Daftar Akun)

Daftar akun standar yang digunakan dalam sistem (bisa bertambah sesuai input user).

| Code            | Name                             | Type      | Normal Balance |
| :-------------- | :------------------------------- | :-------- | :------------- |
| **ASSETS**      |                                  |           |                |
| 1001            | Cash                             | ASSET     | Debit          |
| 1002            | Bank                             | ASSET     | Debit          |
| 1003            | Accounts Receivable (Piutang)    | ASSET     | Debit          |
| 1004            | Inventory (Persediaan)           | ASSET     | Debit          |
| **LIABILITIES** |                                  |           |                |
| 2001            | Accounts Payable (Hutang)        | LIABILITY | Credit         |
| 2002            | Unearned Revenue                 | LIABILITY | Credit         |
| **EQUITY**      |                                  |           |                |
| 3001            | Owner Equity / Retained Earnings | EQUITY    | Credit         |
| **REVENUE**     |                                  |           |                |
| 4001            | Sales Revenue                    | REVENUE   | Credit         |
| 4002            | Sales Discount                   | REVENUE   | Debit (Contra) |
| 4003            | Sales Returns                    | REVENUE   | Debit (Contra) |
| **EXPENSES**    |                                  |           |                |
| 5001            | Cost of Goods Sold (HPP)         | EXPENSE   | Debit          |
| 5002            | Salaries Expense                 | EXPENSE   | Debit          |
| 5003            | Rent Expense                     | EXPENSE   | Debit          |
| 5004            | Electricity Expense              | EXPENSE   | Debit          |
| 5005            | Marketing & Admin Expense        | EXPENSE   | Debit          |
| 5006            | Operational Expense              | EXPENSE   | Debit          |

---

## 9. CUSTOMER V2 (Customer Portal & Authentication)

### A. Customer Registration

Register customer baru dengan email dan password.

- **URL**: `POST /v2/customer/register`
- **Body**:

```json
{
  "nama_customer": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "no_hp_customer": "08123456789",
  "alamat": "Jl. Example No. 123",
  "provinsi": "DKI Jakarta",
  "kota_kabupaten": "Jakarta Selatan",
  "kode_pos": "12345"
}
```

**Response**:

```json
{
  "status": 201,
  "message": "Registration successful. Please check your email to verify your account.",
  "data": {
    "customer_id": 1,
    "email_sent": true
  }
}
```

**Email yang Dikirim:**
Customer akan menerima email berisi:

- ✉️ **Informasi Login**: Email dan password mereka
- 🔗 **Link Verifikasi**: Untuk mengaktifkan akun
- 📝 **Instruksi**: Cara menggunakan akun

**Note**: Password dikirim dalam plain text di email untuk kemudahan customer. Disarankan customer mengganti password setelah login pertama kali.

### B. Email Verification (Via Email Link)

Customer klik link verifikasi dari email → Diarahkan ke halaman thank you.

- **URL**: `GET /customer/verify?token={verification_token}`
- **Method**: GET (dari link di email)
- **Response**: HTML Page (bukan JSON)

**Success Page**:

- ✅ Menampilkan pesan sukses
- ✅ Menampilkan nama customer
- ✅ Informasi next steps
- ✅ Link kembali ke homepage

**Error Page**:

- ❌ Token invalid/kadaluarsa
- ❌ Instruksi troubleshooting
- ❌ Link contact support

### C. Email Verification (API - Optional)

Verifikasi email via API (jika diperlukan untuk mobile app).

- **URL**: `POST /v2/customer/verify-email`
- **Body**:

```json
{
  "token": "verification_token_from_email"
}
```

### D. Customer Login

Login customer dan mendapatkan JWT token.

- **URL**: `POST /v2/customer/login`
- **Body**:

```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

**Response**:

```json
{
  "status": 200,
  "message": "Login successful",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "customer": {
      "id": 1,
      "nama_customer": "John Doe",
      "email": "john@example.com",
      "no_hp_customer": "08123456789",
      "discount_type": "PERCENTAGE",
      "discount_value": 10
    }
  }
}
```

### D. Get Product List

Mendapatkan daftar produk dengan diskon customer (jika login).

**Requires**: Optional Customer JWT Token

- **URL**: `GET /api/v2/customer/products`
- **Method**: `GET`
- **Headers**: `Authorization: Bearer {customer_token}` (Optional)
- **Query Params**:
  - `limit`: Limit data per page (default: 20)
  - `page`: Halaman ke berapa (default: 1)
  - `id_toko`: (Optional) Filter by toko ID
  - `search`: (Optional) Search by Nama Barang / Nama Model / Seri / Kode Barang

**Response (With id_toko)**:

```json
{
  "status": 200,
  "data": [
    {
      "id": 1,
      "id_barang": "IPH001",
      "nama_barang": "iPhone 15 Pro Max",
      "nama_lengkap_barang": "iPhone 15 Pro Max 256GB Black",
      "harga_jual": 20000000,
      "customer_price": 18000000,
      "discount_applied": 2000000,
      "stock": 5
    }
  ]
}
```

**Response (Without id_toko)**:

```json
{
  "status": 200,
  "data": [
    {
      "id": 1,
      "id_barang": "IPH001",
      "nama_barang": "iPhone 15 Pro Max",
      "nama_lengkap_barang": "iPhone 15 Pro Max 256GB Black",
      "harga_jual": 20000000,
      "customer_price": 18000000,
      "discount_applied": 2000000,
      "stock_total": 15,
      "stock_details": [
        {
          "id_toko": "1",
          "toko_name": "Toko Pusat",
          "stock": 10
        },
        {
          "id_toko": "2",
          "toko_name": "Cabang Jakarta",
          "stock": 5
        }
      ]
    }
  ]
}
```

**Note**:

- Jika `Authorization` header dikirim, harga akan disesuaikan dengan diskon customer.
- Jika `id_toko` dikirim, response akan berisi field `stock` (integer).
- Jika `id_toko` TIDAK dikirim, response akan berisi field `stock_total` dan `stock_details` (per toko).
- `search` akan mencari di kode barang, dan kombinasi nama + model + seri.
- `harga_jual` adalah harga normal.
- `customer_price` adalah harga setelah diskon (jika login & eligible).
- `discount_applied` adalah nominal diskon yang didapat.

### E. Get Product Detail

Mendapatkan detail satu produk dengan informasi lengkap (termasuk stok per toko & gambar).

**Requires**: Optional Customer JWT Token

- **URL**: `GET /api/v2/customer/products/{id}`
- **Method**: `GET`
- **Headers**: `Authorization: Bearer {customer_token}` (Optional)

**Response**:

```json
{
  "status": 200,
  "message": "Data berhasil diambil",
  "data": {
    "id": 1,
    "id_barang": "IPH001",
    "nama_barang": "iPhone 15 Pro Max",
    "nama_lengkap_barang": "iPhone 15 Pro Max 256GB Black",
    "nama_model": "iPhone 15 Pro Max",
    "seri": "256GB Black",
    "deskripsi": "Barang original garansi resmi...",
    "harga_jual": 20000000,
    "customer_price": 18000000,
    "discount_applied": 2000000,
    "stock_total": 15,
    "stock_details": [
      {
        "id_toko": "1",
        "toko_name": "Toko Pusat",
        "stock": 10
      },
      {
        "id_toko": "2",
        "toko_name": "Cabang Jakarta",
        "stock": 5
      }
    ],
    "images": [
      "https://example.com/image1.jpg",
      "https://example.com/image2.jpg"
    ]
  }
}
```

### F. Get Customer Profile

Mendapatkan data profile customer yang sedang login.

**Requires**: Customer JWT Token

- **URL**: `GET /api/v2/customer/profile`
- **Headers**: `Authorization: Bearer {customer_token}`

**Response**:

```json
{
  "status": 200,
  "data": {
    "id": 1,
    "nama_customer": "John Doe",
    "email": "john@example.com",
    "no_hp_customer": "08123456789",
    "alamat": "Jl. Example No. 123",
    "provinsi": "DKI Jakarta",
    "kota_kabupaten": "Jakarta Selatan",
    "kode_pos": "12345",
    "discount_type": "PERCENTAGE",
    "discount_value": 10
  }
}
```

### G. Update Customer Profile

Update profile customer (nama, alamat, password, dll).

**Requires**: Customer JWT Token

- **URL**: `PUT /api/v2/customer/profile`
- **Headers**: `Authorization: Bearer {customer_token}`
- **Body**:

```json
{
  "nama_customer": "John Doe Updated",
  "no_hp_customer": "08123456789",
  "alamat": "Jl. New Address No. 456",
  "provinsi": "Jawa Barat",
  "kota_kabupaten": "Bandung",
  "kode_pos": "40123"
}
```

**Update Password**:

```json
{
  "current_password": "oldpassword123",
  "password": "newpassword123"
}
```

**Update Email** (requires re-verification):

```json
{
  "email": "newemail@example.com"
}
```

**Note**:

- Semua field optional (partial update)
- Untuk ganti password, wajib kirim `current_password`
- Ganti email akan reset `email_verified_at` dan perlu verifikasi ulang
- Response: `"Profile updated successfully"` atau `"Profile updated successfully. Please verify your new email address."`

---

## 10. VOUCHER MANAGEMENT

### A. Create Voucher (Admin)

Membuat voucher/promo code baru.

- **URL**: `POST /v2/voucher`
- **Body**:

```json
{
  "code": "NEWYEAR2026",
  "description": "New Year Promo 2026",
  "discount_type": "PERCENTAGE",
  "discount_value": 15,
  "min_purchase": 500000,
  "max_discount": 100000,
  "usage_limit": 100,
  "valid_from": "2026-01-01 00:00:00",
  "valid_until": "2026-01-31 23:59:59",
  "is_active": 1
}
```

**Field Explanation**:

- `code`: Kode voucher (unique, akan di-uppercase otomatis)
- `discount_type`: `FIXED` atau `PERCENTAGE`
- `discount_value`: Nilai diskon (nominal atau persentase)
- `min_purchase`: Minimum pembelian untuk menggunakan voucher (optional)
- `max_discount`: Maximum diskon untuk tipe PERCENTAGE (optional)
- `usage_limit`: Batas total penggunaan voucher (null = unlimited)
- `valid_from`: Tanggal mulai berlaku (optional)
- `valid_until`: Tanggal kadaluarsa (optional)

### B. List Vouchers (Admin)

- **URL**: `GET /v2/voucher?is_active={1|0}&page={page}&limit={limit}`

### C. Update Voucher (Admin)

- **URL**: `PUT /v2/voucher/{id}`
- **Body**: Same as create (partial update supported)

### D. Delete Voucher (Admin)

- **URL**: `DELETE /v2/voucher/{id}`

### E. Validate Voucher (Public/Customer)

Validasi voucher sebelum digunakan di transaksi.

- **URL**: `POST /v2/customer/voucher/validate`
- **Body**:

```json
{
  "code": "NEWYEAR2026",
  "purchase_amount": 1000000
}
```

**Response**:

```json
{
  "status": 200,
  "message": "Voucher is valid",
  "data": {
    "voucher": {
      "id": 1,
      "code": "NEWYEAR2026",
      "discount_type": "PERCENTAGE",
      "discount_value": 15,
      "min_purchase": 500000,
      "max_discount": 100000
    },
    "discount_amount": 100000,
    "final_amount": 900000
  }
}
```

**Validation Checks**:

- ✅ Voucher exists and active
- ✅ Valid period (valid_from - valid_until)
- ✅ Usage limit not exceeded
- ✅ Minimum purchase requirement met

### F. Apply Voucher (Customer - Protected)

Increment usage count setelah voucher digunakan di transaksi.

**Requires**: Customer JWT Token

- **URL**: `POST /v2/customer/voucher/{id}/apply`
- **Headers**: `Authorization: Bearer {customer_token}`

**Note**: Endpoint ini dipanggil setelah transaksi berhasil dibuat untuk increment `usage_count`.

---

## Customer Discount vs Voucher

| Feature        | Customer Discount                  | Voucher                                      |
| -------------- | ---------------------------------- | -------------------------------------------- |
| **Setup**      | Set di customer profile            | Dibuat admin sebagai promo code              |
| **Scope**      | Berlaku untuk semua produk         | Berlaku per transaksi                        |
| **Type**       | FIXED atau PERCENTAGE              | FIXED atau PERCENTAGE                        |
| **Limit**      | Tidak ada limit                    | Ada usage_limit dan valid_until              |
| **Validation** | Otomatis apply saat login          | Perlu input code manual                      |
| **Kombinasi**  | Bisa dikombinasikan dengan voucher | Bisa dikombinasikan dengan customer discount |

**Example Flow**:

1. Customer login → dapat discount 10% (customer discount)
2. Customer lihat produk → harga sudah include discount 10%
3. Customer checkout → input voucher code "NEWYEAR2026" → dapat tambahan diskon
4. Total discount = Customer Discount + Voucher Discount

---

## 11. WILAYAH INDONESIA API

API untuk mendapatkan data provinsi dan kota/kabupaten di Indonesia.

### A. Get All Provinces

Mendapatkan daftar semua provinsi di Indonesia.

- **URL**: `GET /api/wilayah/provinces`
- **Auth**: No Auth Required

**Response**:

```json
{
  "status": 200,
  "data": [
    {
      "id": "31",
      "name": "DKI Jakarta"
    },
    {
      "id": "32",
      "name": "Jawa Barat"
    }
  ],
  "total_data": 34
}
```

### B. Get Cities by Province

Mendapatkan daftar kota/kabupaten berdasarkan ID provinsi.

- **URL**: `GET /api/wilayah/cities/{province_id}`
- **Auth**: No Auth Required

**Example**: `GET /api/wilayah/cities/31` (DKI Jakarta)

**Response**:

```json
{
  "status": 200,
  "data": [
    {
      "id": "3171",
      "name": "Jakarta Selatan"
    },
    {
      "id": "3172",
      "name": "Jakarta Timur"
    }
  ],
  "total_data": 6
}
```

### C. Search Cities

Mencari kota/kabupaten berdasarkan nama.

- **URL**: `GET /api/wilayah/cities/search?q={search_term}&province_id={province_id}`
- **Auth**: No Auth Required

**Note**: Semua endpoint wilayah adalah public (tidak perlu authentication).

---

---

## 12. PRODUCT PRICELIST V2 (WITH CUSTOMER DISCOUNT)

Endpoint untuk mendapatkan daftar produk dengan harga yang sudah include discount customer.

### Get Product Pricelist with Discount

Mendapatkan daftar produk dengan harga yang sudah dikurangi discount customer (jika ada).

**Requires**: Customer JWT Token

- **URL**: `POST /api/v2/customer/pricelist`
- **Headers**: `Authorization: Bearer {customer_token}`
- **Body** (optional query params):

```json
{
  "namaProduct": "iPhone",
  "id_toko": "1",
  "seri": "2",
  "model": "3",
  "sortBy": "product.nama_barang",
  "sortMethod": "asc",
  "page": 1,
  "limit": 10
}
```

**Response**:

```json
{
  "status": 200,
  "data": [
    {
      "id": 1,
      "kode_barang": "IPH001",
      "nama_barang": "iPhone 15 Pro Max",
      "nama_lengkap_barang": "iPhone 15 Pro Max 256GB Black",
      "nama_model": "256GB",
      "seri": "Black",
      "harga_jual": 18000000,
      "stock": [
        {
          "stock": 5,
          "barang_cacat": 0,
          "toko_name": "Toko Pusat",
          "dropship": false
        }
      ],
      "images": ["https://example.com/image1.jpg"]
    }
  ],
  "total_data": 100,
  "total_page": 10,
  "current_page": 1,
  "limit": 10
}
```

**Note**:

- Endpoint ini **WAJIB** menggunakan customer JWT token
- Harga yang ditampilkan (`harga_jual`) sudah otomatis dikurangi discount customer
- Jika customer punya `discount_type = PERCENTAGE` dan `discount_value = 10`, maka harga akan dikurangi 10%
- Jika customer punya `discount_type = FIXED` dan `discount_value = 100000`, maka harga akan dikurangi Rp 100.000
- Jika customer tidak punya discount, harga akan tampil normal (harga jual original)
- Response format sama persis dengan endpoint `/api/pricelist` yang lama
- Tidak ada field `harga_jual_toko` atau `harga_special` - hanya fokus pada `harga_jual` yang sudah include discount

**Example Calculation**:

- Harga Original: Rp 20.000.000
- Customer Discount: PERCENTAGE 10%
- Harga Final: Rp 18.000.000 (20.000.000 - 2.000.000)

---

## 13. UTILITIES

### A. Upload Image (Auto WebP)

Upload gambar (Product, Bukti Bayar, dll) dengan konversi otomatis ke format WebP dan manajemen folder.

- **URL**: `POST /v2/upload/image`
- **Header**: `Content-Type: multipart/form-data`
- **Body**:
  - `file`: (File) Image file (JPG, PNG, AVIF, WebP)
  - `folder`: (String) Subfolder name (e.g., `products`, `transactions/payment`, `toko/logo`). Default: `general`

**Response**:

```json
{
  "status": 200,
  "message": "Image uploaded and converted to WebP successfully",
  "data": {
    "path": "uploads/products/a1b2c3d4e5f6.webp",
    "url": "http://domain.com/uploads/products/a1b2c3d4e5f6.webp"
  }
}
```

---

## 14. DASHBOARD & ANALYTICS

### A. Branch Performance Summary

Mendapatkan performa setiap toko (Cabang) berdasarkan total terjual, revenue, profit, dan rincian per kategori status transaksi.

- **URL**: `GET /api/dashboard/branch-performance`
- **Method**: `GET`
- **Query Params**:
  - `start_date` (optional): Format `YYYY-MM-DD`
  - `end_date` (optional): Format `YYYY-MM-DD`

**Response**:

```json
{
  "status": 200,
  "data": [
    {
      "id_toko": "1",
      "toko_name": "Toko Pusat",
      "total_terjual_barang": 150,
      "total_revenue": 15000000,
      "total_profit": 3000000,
      "transaction_status_count": {
        "SUCCESS": 45,
        "PAID": 80,
        "PACKING": 12,
        "IN_DELIVERY": 13
      }
    }
  ]
}
```

---

## 15. PRODUCT STOCK OPTIMIZATION

Beberapa perubahan pada endpoint `GET /api/product` untuk reporting stok yang lebih akurat.

### A. New Stock Fields (Response)

Response produk sekarang memisahkan antara stok fisik yang tersedia dan stok yang sedang tertahan (Hold) di transaksi pending.

- **`total_stock_ready`**: Stok fisik yang siap dijual saat ini.
- **`total_hold`**: Stok yang sudah di-checkout customer tapi belum bayar (`WAITING_PAYMENT`).
- **`total_stock`**: Total stok fisik keseluruhan (`ready + hold`).
- **`total_coming_soon`**: Stok dalam perjalanan (Purchase yang sudah `APPROVED` tapi belum `EXECUTE`).

**Response Structure (Per Store Stock)**:

```json
"stock": [
  {
    "stock_ready": 8, // Stok siap jual
    "stock": 10,      // Total stok (ready + hold)
    "hold_stock": 2,  // Stok tertahan
    "stock_coming_soon": 5, // Stok akan datang
    "toko_name": "Toko Pusat",
    "id_toko": 1
  }
]
```

### B. Stock Filter

Filter stok di endpoint `/api/product` kini bekerja berdasarkan `total_stock_ready` (Stok yang benar-benar siap dijual).

- `stock_filter=available`: Menampilkan produk dengan `ready_stock > 6`.
- `stock_filter=low_stock`: Menampilkan produk dengan `ready_stock 1 - 5`.
- `stock_filter=out_stock`: Menampilkan produk dengan `ready_stock = 0`.

---

## 16. ACTIVITY LOGS

### A. Multi-Table Filtering

Filter `target_table` di endpoint reporting log sekarang mendukung multiple values.

- **URL**: `GET /api/reporting/log?target_table=product,transaction,toko`
- **Behavior**: Akan menampilkan log yang berkaitan dengan tabel `product`, `transaction`, ATAU `toko`. Pisahkan dengan koma tanpa spasi.

---

## 17. CUSTOMER SHOPPING (CART & CHECKOUT)

Endpoint khusus untuk aplikasi/portal customer untuk mengelola keranjang belanja dan membuat pesanan.

### A. GET Cart

Mendapatkan daftar item di keranjang belanja customer.

- **URL**: `GET /api/v2/customer/cart`
- **Auth**: Customer JWT Token Required

### B. SAVE Cart Item (Add to Cart)

Menambah item ke keranjang. Jika `id_barang` DAN `id_toko` yang sama sudah ada, maka jumlahnya akan **ditambah** (inkremental).

- **URL**: `POST /api/v2/customer/cart`
- **Headers**: `Authorization: Bearer {customer_token}`
- **Body**:

```json
{
  "id_barang": "PROD-001",
  "jumlah": 2, // Akan ditambahkan ke jumlah yang sudah ada jika item sama
  "id_toko": 1 // Optional
}
```

### C. UPDATE Cart Item

Mengubah jumlah item di keranjang secara spesifik (Absolute). Digunakan untuk input manual atau tombol +/- di halaman keranjang.

- **URL**: `PUT /api/v2/customer/cart/{id}`
- **Headers**: `Authorization: Bearer {customer_token}`
- **Body**:

```json
{
  "jumlah": 5 // Set jumlah menjadi 5
}
```

### D. DELETE Cart Item

Menghapus satu item dari keranjang berdasarkan ID baris cart.

- **URL**: `DELETE /api/v2/customer/cart/{id}`

### E. CLEAR Cart

Menghapus **seluruh** isi keranjang belanja customer.

- **URL**: `DELETE /api/v2/customer/cart`

### C. Checkout (Create Order)

Mengubah isi keranjang menjadi Invoice. Jika item berasal dari toko yang berbeda, sistem akan otomatis memecahnya menjadi beberapa invoice (1 invoice per toko).

- **URL**: `POST /api/v2/customer/checkout`
- **Auth**: Customer JWT Token Required
- **Logic**:
  - Validasi stok di masing-masing toko.
  - Otomatis mencatat Jurnal Akuntansi (Sales & COGS).
  - Mengosongkan item yang berhasil di-order dari keranjang belanja.
  - Harga barang otomatis menggunakan **Customer Price** (setelah diskon level customer).
- **Body**:

```json
{
  "cart_ids": [1, 5, 8], // ID dari tabel cart
  "alamat": "Jl. Kemenangan No. 8",
  "provinsi": "DKI Jakarta",
  "kota_kabupaten": "Jakarta Selatan",
  "kecamatan": "Tebet",
  "kelurahan": "Kebon Baru",
  "kode_pos": "12345",
  "stores": [
    {
      "id_toko": 1,
      "pengiriman": "JNE REG",
      "biaya_pengiriman": 15000,
      "discount_type": "PERCENTAGE",
      "discount_amount": 10
    },
    {
      "id_toko": 2,
      "pengiriman": "J&T Express",
      "biaya_pengiriman": 12000,
      "discount_type": "FIXED",
      "discount_amount": 5000
    }
  ]
}
```

- **Response (Success)**:

```json
{
  "status": 201,
  "message": "Checkout successful",
  "data": [
    {
      "id": 123,
      "invoice": "INV240221123",
      "store": 1,
      "total": 515000
    },
    {
      "id": 124,
      "invoice": "INV240221124",
      "store": 2,
      "total": 300000
    }
  ]
}
```

### D. Upload Payment Proof

Mengirim bukti transfer pembayaran dari sisi customer.

- **URL**: `POST /api/v2/customer/payment/upload`
- **Auth**: Customer JWT Token Required
- **Behavior**:
  - Status transaksi berubah menjadi **`WAITING_VERIFICATION`**.
  - Bukti gambar disimpan di tabel pembayaran dengan status `PENDING`.
- **Body**:

```json
{
  "transaction_id": 123,
  "image_url": "https://...",
  "note": "Sudah lunas ya kak"
}
```

### E. GET Transactions (History)

Mendapatkan daftar riwayat transaksi (invoice) milik customer yang login.

- **URL**: `GET /api/v2/customer/transactions`
- **Auth**: Customer JWT Token Required
- **Query Parameters**:
  - `page` (optional): Nomor halaman (default: 1)
  - `limit` (optional): Jumlah data per halaman (default: 10)
  - `status` (optional): Filter status, bisa lebih dari satu dipisahkan koma (contoh: `status=WAITING_PAYMENT,SUCCESS`)
- **Response**:

```json
{
  "status": 200,
  "message": "Transactions fetched",
  "data": [
    {
      "id": 1,
      "invoice": "INV2402210001",
      "amount": 500000,
      "actual_total": 515000,
      "status": "WAITING_PAYMENT",
      "status_label": "Waiting for Payment",
      "date_time": "2026-02-21 16:00:00",
      "summary_item": "Nama Barang Pertama",
      "item_count": 3
    }
  ],
  "pagination": {
    "total_data": 25,
    "total_page": 3,
    "current_page": 1,
    "per_page": 10
  }
}
```
