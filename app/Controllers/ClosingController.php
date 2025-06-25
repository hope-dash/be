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

    private function processClosing($trx, $start, $end, $isAdjustment = false)
    {
        $trxId = $trx->id;
        $invoice = $trx->invoice;
        $status = strtoupper($trx->status);
        $closingStatus = $trx->closing;
        $tanggal = date('Y-m-d', strtotime($trx->date_time));
        $totalPayment = floatval($trx->total_payment);

        $cashflows = $this->db->query("SELECT * FROM cashflow WHERE noted LIKE ? ORDER BY date_time ASC", ['%' . $invoice])->getResult();
        $sales = $this->db->table('sales_product')->where('id_transaction', $trxId)->get()->getResult();
        $modalLama = $this->db->query("SELECT total_modal FROM transaction_closing WHERE transaction_id = ? ORDER BY id DESC LIMIT 1", [$trxId])->getRow('total_modal') ?? 0;

        $detail = [];
        $totalDebit = 0;
        $totalCredit = 0;
        $totalModalBaru = 0;
        $urutan = 1;

        // Proses cashflow (pembayaran dan refund)
        foreach ($cashflows as $cf) {
            $desc = strtoupper($cf->noted);
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
                $totalCredit += $credit;
            } elseif (strpos($desc, 'ONGKOS') !== false && $credit > 0) {
                $detail[] = [
                    'keterangan' => 'Biaya Tambahan',
                    'debit' => 0,
                    'credit' => $credit,
                    'urutan' => $urutan++,
                    'tipe' => 'ONGKOS_KIRIM',
                    'id_cashflow' => $cf->id
                ];
                $totalCredit += $credit;
            } elseif ((strpos($desc, 'DP') !== false || strpos($desc, 'PEMBAYARAN') !== false) && $debit > 0) {
                $tipe = strpos($desc, 'DP') !== false ? 'DP' : 'PEMBAYARAN';
                $detail[] = [
                    'keterangan' => 'Pembayaran',
                    'debit' => $debit,
                    'credit' => 0,
                    'urutan' => $urutan++,
                    'tipe' => $tipe,
                    'id_cashflow' => $cf->id
                ];
                $totalDebit += $debit;
            }
        }

        // Hitung modal baru hanya jika ada pembayaran
        if ($totalPayment > 0) {
            foreach ($sales as $s) {
                $totalModalBaru += floatval($s->modal_system) * intval($s->jumlah);
            }

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

        // Handle kasus refund penuh (total_credit = total_debit dan status REFUNDED)
        if ($status === 'REFUNDED' && $totalCredit > 0 && $totalCredit == $totalDebit && $totalModalBaru > 0) {
            $detail[] = [
                'keterangan' => 'Modal Refund',
                'debit' => $totalModalBaru,
                'credit' => 0,
                'urutan' => $urutan++,
                'tipe' => 'MODAL_REFUND',
                'id_cashflow' => null
            ];
            // Karena modal dikembalikan, net modal menjadi 0
            $totalModalBaru = 0;
        }

        // Hitung profit akhir
        $profit = $totalDebit - $totalCredit - $totalModalBaru + $modalLama;

        // Insert ke transaction_closing
        $this->db->table('transaction_closing')->insert([
            'transaction_id' => $trxId,
            'period_start' => $start,
            'period_end' => $end,
            'closing_status' => $closingStatus,
            'payment_count' => count($cashflows),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'total_profit' => $profit,
            'total_modal' => $totalModalBaru,
            'closing_date' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $closingId = $this->db->insertID();

        // Urutkan detail
        $detail = $this->customSortDetail($detail);

        // Insert detail
        foreach ($detail as $d) {
            $this->db->table('closing_detail')->insert([
                'transaction_closing_id' => $closingId,
                'keterangan' => $d['keterangan'],
                'tipe' => $d['tipe'],
                'tanggal' => $tanggal,
                'debit' => $d['debit'],
                'credit' => $d['credit'],
                'urutan' => $d['urutan'],
                'id_cashflow' => $d['id_cashflow'],
            ]);
        }

        // Update status closing
        $this->db->table('transaction')->where('id', $trxId)->update(['closing' => 1]);
        $this->db->table('sales_product')->where('id_transaction', $trxId)->update(['closing' => 1]);
        foreach ($cashflows as $cf) {
            $this->db->table('cashflow')->where('id', $cf->id)->update(['closing' => 1]);
        }

        return [
            'invoice' => $invoice,
            'tanggal' => $tanggal,
            'profit' => $profit,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'total_modal' => $totalModalBaru,
            'detail' => $detail
        ];
    }

    private function customSortDetail(array $details): array
    {
        $order = [
            'DP' => 1,
            'PEMBAYARAN' => 2,
            'MODAL' => 3,
            'REFUND' => 4,
            'MODAL_REFUND' => 5,
            'MODAL_CORRECTION' => 6,
            'ONGKOS_KIRIM' => 7
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

        // Ambil data closing berdasarkan period_start
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

        // Ambil detail dari semua closing
        $detailRows = $this->db->table('closing_detail')
            ->whereIn('transaction_closing_id', $closingIds)
            ->orderBy('tanggal', 'asc')
            ->orderBy('urutan', 'asc')
            ->get()->getResult();

        // Inisialisasi variabel summary
        $summary = [
            'total_debit' => 0,
            'total_credit' => 0,
            'total_modal' => 0,
            'total_profit' => 0
        ];

        // Gabungkan detail ke transaksi
        $grouped = [];
        foreach ($closings as $closing) {
            $grouped[$closing->id] = [
                'invoice' => $this->getInvoice($closing->transaction_id),
                'tanggal' => $this->getTanggal($closing->transaction_id),
                'profit' => floatval($closing->total_profit),
                'total_debit' => floatval($closing->total_debit),
                'total_credit' => floatval($closing->total_credit),
                'total_modal' => floatval($closing->total_modal),
                'detail' => []
            ];

            // Hitung summary
            $summary['total_debit'] += floatval($closing->total_debit);
            $summary['total_credit'] += floatval($closing->total_credit);
            $summary['total_modal'] += floatval($closing->total_modal);
            $summary['total_profit'] += floatval($closing->total_profit);
        }

        foreach ($detailRows as $d) {
            if (!isset($grouped[$d->transaction_closing_id]))
                continue;

            $grouped[$d->transaction_closing_id]['detail'][] = [
                'keterangan' => $d->keterangan,
                'debit' => floatval($d->debit),
                'credit' => floatval($d->credit),
                'urutan' => intval($d->urutan),
                'tipe' => $d->tipe,
                'id_cashflow' => $d->id_cashflow
            ];
        }

        return $this->response->setJSON([
            'status' => 'success',
            'month' => "$month/$year",
            'summary' => $summary, // Tambahkan summary di sini
            'data' => array_values($grouped) // reset key agar jadi array numerik
        ]);
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
