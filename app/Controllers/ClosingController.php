<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\{TransactionModel, CashflowModel, SalesProductModel, TransactionClosingModel, ClosingDetailModel};

class ClosingController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function closeMonthly()
    {
        $json = $this->request->getJSON();
        $year = $json->year ?? null;
        $month = $json->month ?? null;

        if (!$year || !$month) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Year dan month dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = date("$year-$month-01 00:00:00");
        $endDate = date("Y-m-t 23:59:59", strtotime($startDate));

        try {
            $transactions = $this->getTransactionsForClosing($startDate, $endDate);

            if (empty($transactions)) {
                return $this->response->setJSON([
                    'status' => 'info',
                    'message' => "Tidak ada transaksi yang perlu di-closing untuk periode $month/$year.",
                    'month' => "$month/$year"
                ]);
            }

            $results = $this->processBatchClosing($transactions, $startDate, $endDate);
            $summary = $this->generateClosingSummary($results);

            return $this->response->setJSON([
                'status' => 'success',
                'month' => "$month/$year",
                'summary' => $summary,
                'processed_count' => count($results),
                'results' => $results
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Close monthly failed: ' . $e->getMessage());
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal melakukan closing: ' . $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    public function autoCloseMonthly()
    {
        try {
            $now = new \DateTime();
            $now->modify('first day of last month');
            $year = $now->format('Y');
            $month = $now->format('m');

            $startDate = date("$year-$month-01 00:00:00");
            $endDate = date("Y-m-t 23:59:59", strtotime($startDate));
            $periodLabel = "$month/$year";

            if ($this->isPeriodAlreadyClosed($startDate, $endDate)) {
                return $this->response->setJSON([
                    'status' => 'info',
                    'message' => "Period $periodLabel sudah dilakukan closing sebelumnya.",
                    'month' => $periodLabel
                ]);
            }

            $transactions = $this->getTransactionsForClosing($startDate, $endDate);

            if (empty($transactions)) {
                return $this->response->setJSON([
                    'status' => 'info',
                    'message' => "Tidak ada transaksi yang perlu di-closing untuk period $periodLabel.",
                    'month' => $periodLabel
                ]);
            }

            $results = $this->processBatchClosing($transactions, $startDate, $endDate);
            $summary = $this->generateClosingSummary($results);

            return $this->response->setJSON([
                'status' => 'success',
                'message' => "Auto-closing berhasil untuk period $periodLabel",
                'month' => $periodLabel,
                'summary' => $summary,
                'processed_count' => count($results),
                'results' => $results
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Auto-closing failed: ' . $e->getMessage());
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal melakukan auto-closing: ' . $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    private function isPeriodAlreadyClosed($startDate, $endDate)
    {
        return $this->db->table('transaction_closing')
            ->where('period_start >=', $startDate)
            ->where('period_start <=', $endDate)
            ->countAllResults() > 0;
    }

    private function getTransactionsForClosing($startDate, $endDate)
    {
        // Solusi 1: Gunakan subquery untuk menghindari GROUP BY problem
        $subquery = $this->db->table('transaction t')
            ->select('t.id')
            ->groupStart()
            ->where('t.status !=', 'WAITING_PAYMENT')
            ->where('t.closing', 0)
            ->where('t.updated_at >=', $startDate)
            ->where('t.updated_at <=', $endDate)
            ->groupEnd()
            ->orWhere('t.closing', 2)
            ->groupBy('t.id');

        $transactionIds = array_column($subquery->get()->getResultArray(), 'id');

        if (empty($transactionIds)) {
            return [];
        }

        // Ambil data lengkap tanpa GROUP BY
        return $this->db->table('transaction t')
            ->select('t.*, tm_customer.value as customer_name')
            ->join('transaction_meta tm_customer', 't.id = tm_customer.transaction_id AND tm_customer.key = "customer_name"', 'left')
            ->whereIn('t.id', $transactionIds)
            ->orderBy('t.date_time', 'ASC')
            ->get()
            ->getResult();
    }

    private function processBatchClosing($transactions, $startDate, $endDate)
    {
        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($transactions as $trx) {
            try {
                $isAdjustment = ($trx->closing == 2);
                $result = $this->processClosing($trx, $startDate, $endDate, $isAdjustment);

                $results[] = [
                    'status' => 'success',
                    'invoice' => $trx->invoice,
                    'data' => $result
                ];
                $successCount++;

            } catch (\Exception $e) {
                log_message('error', "Failed to close transaction {$trx->invoice}: " . $e->getMessage());
                $results[] = [
                    'status' => 'error',
                    'invoice' => $trx->invoice,
                    'error' => $e->getMessage()
                ];
                $errorCount++;
            }
        }

        log_message('info', "Closing completed: $successCount success, $errorCount failed");
        return $results;
    }

    private function generateClosingSummary($results)
    {
        $summary = [
            'total_transactions' => count($results),
            'success_count' => 0,
            'error_count' => 0,
            'total_profit' => 0,
            'total_debit' => 0,
            'total_credit' => 0,
            'total_modal' => 0,
            'status_breakdown' => []
        ];

        foreach ($results as $result) {
            if ($result['status'] === 'success') {
                $summary['success_count']++;
                $data = $result['data'];

                $summary['total_profit'] += $data['profit'] ?? 0;
                $summary['total_debit'] += $data['total_debit'] ?? 0;
                $summary['total_credit'] += $data['total_credit'] ?? 0;
                $summary['total_modal'] += $data['total_modal'] ?? 0;

                $status = $data['transaction_status'] ?? 'UNKNOWN';
                $summary['status_breakdown'][$status] = ($summary['status_breakdown'][$status] ?? 0) + 1;
            } else {
                $summary['error_count']++;
            }
        }

        return $summary;
    }

    private function processClosing($trx, $start, $end, $isAdjustment = false)
    {
        $this->db->transBegin();

        try {
            $trxId = $trx->id;
            $invoice = $trx->invoice;
            $status = strtoupper($trx->status);
            $closingStatus = $trx->closing;
            $tanggal = date('Y-m-d', strtotime($trx->date_time));
            $idToko = $trx->id_toko ?? null;

            // Get required data
            $cashflows = $this->getCashflowsForClosing($invoice);
            $sales = $this->getSalesProductsForClosing($trxId);
            $previousClosing = $this->getPreviousClosing($trxId);

            // Enhanced logging
            log_message('info', "=== PROCESSING CLOSING ===");
            log_message('info', "Invoice: {$invoice}, Status: {$status}, Closing: {$closingStatus}, Is Adjustment: " . ($isAdjustment ? 'YES' : 'NO'));
            log_message('info', "Cashflows found: " . count($cashflows));
            log_message('info', "Sales items: " . count($sales));
            log_message('info', "Previous closing: " . ($previousClosing ? 'EXISTS' : 'NONE'));

            if ($previousClosing) {
                log_message('info', "Previous closing details - Modal: {$previousClosing->total_modal}, Status: {$previousClosing->transaction_status}");
            }

            // Calculate closing details
            $detail = $this->calculateClosingDetails(
                $cashflows,
                $sales,
                $previousClosing,
                $status,
                $closingStatus,
                $isAdjustment,
                $trxId
            );

            // Calculate totals
            $totalDebit = array_sum(array_column($detail, 'debit'));
            $totalCredit = array_sum(array_column($detail, 'credit'));
            $profit = $totalDebit - $totalCredit;
            $totalModalBaru = $this->calculateNewModal($detail);

            log_message('info', "Closing totals - Debit: {$totalDebit}, Credit: {$totalCredit}, Profit: {$profit}, Modal: {$totalModalBaru}");

            // Save data
            $closingId = $this->saveClosingData(
                $trxId,
                $idToko,
                $start,
                $end,
                $closingStatus,
                $status,
                count($cashflows),
                $totalDebit,
                $totalCredit,
                $profit,
                $totalModalBaru
            );

            // Gunakan tanggal default dari transaksi, tapi detail akan menggunakan tanggal cashflow
            $this->saveClosingDetails($closingId, $detail, $tanggal);
            $this->updateClosingStatuses($trxId, $cashflows);
            $this->saveSupplierClosing($trxId, $start, $end, $isAdjustment);

            $this->db->transCommit();

            log_message('info', "=== CLOSING COMPLETED ===");

            return [
                'invoice' => $invoice,
                'tanggal' => $tanggal,
                'profit' => $profit,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'total_modal' => $totalModalBaru,
                'transaction_status' => $status,
                'cashflow_count' => count($cashflows),
                'detail' => $this->customSortDetail($detail)
            ];

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw new \Exception("Failed to close transaction {$trx->invoice}: " . $e->getMessage());
        }
    }

    private function saveSupplierClosing($trxId, $startDate, $endDate, $isAdjustment = false)
    {
        $salesProducts = $this->db->table('sales_product')
            ->where('id_transaction', $trxId)
            ->get()
            ->getResult();

        if (empty($salesProducts)) {
            log_message('info', "Tidak ada sales_product untuk transaksi ID {$trxId}");
            return;
        }

        // Hitung periode closing (YYYY-MM)
        $closingMonth = date('Y-m', strtotime($endDate));
        $closingDate = date('Y-m-d H:i:s');

        foreach ($salesProducts as $sp) {
            // Cek apakah sudah pernah di-closing bulan ini
            $existing = $this->db->table('supplier_closing')
                ->where('transaction_id', $trxId)
                ->where('kode_barang', $sp->kode_barang)
                ->get()
                ->getRow();

            // Kalau adjustment, hitung selisih jumlah
            $jumlah = intval($sp->jumlah);
            if ($isAdjustment && $existing) {
                $selisih = $jumlah - intval($existing->jumlah);

                if ($selisih == 0)
                    continue; // Tidak ada perubahan

                // Kalau ada selisih, catat sebagai penyesuaian
                $jumlah = $selisih;
            }

            $data = [
                'transaction_id' => $trxId,
                'kode_barang' => $sp->kode_barang,
                'transaction_date' => $sp->created_at ?? date('Y-m-d H:i:s'),
                'jumlah' => $jumlah,
                'harga_jual' => $sp->harga_jual ?? 0,
                'total' => ($sp->harga_jual ?? 0) * $jumlah,
                'harga_modal' => $sp->modal_system ?? 0,
                'total_harga_modal' => ($sp->modal_system ?? 0) * $jumlah,
                'dropship_suplier' => $sp->dropship_suplier ?? null,
                'closing_month' => $closingMonth,
                'closing_date' => $closingDate,
                'closing_status' => 'active',
                'created_at' => $closingDate,
            ];

            // Insert ke supplier_closing
            $this->db->table('supplier_closing')->insert($data);

            // Update sales_product.closing = 1
            $this->db->table('sales_product')
                ->where('id', $sp->id)
                ->update(['closing' => 1]);
        }

        log_message('info', "Supplier closing disimpan untuk trx {$trxId}");
    }

    private function calculateClosingDetails($cashflows, $sales, $previousClosing, $status, $closingStatus, $isAdjustment, $trxId)
    {
        $detail = [];
        $urutan = 1;


        $modalLama = $previousClosing ? $previousClosing->total_modal : 0;
        $prevStatus = $previousClosing ? strtoupper($previousClosing->transaction_status) : null;

        log_message('info', "=== CALCULATE CLOSING DETAIL START ===");
        log_message('info', "Previous closing - Modal: {$modalLama}, Status: {$prevStatus}, Is Adjustment: " . ($isAdjustment ? 'YES' : 'NO'));
        log_message('info', "Current status: {$status}, Closing status: {$closingStatus}");

        // ========== CEK RETURN ITEMS ==========
        $hasReturnedItems = $this->db->table('sales_product')
            ->where('id_transaction', $trxId)
            ->where('closing', 2)
            ->countAllResults() > 0;

        log_message('info', "Has returned items: " . ($hasReturnedItems ? 'YES' : 'NO'));

        // ========== PROSES CASHFLOW ==========
        foreach ($cashflows as $cf) {
            $desc = strtoupper(trim($cf->noted));
            $debit = floatval($cf->debit);
            $credit = floatval($cf->credit);
            $cashflowDate = $cf->date_time;

            // Handle pembayaran (DP / Pelunasan / Bayar)
            if (
                $debit > 0 && (
                    strpos($desc, 'DP') !== false ||
                    strpos($desc, 'PEMBAYARAN') !== false ||
                    strpos($desc, 'PELUNASAN') !== false ||
                    strpos($desc, 'BAYAR') !== false ||
                    strpos($desc, 'PAYMENT') !== false
                )
            ) {
                if (strpos($desc, 'DP') !== false) {
                    $tipe = 'DP';
                    $keterangan = 'DP';
                } elseif (strpos($desc, 'PELUNASAN') !== false) {
                    $tipe = 'PELUNASAN';
                    $keterangan = 'Pelunasan';
                } else {
                    $tipe = 'PEMBAYARAN';
                    $keterangan = 'Pembayaran';
                }

                $detail[] = $this->createDetailItem($keterangan, $debit, 0, $urutan++, $tipe, $cf->id, $cashflowDate);
            }

            // Handle refund
            elseif (strpos($desc, 'REFUND') !== false && $credit > 0) {
                $detail[] = $this->createDetailItem('Refund', 0, $credit, $urutan++, 'REFUND', $cf->id, $cashflowDate);
            }

            // Handle ongkos kirim
            elseif (
                $credit > 0 && (
                    strpos($desc, 'ONGKIR') !== false ||
                    strpos($desc, 'ONGKOS KIRIM') !== false ||
                    strpos($desc, 'BIAYA PENGIRIMAN') !== false ||
                    strpos($desc, 'SHIPPING') !== false
                )
            ) {
                $detail[] = $this->createDetailItem('Ongkos Kirim', 0, $credit, $urutan++, 'ONGKOS_KIRIM', $cf->id, $cashflowDate);
            }
        }

        // ========== HITUNG MODAL SEKARANG ==========
        $modalSekarang = array_reduce($sales, function ($carry, $s) {
            return $carry + (floatval($s->modal_system) * intval($s->jumlah));
        }, 0);

        log_message('info', "Modal calculation - Lama: {$modalLama}, Sekarang: {$modalSekarang}");
        $selisihModal = $modalSekarang - $modalLama;
        log_message('info', "Selisih Modal: {$selisihModal}");

        $hasModalRefund = false;

        // ========== LOGIC UNTUK ADJUSTMENT ==========
        if ($isAdjustment && $previousClosing) {
            log_message('info', "=== HANDLING ADJUSTMENT ===");

            if ($status === 'REFUNDED') {
                log_message('info', "Adjustment + Refund detected");


                if ($modalSekarang < $modalLama) {
                    // Modal turun → pengembalian selisih
                    $detail[] = $this->createDetailItem('Pengembalian Modal (Selisih)', abs($selisihModal), 0, $urutan++, 'MODAL_REFUND', null, date('Y-m-d'));
                    $hasModalRefund = true;
                    log_message('info', "Returned modal difference: " . abs($selisihModal));
                } elseif ($modalSekarang > $modalLama) {
                    // Modal naik → penambahan modal
                    $detail[] = $this->createDetailItem('Penambahan Modal (Selisih)', 0, $selisihModal, $urutan++, 'MODAL', null, date('Y-m-d'));
                    log_message('info', "Added modal difference: {$selisihModal}");
                } else {
                    log_message('info', "No modal change during refund adjustment");
                }
            } else {
                // Adjustment biasa (tanpa refund)
                if ($selisihModal > 0) {
                    $detail[] = $this->createDetailItem('Penambahan Modal', 0, $selisihModal, $urutan++, 'MODAL', null, date('Y-m-d'));
                    log_message('info', "Added modal increase: {$selisihModal}");
                } elseif ($selisihModal < 0) {
                    $detail[] = $this->createDetailItem('Pengembalian Modal (Selisih)', abs($selisihModal), 0, $urutan++, 'MODAL_REFUND', null, date('Y-m-d'));
                    $hasModalRefund = true;
                    log_message('info', "Added modal refund: " . abs($selisihModal));
                }
            }

        } else {
            // ========== LOGIC UNTUK TRANSAKSI BARU ==========
            $hasIncomingPayment = false;
            $totalProductPayments = 0;

            foreach ($cashflows as $cf) {
                $desc = strtoupper(trim($cf->noted));
                $debit = floatval($cf->debit);

                if (
                    $debit > 0 && (
                        strpos($desc, 'DP') !== false ||
                        strpos($desc, 'PEMBAYARAN') !== false ||
                        strpos($desc, 'PELUNASAN') !== false ||
                        strpos($desc, 'BAYAR') !== false
                    ) && strpos($desc, 'ONGKIR') === false
                ) {
                    $hasIncomingPayment = true;
                    $totalProductPayments += $debit;
                }
            }

            $shouldCountModal = !$hasReturnedItems && $closingStatus == 0 && $hasIncomingPayment;

            if ($shouldCountModal && $modalSekarang > 0) {
                $detail[] = $this->createDetailItem('Modal', 0, $modalSekarang, $urutan++, 'MODAL', null, date('Y-m-d'));
                log_message('info', "Added normal modal: {$modalSekarang}");
            }

            // REFUND untuk transaksi non-adjustment
            if ($status === 'REFUNDED' && $modalSekarang > 0) {
                $hasRefund = array_filter($detail, fn($d) => $d['tipe'] === 'REFUND');
                if (!empty($hasRefund) && !$hasModalRefund) {
                    $refundModal = $modalSekarang > 0 ? $modalSekarang : $modalLama;
                    $detail[] = $this->createDetailItem('Pengembalian Modal (Refund)', $refundModal, 0, $urutan++, 'MODAL_REFUND', null, date('Y-m-d'));
                    log_message('info', "Added refund modal for non-adjustment: {$refundModal}");
                }
            }
        }

        // ========== FINAL VALIDATION ==========
        $modalEntries = array_filter($detail, fn($d) => $d['tipe'] === 'MODAL');
        $modalRefundEntries = array_filter($detail, fn($d) => $d['tipe'] === 'MODAL_REFUND');

        log_message('info', "Final validation - Modal entries: " . count($modalEntries) . ", Modal refund entries: " . count($modalRefundEntries));
        log_message('info', "Final detail count: " . count($detail));
        log_message('info', "=== CALCULATE CLOSING DETAIL END ===");

        return $detail;
    }

    private function createDetailItem($keterangan, $debit, $credit, $urutan, $tipe, $cashflowId, $tanggal = null)
    {
        // Jika ada cashflowId, ambil tanggal dari cashflow, jika tidak gunakan tanggal hari ini
        $finalTanggal = $tanggal ? date('Y-m-d', strtotime($tanggal)) : date('Y-m-d');

        return [
            'keterangan' => $keterangan,
            'debit' => $debit,
            'credit' => $credit,
            'urutan' => $urutan,
            'tipe' => $tipe,
            'id_cashflow' => $cashflowId,
            'tanggal' => $finalTanggal // Simpan tanggal untuk digunakan nanti
        ];
    }

    private function customSortDetail(array $details): array
    {
        $order = [
            'DP' => 1,
            'PEMBAYARAN' => 2,
            'PELUNASAN' => 3,
            'MODAL' => 4,
            'MODAL_REFUND' => 5,
            'MODAL_CORRECTION' => 6,
            'ONGKOS_KIRIM' => 7,
            'BIAYA_LAIN' => 8,
            'REFUND' => 9
        ];

        usort($details, function ($a, $b) use ($order) {
            $aOrder = $order[$a['tipe']] ?? 999;
            $bOrder = $order[$b['tipe']] ?? 999;

            // Jika tipe sama, urutkan berdasarkan tanggal kemudian urutan
            if ($aOrder === $bOrder) {
                $dateCompare = strcmp($a['tanggal'] ?? '', $b['tanggal'] ?? '');
                return $dateCompare === 0 ? $a['urutan'] <=> $b['urutan'] : $dateCompare;
            }

            return $aOrder <=> $bOrder;
        });

        // Reset urutan setelah sorting
        foreach ($details as $i => &$d) {
            $d['urutan'] = $i + 1;
        }

        return $details;
    }

    public function listClosings()
    {
        $result = $this->db->table('transaction_closing')
            ->select("DATE_FORMAT(period_start, '%Y-%m') as period")
            ->groupBy("DATE_FORMAT(period_start, '%Y-%m')") // Group by expression yang sama
            ->orderBy("period", "desc")
            ->get()
            ->getResult();

        $periods = array_column($result, 'period');

        return $this->response->setJSON([
            'status' => 'success',
            'periods' => $periods
        ]);
    }

    public function getClosingDetailsByMonth()
    {
        $json = $this->request->getJSON();
        $year = $json->year ?? null;
        $month = $json->month ?? null;

        if (!$year || !$month) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Year dan month dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = "$year-$month-01 00:00:00";
        $endDate = date("Y-m-t 23:59:59", strtotime($startDate));

        try {
            $closings = $this->db->table('transaction_closing tc')
                ->select('tc.*, t.toko_name, tr.invoice, tr.date_time')
                ->join('toko t', 'tc.id_toko = t.id', 'left')
                ->join('transaction tr', 'tc.transaction_id = tr.id', 'left')
                ->where('tc.period_start >=', $startDate)
                ->where('tc.period_start <=', $endDate)
                ->orderBy('t.toko_name', 'asc')
                ->orderBy('tr.date_time', 'asc')
                ->get()
                ->getResult();

            if (empty($closings)) {
                return $this->response->setJSON([
                    'status' => 'empty',
                    'message' => 'Tidak ada data closing untuk bulan tersebut.'
                ]);
            }

            $closingIds = array_column($closings, 'id');
            $details = $this->db->table('closing_detail')
                ->whereIn('transaction_closing_id', $closingIds)
                ->orderBy('transaction_closing_id', 'asc')
                ->orderBy('urutan', 'asc')
                ->get()
                ->getResult();

            $detailsIndex = [];
            foreach ($details as $detail) {
                $detailsIndex[$detail->transaction_closing_id][] = $detail;
            }

            $response = [
                'status' => 'success',
                'month' => "$month/$year",
                'summary' => [
                    'total_debit' => 0,
                    'total_credit' => 0,
                    'total_modal' => 0,
                    'total_profit' => 0
                ],
                'data' => []
            ];

            $storesData = [];

            foreach ($closings as $closing) {
                $storeId = $closing->id_toko ?? 0;
                $tokoName = $closing->toko_name ?? 'Unknown';

                if (!isset($storesData[$storeId])) {
                    $storesData[$storeId] = [
                        'toko' => $tokoName,
                        'summary' => [
                            'total_debit' => 0,
                            'total_credit' => 0,
                            'total_modal' => 0,
                            'total_profit' => 0
                        ],
                        'data' => []
                    ];
                }

                // Pastikan semua key ada dengan nilai default
                $closingData = [
                    'invoice' => $closing->invoice ?? '',
                    'id_toko' => $closing->id_toko ?? null,
                    'tanggal' => !empty($closing->date_time) ? date('Y-m-d', strtotime($closing->date_time)) : '',
                    'profit' => isset($closing->total_profit) ? floatval($closing->total_profit) : 0,
                    'total_debit' => isset($closing->total_debit) ? floatval($closing->total_debit) : 0,
                    'total_credit' => isset($closing->total_credit) ? floatval($closing->total_credit) : 0,
                    'total_modal' => isset($closing->total_modal) ? floatval($closing->total_modal) : 0,
                    'transaction_status' => $closing->transaction_status ?? 'UNKNOWN',
                    'detail' => $detailsIndex[$closing->id] ?? []
                ];

                $storesData[$storeId]['data'][] = $closingData;

                // Update store summary dengan pengecekan key
                $storesData[$storeId]['summary']['total_debit'] += $closingData['total_debit'];
                $storesData[$storeId]['summary']['total_credit'] += $closingData['total_credit'];
                $storesData[$storeId]['summary']['total_modal'] += $closingData['total_modal'];
                $storesData[$storeId]['summary']['total_profit'] += $closingData['profit'];

                // Update global summary dengan pengecekan key
                $response['summary']['total_debit'] += $closingData['total_debit'];
                $response['summary']['total_credit'] += $closingData['total_credit'];
                $response['summary']['total_modal'] += $closingData['total_modal'];
                $response['summary']['total_profit'] += $closingData['profit'];
            }

            $response['data'] = array_values($storesData);

            return $this->response->setJSON($response);

        } catch (\Exception $e) {
            log_message('error', 'Get closing details failed: ' . $e->getMessage());
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal mengambil data closing: ' . $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    public function rollbackClosingByMonth()
    {
        $json = $this->request->getJSON();
        $year = $json->year ?? null;
        $month = $json->month ?? null;

        if (!$year || !$month) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Year dan month dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = "$year-$month-01 00:00:00";
        $endDate = date("Y-m-t 23:59:59", strtotime($startDate));
        $closingMonth = date("Y-m", strtotime($startDate));

        // Mulai transaksi database
        $this->db->transBegin();

        try {
            // 1. Ambil semua closing di periode itu
            $closings = $this->db->table('transaction_closing')
                ->where('period_start >=', $startDate)
                ->where('period_start <=', $endDate)
                ->get()
                ->getResult();

            if (empty($closings)) {
                return $this->response->setJSON([
                    'status' => 'empty',
                    'message' => 'Tidak ada data closing untuk bulan tersebut.'
                ]);
            }

            $closingIds = array_column($closings, 'id');
            $transactionIds = array_column($closings, 'transaction_id');

            // 2. Ambil semua id_cashflow dari closing_detail
            $cashflowIds = $this->db->table('closing_detail')
                ->select('id_cashflow')
                ->whereIn('transaction_closing_id', $closingIds)
                ->where('id_cashflow IS NOT NULL')
                ->get()
                ->getResultArray();

            $cashflowIds = array_column($cashflowIds, 'id_cashflow');

            // 3. Hapus closing_detail
            $this->db->table('closing_detail')
                ->whereIn('transaction_closing_id', $closingIds)
                ->delete();

            // 4. Hapus transaction_closing
            $this->db->table('transaction_closing')
                ->whereIn('id', $closingIds)
                ->delete();

            // 5. Kembalikan status transaksi ke semula (unclosing)
            foreach ($closings as $closing) {
                $this->db->table('transaction')
                    ->where('id', $closing->transaction_id)
                    ->update(['closing' => $closing->closing_status]);
            }

            // 6. Update sales_product.closing = 0
            $this->db->table('sales_product')
                ->whereIn('id_transaction', $transactionIds)
                ->update(['closing' => 0]);

            // 7. Update cashflow.closing = 0
            if (!empty($cashflowIds)) {
                $this->db->table('cashflow')
                    ->whereIn('id', $cashflowIds)
                    ->update(['closing' => 0]);
            }

            // 8. Hapus data supplier_closing sesuai periode
            $this->db->table('supplier_closing')
                ->where('closing_month', $closingMonth)
                ->delete();

            // Commit transaksi jika semua sukses
            $this->db->transCommit();

            return $this->response->setJSON([
                'status' => 'success',
                'message' => "Rollback data closing bulan {$month}/{$year} berhasil",
                'affected_transactions' => count($transactionIds),
                'affected_cashflows' => count($cashflowIds),
                'affected_closings' => count($closingIds)
            ]);

        } catch (\Exception $e) {
            // Rollback semua jika ada error
            $this->db->transRollback();

            log_message('error', 'Rollback closing failed: ' . $e->getMessage());

            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal melakukan rollback: ' . $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    // Helper methods
    private function getCashflowsForClosing($invoice)
    {

        return $this->db->table('cashflow')
            ->where('noted LIKE', "%{$invoice}%")
            ->where('closing', 0)
            ->orderBy('date_time', 'ASC')
            ->get()
            ->getResult();
    }

    private function getSalesProductsForClosing($transactionId)
    {
        return $this->db->table('sales_product')
            ->where('id_transaction', $transactionId)
            ->get()
            ->getResult();
    }

    private function getPreviousClosing($transactionId)
    {
        return $this->db->table('transaction_closing')
            ->where('transaction_id', $transactionId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRow();
    }

    private function calculateNewModal($details)
    {
        return array_reduce($details, function ($carry, $detail) {
            return $carry + ($detail['tipe'] === 'MODAL' ? $detail['credit'] : 0);
        }, 0);
    }

    private function saveClosingData($trxId, $idToko, $start, $end, $closingStatus, $status, $paymentCount, $totalDebit, $totalCredit, $profit, $totalModal)
    {
        $this->db->table('transaction_closing')->insert([
            'transaction_id' => $trxId,
            'id_toko' => $idToko,
            'period_start' => $start,
            'period_end' => $end,
            'closing_status' => $closingStatus,
            'transaction_status' => $status,
            'payment_count' => $paymentCount,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'total_profit' => $profit,
            'total_modal' => $totalModal,
            'closing_date' => date('Y-m-d H:i:s')
        ]);

        return $this->db->insertID();
    }

    private function saveClosingDetails($closingId, $details, $defaultTanggal)
    {
        $batchData = [];
        foreach ($details as $d) {
            // Gunakan tanggal dari cashflow jika ada, jika tidak gunakan default tanggal
            $tanggal = isset($d['tanggal']) ? $d['tanggal'] : $defaultTanggal;

            $batchData[] = [
                'transaction_closing_id' => $closingId,
                'keterangan' => $d['keterangan'],
                'tipe' => $d['tipe'],
                'tanggal' => $tanggal, // Gunakan tanggal yang sudah disesuaikan
                'debit' => $d['debit'],
                'credit' => $d['credit'],
                'urutan' => $d['urutan'],
                'id_cashflow' => $d['id_cashflow']
            ];
        }

        if (!empty($batchData)) {
            $this->db->table('closing_detail')->insertBatch($batchData);
        }
    }

    private function updateClosingStatuses($trxId, $cashflows, $isAdjustment = false)
    {
        // Untuk adjustment, transaction.closing tetap 2
        // Untuk normal closing, ubah menjadi 1
        $newClosingStatus = $isAdjustment ? 2 : 1;

        $this->db->table('transaction')
            ->where('id', $trxId)
            ->update(['closing' => $newClosingStatus]);

        // Update sales_product - untuk adjustment, yang closing != 2 diupdate ke 1
        if ($isAdjustment) {
            $this->db->table('sales_product')
                ->where('id_transaction', $trxId)
                ->where('closing !=', 2)
                ->update(['closing' => 1]);
        } else {
            $this->db->table('sales_product')
                ->where('id_transaction', $trxId)
                ->where('closing !=', 2)
                ->update(['closing' => 1]);
        }

        $cashflowIds = array_column($cashflows, 'id');
        if (!empty($cashflowIds)) {
            $this->db->table('cashflow')
                ->whereIn('id', $cashflowIds)
                ->update(['closing' => 1]);
        }
    }
    private function debugCashflows($cashflows, $invoice)
    {
        $debugInfo = [];
        foreach ($cashflows as $cf) {
            $debugInfo[] = [
                'noted' => $cf->noted,
                'debit' => $cf->debit,
                'credit' => $cf->credit,
                'date_time' => $cf->date_time,
                'type' => $this->detectCashflowType($cf->noted)
            ];
        }

        log_message('info', "Debug cashflows for {$invoice}: " . json_encode($debugInfo));
    }

    private function detectCashflowType($noted)
    {
        $desc = strtoupper(trim($noted));

        if (strpos($desc, 'DP') !== false)
            return 'DP';
        if (strpos($desc, 'PELUNASAN') !== false)
            return 'PELUNASAN';
        if (strpos($desc, 'PEMBAYARAN') !== false)
            return 'PEMBAYARAN';
        if (strpos($desc, 'REFUND') !== false)
            return 'REFUND';
        if (strpos($desc, 'ONGKIR') !== false || strpos($desc, 'ONGKOS KIRIM') !== false)
            return 'ONGKOS_KIRIM';
        if (strpos($desc, 'BAYAR') !== false)
            return 'PAYMENT';

        return 'OTHER';
    }

    public function getSupplierClosingReport()
    {
        try {
            $request = $this->request->getGet();

            $closingMonth = $request['closing_month_year'] ?? null;
            $transactionId = $request['transaction_id'] ?? null;
            $kodeBarang = isset($request['kode_barang']) ? (array) $request['kode_barang'] : [];
            $suplier = $request['suplier'] ?? null;

            // ✅ Supplier wajib diisi
            if (empty($suplier)) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'Parameter suplier wajib diisi.',
                ])->setStatusCode(400);
            }

            $builder = $this->db->table('supplier_closing sc')
                ->select("
                sc.id,
                sc.dropship_suplier,
                sc.transaction_id,
                sc.kode_barang,
                sc.jumlah,
                sc.total,
                sc.harga_modal,
                sc.total_harga_modal,
                sc.closing_month,
                sc.created_at
            ")
                ->where('sc.dropship_suplier', $suplier);

            // ✅ Filter tambahan opsional
            if ($closingMonth) {
                $builder->where('sc.closing_month', $closingMonth);
            }

            if ($transactionId) {
                $builder->where('sc.transaction_id', $transactionId);
            }

            if (!empty($kodeBarang)) {
                $builder->whereIn('sc.kode_barang', $kodeBarang);
            }

            $data = $builder->get()->getResultArray();

            if (empty($data)) {
                return $this->response->setJSON([
                    'status' => 'empty',
                    'message' => 'Tidak ada data ditemukan untuk suplier tersebut.',
                    'data' => []
                ]);
            }

            // ✅ Group berdasarkan kode_barang
            $grouped = [];
            foreach ($data as $row) {
                $kode = $row['kode_barang'];

                if (!isset($grouped[$kode])) {
                    $grouped[$kode] = [
                        'kode_barang' => $row['kode_barang'],
                        'total_jumlah' => 0,
                        'total' => 0,
                        'total_modal' => 0,
                        'data' => []
                    ];
                }

                $grouped[$kode]['total_jumlah'] += (float) $row['jumlah'];
                $grouped[$kode]['total'] += (float) $row['total'];
                $grouped[$kode]['total_modal'] += (float) $row['total_harga_modal'];
                $grouped[$kode]['data'][] = [
                    'transaction_id' => $row['transaction_id'],
                    'jumlah' => $row['jumlah'],
                    'total' => $row['total'],
                    'harga_modal' => $row['harga_modal'],
                    'total_harga_modal' => $row['total_harga_modal'],
                    'closing_month' => $row['closing_month'],
                    'tanggal' => $row['created_at'] ?? null,
                ];
            }

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Data laporan supplier berhasil diambil.',
                'data' => array_values($grouped)
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Error in getSupplierClosingReport: ' . $e->getMessage());
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ])->setStatusCode(500);
        }
    }



}