# Documentation API V2 (Accounting & Inventory)

Dokumentasi untuk sistem akuntansi V2 yang mencakup Transaksi Penjualan, Pembelian (Purchase), Pengeluaran (Expense), dan Laporan Keuangan (Reporting).

**Base URL**: `/api/v2`  
**Authentication**: Bearer Token (JWT) di Header `Authorization`

---

## 1. TRANSACTION (Penjualan / Invoice)

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

---

## 2. PAYMENTS & REFUNDS

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
```

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

## 3. INVENTORY (Stok)

### A. Purchase (Pembelian Barang / Re-stock)

Mencatat pembelian ke supplier. Stock bertambah. Update Harga Modal (Average).

1. `POST /v2/purchase` (Draft)
2. `POST /v2/purchase/{id}/execute` (Finalize & Jurnal)

### B. Stock Transfer (Antar Toko)

Memindahkan stok fisik dan nilai modal antar toko.

- **URL**: `POST /v2/inventory/transfer`
- **Body**:

```json
{
  "source_toko_id": 1,
  "target_toko_id": 2,
  "items": [{ "kode_barang": "A001", "qty": 10 }]
}
```

---

## 4. EXPENSE (Pengeluaran Operasional)

Mencatat biaya-biaya (Listrik, Sewa, Gaji, dll).

- **URL**: `POST /v2/expense`
- **Body**:

```json
{
  "id_toko": 1,
  "account_code": "703", // Beban Listrik
  "amount": 500000,
  "payment_method": "CASH",
  "date": "2025-05-15"
}
```

---

## 5. FINANCE (Keuangan & Cashflow)

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

## 6. ACCOUNTING REPORTS (Laporan Keuangan)

Semua report mendukung filter `id_toko`. Jika kosong = Konsolidasi (Gabungan).

### A. Journal Report (Jurnal Umum)

Detail pergerakan debit/kredit setiap transaksi.

- **URL**: `GET /v2/report/journal?start_date=...&end_date=...&id_toko=...`

### B. General Ledger (Buku Besar)

Mutasi saldo per akun (Opening, Debit, Credit, Closing).

- **URL**: `GET /v2/report/ledger?start_date=...&end_date=...`

### C. Income Statement (Laporan Laba Rugi)

Ringkasan Pendapatan vs Pengeluaran dalam periode tertentu.

- **URL**: `GET /v2/report/income-statement?start_date=...&end_date=...&id_toko=...`
- **Response**:

```json
{
  "period": "2025-01-01 to 2025-01-31",
  "revenues": { "total": 50000000, "details": [...] },
  "expenses": { "total": 30000000, "details": [...] },
  "net_income": 20000000
}
```

### D. Balance Sheet (Neraca Saldo)

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

## 7. CLOSING (Tutup Buku Bulanan)

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
| 6001            | Salaries Expense                 | EXPENSE   | Debit          |
| 6002            | Rent Expense                     | EXPENSE   | Debit          |
| 6003            | Electricity Expense              | EXPENSE   | Debit          |
| 6004            | Marketing & Admin Expense        | EXPENSE   | Debit          |
| 6005            | Operational Expense              | EXPENSE   | Debit          |
