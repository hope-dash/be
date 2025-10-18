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
                'message' => 'year dan month dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = date("$year-$month-01 00:00:00");
        $endDate = date("Y-m-t 23:59:59", strtotime($startDate));

        $query1 = $this->db->table('transaction')
            ->where('status !=', 'WAITING_PAYMENT')
            ->where('closing', 0)
            ->where('updated_at >=', $startDate)
            ->where('updated_at <=', $endDate)
            ->get()->getResult();

        $query2 = $this->db->table('transaction')
            ->where('closing', 2)
            ->get()->getResult();

        $transactions = array_merge($query1, $query2);

        $results = [];
        foreach ($transactions as $trx) {
            $isAdjustment = ($trx->closing == 2);
            $result = $this->processClosing($trx, $startDate, $endDate, $isAdjustment);
            $results[] = $result;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'month' => "$month/$year",
            'results' => $results
        ]);
    }

    public function autoCloseMonthly()
    {
        $now = new \DateTime();
        $now->modify('first day of last month');
        $year = $now->format('Y');
        $month = $now->format('m');

        if (!$year || !$month) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'year dan month dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = date("$year-$month-01 00:00:00");
        $endDate = date("Y-m-t 23:59:59", strtotime($startDate));

        $query1 = $this->db->table('transaction')
            ->where('status !=', 'WAITING_PAYMENT')
            ->where('closing', 0)
            ->where('updated_at >=', $startDate)
            ->where('updated_at <=', $endDate)
            ->get()->getResult();

        $query2 = $this->db->table('transaction')
            ->where('closing', 2)
            ->get()->getResult();

        $transactions = array_merge($query1, $query2);

        $results = [];
        foreach ($transactions as $trx) {
            $isAdjustment = ($trx->closing == 2);
            $result = $this->processClosing($trx, $startDate, $endDate, $isAdjustment);
            $results[] = $result;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'month' => "$month/$year",
            'results' => $results
        ]);
    }

    private function processClosing($trx, $start, $end, $isAdjustment = false)
    {
        $trxId = $trx->id;
        $invoice = $trx->invoice;
        $status = strtoupper($trx->status);
        $closingStatus = $trx->closing;
        $tanggal = date('Y-m-d', strtotime($trx->date_time));
        $totalPayment = floatval($trx->total_payment);
        $idToko = $trx->id_toko ?? null;

        // 1. Get unclosed cashflows
        $cashflows = $this->db->query("SELECT * FROM cashflow WHERE noted LIKE ? AND closing = 0 ORDER BY date_time ASC", ['%' . $invoice])->getResult();

        // 2. Get sales products
        $sales = $this->db->table('sales_product')
            ->where('id_transaction', $trxId)
            ->get()
            ->getResult();

        // 3. Get previous closing data
        $previousClosing = $this->db->table('transaction_closing')
            ->where('transaction_id', $trxId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRow();

        $modalLama = $previousClosing ? $previousClosing->total_modal : 0;
        $prevStatus = $previousClosing ? strtoupper(string: $previousClosing->transaction_status) : null;
        $isDPPreviouslyClosed = $prevStatus === 'PARTIALLY_PAID';

        $detail = [];
        $urutan = 1;
        $hasRefund = false;

        // 4. Process cashflows
        foreach ($cashflows as $cf) {
            $desc = strtoupper(trim($cf->noted));
            $debit = floatval($cf->debit);
            $credit = floatval($cf->credit);

            if (strpos($desc, 'REFUND') !== false && $credit > 0) {
                $detail[] = [
                    'keterangan' => 'Refund',
                    'debit' => 0,
                    'credit' => $credit,
                    'urutan' => $urutan++,
                    'tipe' => 'REFUND',
                    'id_cashflow' => $cf->id
                ];
                $hasRefund = true;
            } elseif ((strpos($desc, 'DP') !== false || strpos($desc, 'PEMBAYARAN') !== false) && $debit > 0) {
                $tipe = strpos($desc, 'DP') !== false ? 'DP' : 'PEMBAYARAN';
                $detail[] = [
                    'keterangan' => $tipe === 'DP' ? 'DP' : 'Pembayaran',
                    'debit' => $debit,
                    'credit' => 0,
                    'urutan' => $urutan++,
                    'tipe' => $tipe,
                    'id_cashflow' => $cf->id
                ];
            } elseif ((strpos($desc, 'ONGKIR') !== false || strpos($desc, 'ONGKOS KIRIM') !== false) && $credit > 0) {
                $detail[] = [
                    'keterangan' => 'Ongkos Kirim',
                    'debit' => 0,
                    'credit' => $credit,
                    'urutan' => $urutan++,
                    'tipe' => 'ONGKOS_KIRIM',
                    'id_cashflow' => $cf->id
                ];
            }
        }

        // 5. Handle returns
        $hasReturnedItems = $this->db->table('sales_product')
            ->where('id_transaction', $trxId)
            ->where('closing', 2)
            ->countAllResults() > 0;

        if ($hasReturnedItems && $modalLama > 0) {
            $detail[] = [
                'keterangan' => 'Pembatalan Modal Lama',
                'debit' => $modalLama,
                'credit' => 0,
                'urutan' => $urutan++,
                'tipe' => 'MODAL_CORRECTION',
                'id_cashflow' => null
            ];
        }

        // 6. Calculate new modal
        $totalModalBaru = 0;
        $hasIncomingPayment = false;
        foreach ($cashflows as $cf) {
            $desc = strtoupper(trim($cf->noted));
            if ((strpos($desc, 'DP') !== false || strpos($desc, 'PEMBAYARAN') !== false) && floatval($cf->debit) > 0) {
                $hasIncomingPayment = true;
                break;
            }
        }

        $shouldCountModal = !$isDPPreviouslyClosed && !$hasReturnedItems && $closingStatus == 0 && $hasIncomingPayment;

        if ($shouldCountModal) {
            $totalModalBaru = array_reduce($sales, function ($carry, $s) {
                return $carry + (floatval($s->modal_system) * intval($s->jumlah));
            }, 0);

            if ($totalModalBaru > 0) {
                $detail[] = [
                    'keterangan' => 'Modal',
                    'debit' => 0,
                    'credit' => $totalModalBaru,
                    'urutan' => $urutan++,
                    'tipe' => 'MODAL',
                    'id_cashflow' => null
                ];
            }
        }

        // 7. Handle refund with modal return
        if ($status === 'REFUNDED' && $hasRefund) {
            if ($closingStatus == 0) {
                // Belum pernah closing
                $totalModal = array_reduce($sales, function ($carry, $s) {
                    return $carry + (floatval($s->modal_system) * intval($s->jumlah));
                }, 0);
                if ($totalModal > 0) {
                    $detail[] = [
                        'keterangan' => 'Pengembalian Modal',
                        'debit' => $totalModal,
                        'credit' => 0,
                        'urutan' => $urutan++,
                        'tipe' => 'MODAL_REFUND',
                        'id_cashflow' => null
                    ];
                }
            } elseif ($closingStatus == 2 && $modalLama > 0) {
                // Sudah pernah closing, lakukan koreksi modal lama
                $detail[] = [
                    'keterangan' => 'Pengembalian Modal',
                    'debit' => $modalLama,
                    'credit' => 0,
                    'urutan' => $urutan++,
                    'tipe' => 'MODAL_REFUND',
                    'id_cashflow' => null
                ];
            }
        }

        // 8. Totals
        $totalDebit = array_sum(array_column($detail, 'debit'));
        $totalCredit = array_sum(array_column($detail, 'credit'));
        $profit = $totalDebit - $totalCredit;

        // 9. Save closing
        $this->db->table('transaction_closing')->insert([
            'transaction_id' => $trxId,
            'id_toko' => $idToko,
            'period_start' => $start,
            'period_end' => $end,
            'closing_status' => $closingStatus,
            'transaction_status' => $status,
            'payment_count' => count($cashflows),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'total_profit' => $profit,
            'total_modal' => $totalModalBaru,
            'closing_date' => date('Y-m-d H:i:s')
        ]);
        $closingId = $this->db->insertID();

        // 10. Save details
        foreach ($detail as $d) {
            $this->db->table('closing_detail')->insert([
                'transaction_closing_id' => $closingId,
                'keterangan' => $d['keterangan'],
                'tipe' => $d['tipe'],
                'tanggal' => $tanggal,
                'debit' => $d['debit'],
                'credit' => $d['credit'],
                'urutan' => $d['urutan'],
                'id_cashflow' => $d['id_cashflow']
            ]);
        }

        // 11. Update closing status
        $this->db->table('transaction')->where('id', $trxId)->update(['closing' => 1]);
        $this->db->table('sales_product')
            ->where('id_transaction', $trxId)
            ->where('closing !=', 2)
            ->update(['closing' => 1]);
        foreach ($cashflows as $cf) {
            $this->db->table('cashflow')->where('id', $cf->id)->update(['closing' => 1]);
        }

        // 12. Return response
        $response = [
            'invoice' => $invoice,
            'tanggal' => $tanggal,
            'profit' => $profit,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'transaction_status' => $status,
            'detail' => $this->customSortDetail($detail)
        ];

        if (!$isDPPreviouslyClosed && $totalModalBaru > 0 && $status !== 'REFUNDED') {
            $response['total_modal'] = $totalModalBaru;
        }

        return $response;
    }


    private function customSortDetail(array $details): array
    {
        $order = [
            'DP' => 1,
            'PEMBAYARAN' => 2,
            'MODAL' => 3,
            'MODAL_REFUND' => 4,
            'MODAL_CORRECTION' => 5,
            'REFUND' => 6
        ];

        usort($details, function ($a, $b) use ($order) {
            $aOrder = $order[$a['tipe']] ?? 999;
            $bOrder = $order[$b['tipe']] ?? 999;
            return $aOrder === $bOrder ? $a['urutan'] <=> $b['urutan'] : $aOrder <=> $bOrder;
        });

        foreach ($details as $i => &$d) {
            $d['urutan'] = $i + 1;
        }

        return $details;
    }



    public function listClosings()
    {
        $result = $this->db->table('transaction_closing')
            ->select("DATE_FORMAT(period_start, '%Y-%m') as period")
            ->groupBy("period")
            ->orderBy("period", "desc")
            ->get()->getResult();

        $periods = array_map(function ($row) {
            return $row->period;
        }, $result);

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
                'message' => 'year dan month dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = "$year-$month-01 00:00:00";
        $endDate = date("Y-m-t 23:59:59", strtotime($startDate));

        // Get all needed data in optimized queries
        $query = $this->db->table('transaction_closing tc')
            ->select('tc.*, t.toko_name, tr.invoice, tr.date_time')
            ->join('toko t', 'tc.id_toko = t.id', 'left')
            ->join('transaction tr', 'tc.transaction_id = tr.id', 'left')
            ->where('tc.period_start >=', $startDate)
            ->where('tc.period_start <=', $endDate)
            ->orderBy('t.toko_name', 'asc')
            ->orderBy('tr.date_time', 'asc');

        $closings = $query->get()->getResult();

        if (empty($closings)) {
            return $this->response->setJSON([
                'status' => 'empty',
                'message' => 'Tidak ada data closing untuk bulan tersebut.'
            ]);
        }

        // Get all details in one query
        $closingIds = array_column($closings, 'id');
        $details = $this->db->table('closing_detail')
            ->whereIn('transaction_closing_id', $closingIds)
            ->orderBy('transaction_closing_id', 'asc')
            ->orderBy('urutan', 'asc')
            ->get()
            ->getResult();

        // Index details by closing_id for faster lookup
        $detailsIndex = [];
        foreach ($details as $detail) {
            $detailsIndex[$detail->transaction_closing_id][] = $detail;
        }

        // Prepare response structure
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

            $closingData = [
                'invoice' => $closing->invoice,
                'id_toko' => $closing->id_toko,
                'tanggal' => date('Y-m-d', strtotime($closing->date_time)),
                'profit' => floatval($closing->total_profit),
                'total_debit' => floatval($closing->total_debit),
                'total_credit' => floatval($closing->total_credit),
                'total_modal' => floatval($closing->total_modal),
                'transaction_status' => $closing->transaction_status,
                'detail' => $detailsIndex[$closing->id] ?? []
            ];

            // Add to store data
            $storesData[$storeId]['data'][] = $closingData;

            // Update store summary
            $storesData[$storeId]['summary']['total_debit'] += $closingData['total_debit'];
            $storesData[$storeId]['summary']['total_credit'] += $closingData['total_credit'];
            $storesData[$storeId]['summary']['total_modal'] += $closingData['total_modal'];
            $storesData[$storeId]['summary']['total_profit'] += $closingData['profit'];

            // Update global summary
            $response['summary']['total_debit'] += $closingData['total_debit'];
            $response['summary']['total_credit'] += $closingData['total_credit'];
            $response['summary']['total_modal'] += $closingData['total_modal'];
            $response['summary']['total_profit'] += $closingData['profit'];
        }

        $response['data'] = array_values($storesData);

        return $this->response->setJSON($response);
    }
    // Ambil invoice
    private function getInvoice($transactionId)
    {
        return $this->db->table('transaction')->select('invoice')->where('id', $transactionId)->get()->getRow('invoice');
    }

    // Ambil tanggal (dari date_time)
    private function getTanggal($transactionId)
    {
        $trx = $this->db->table('transaction')->select('date_time')->where('id', $transactionId)->get()->getRow();
        return $trx ? date('Y-m-d', strtotime($trx->date_time)) : null;
    }

    public function rollbackClosingByMonth()
    {
        $json = $this->request->getJSON();
        $year = $json->year ?? null;
        $month = $json->month ?? null;

        if (!$year || !$month) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'year dan month dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = "$year-$month-01 00:00:00";
        $endDate = date("Y-m-t 23:59:59", strtotime($startDate));

        // Mulai transaction database
        $this->db->transBegin();

        try {
            // 1. Ambil semua data closing yang akan dirollback
            $closings = $this->db->table('transaction_closing')
                ->where('period_start >=', $startDate)
                ->where('period_start <=', $endDate)
                ->get()->getResult();

            if (empty($closings)) {
                return $this->response->setJSON([
                    'status' => 'empty',
                    'message' => 'Tidak ada data closing untuk bulan tersebut.'
                ]);
            }

            $closingIds = array_column($closings, 'id');
            $transactionIds = array_column($closings, 'transaction_id');

            // 2. Ambil semua id_cashflow dari closing_detail yang akan dirollback
            $cashflowIds = $this->db->table('closing_detail')
                ->select('id_cashflow')
                ->whereIn('transaction_closing_id', $closingIds)
                ->where('id_cashflow IS NOT NULL')
                ->get()
                ->getResultArray();
            $cashflowIds = array_column($cashflowIds, 'id_cashflow');

            // 3. Hapus data di closing_detail
            $this->db->table('closing_detail')
                ->whereIn('transaction_closing_id', $closingIds)
                ->delete();

            // 4. Hapus data di transaction_closing
            $this->db->table('transaction_closing')
                ->whereIn('id', $closingIds)
                ->delete();

            // 5. Update status di tabel terkait
            // a. Update transaction.closing = 0
            $this->db->table('transaction')
                ->whereIn('id', $transactionIds)
                ->update(['closing' => 0]);

            // b. Update sales_product.closing = 0
            $this->db->table('sales_product')
                ->whereIn('id_transaction', $transactionIds)
                ->update(['closing' => 0]);

            // c. Update cashflow.closing = 0 berdasarkan id_cashflow dari closing_detail
            if (!empty($cashflowIds)) {
                $this->db->table('cashflow')
                    ->whereIn('id', $cashflowIds)
                    ->update(['closing' => 0]);
            }

            // Commit transaction jika semua sukses
            $this->db->transCommit();

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Rollback data closing bulan ' . $month . '/' . $year . ' berhasil',
                'affected_transactions' => count($transactionIds),
                'affected_cashflows' => count($cashflowIds)
            ]);

        } catch (\Exception $e) {
            // Rollback transaction jika ada error
            $this->db->transRollback();

            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal melakukan rollback: ' . $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    // Helper function untuk mendapatkan invoice berdasarkan transaction_ids
    private function getInvoicesDetail(array $transactionIds): array
    {
        if (empty($transactionIds)) {
            return [];
        }

        $result = $this->db->table('transaction')
            ->select('invoice')
            ->whereIn('id', $transactionIds)
            ->get()
            ->getResultArray();

        return $result ?: [];
    }

}
