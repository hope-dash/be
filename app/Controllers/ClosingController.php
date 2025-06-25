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

    public function closeByRange()
    {
        $request = service('request');
        $start = $request->getJSON()->start_date ?? null;
        $end = $request->getJSON()->end_date ?? null;

        if (!$start || !$end) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'start_date dan end_date dibutuhkan.'
            ])->setStatusCode(400);
        }

        $startDate = $start . ' 00:00:00';
        $endDate = $end . ' 23:59:59';

        // Cari periode yang sudah pernah di closing
        $existingPeriods = $this->db->query("SELECT period_start, period_end FROM transaction_closing GROUP BY period_start, period_end")->getResult();

        $excludedDates = [];
        foreach ($existingPeriods as $p) {
            $periodStart = $p->period_start;
            $periodEnd = $p->period_end;

            $range = new \DatePeriod(
                new \DateTime($periodStart),
                new \DateInterval('P1D'),
                (new \DateTime($periodEnd))->modify('+1 day')
            );

            foreach ($range as $date) {
                $excludedDates[] = $date->format('Y-m-d');
            }
        }

        $rangeToProcess = [];
        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval('P1D'),
            (new \DateTime($end))->modify('+1 day')
        );

        foreach ($period as $date) {
            $d = $date->format('Y-m-d');
            if (!in_array($d, $excludedDates)) {
                $rangeToProcess[] = $d;
            }
        }

        if (empty($rangeToProcess)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Tidak ada tanggal tersedia untuk di-closing dalam rentang yang diberikan.'
            ])->setStatusCode(400);
        }

        // Kelompokkan jadi beberapa rentang (period)
        $ranges = [];
        $tempStart = null;
        $prevDate = null;

        foreach ($rangeToProcess as $date) {
            if (!$tempStart) {
                $tempStart = $date;
            }
            if ($prevDate && date('Y-m-d', strtotime($prevDate . ' +1 day')) != $date) {
                $ranges[] = [
                    'start' => $tempStart,
                    'end' => $prevDate
                ];
                $tempStart = $date;
            }
            $prevDate = $date;
        }
        if ($tempStart && $prevDate) {
            $ranges[] = ['start' => $tempStart, 'end' => $prevDate];
        }

        $result = [];
        foreach ($ranges as $r) {
            $response = $this->processClosing($r['start'], $r['end']);
            $result[] = $response;
        }

        return $this->response->setJSON($result);
    }

    private function processClosing($start, $end)
    {
        $startDate = $start . ' 00:00:00';
        $endDate = $end . ' 23:59:59';

        $transaksi = $this->db->query("SELECT * FROM transaction WHERE closing != 1 AND status != 'waiting_payment' AND date_time BETWEEN ? AND ?", [$startDate, $endDate])->getResult();

        $results = [];
        foreach ($transaksi as $trx) {
            $invoice = $trx->invoice;
            $trxId = $trx->id;
            $status = strtoupper($trx->status);
            $closingStatus = $trx->closing;
            $tanggal = date('Y-m-d', strtotime($trx->date_time));

            $cashflows = $this->db->query("SELECT * FROM cashflow WHERE noted LIKE ? ORDER BY date_time ASC", ['%' . $invoice])->getResult();
            $sales = $this->db->query("SELECT * FROM sales_product WHERE id_transaction = ?", [$trxId])->getResult();

            $modalLama = $this->db->query("SELECT total_modal FROM transaction_closing WHERE transaction_id = ? ORDER BY id DESC LIMIT 1", [$trxId])->getRow('total_modal') ?? 0;

            $detail = [];
            $totalDebit = 0;
            $totalCredit = 0;
            $totalModalBaru = 0;
            $urutan = 1;
            $modalSudahDitarik = false;

            foreach ($cashflows as $cf) {
                $desc = strtoupper($cf->noted);
                $debit = floatval($cf->debit);
                $credit = floatval($cf->credit);

                if (strpos($desc, 'REFUND') !== false) {
                    $detail[] = ['keterangan' => 'Refund', 'debit' => 0, 'credit' => $credit, 'urutan' => $urutan++, 'tipe' => 'REFUND', 'id_cashflow' => $cf->id];
                    $totalCredit += $credit;
                } elseif (strpos($desc, 'ONGKOS') !== false) {
                    $detail[] = ['keterangan' => 'Biaya Tambahan', 'debit' => 0, 'credit' => $credit, 'urutan' => $urutan++, 'tipe' => 'ONGKOS_KIRIM', 'id_cashflow' => $cf->id];
                    $totalCredit += $credit;
                } elseif (strpos($desc, 'DP') !== false || strpos($desc, 'PEMBAYARAN') !== false) {
                    $tipe = strpos($desc, 'DP') !== false ? 'DP' : 'PEMBAYARAN';
                    $detail[] = ['keterangan' => 'Pembayaran', 'debit' => $debit, 'credit' => 0, 'urutan' => $urutan++, 'tipe' => $tipe, 'id_cashflow' => $cf->id];
                    $totalDebit += $debit;

                    if (!$modalSudahDitarik) {
                        if ($status === 'REFUNDED') {
                            $detail[] = ['keterangan' => 'Modal Refund', 'debit' => $modalLama, 'credit' => 0, 'urutan' => $urutan++, 'tipe' => 'MODAL_REFUND', 'id_cashflow' => null];
                        } elseif ($status === 'RETUR' && $closingStatus == 2 && $modalLama > 0) {
                            $detail[] = ['keterangan' => 'Pembatalan Modal Lama', 'debit' => $modalLama, 'credit' => 0, 'urutan' => $urutan++, 'tipe' => 'MODAL_CORRECTION', 'id_cashflow' => null];
                        }

                        foreach ($sales as $s) {
                            $totalModalBaru += floatval($s->modal_system) * intval($s->jumlah);
                        }

                        $detail[] = ['keterangan' => 'Modal', 'debit' => 0, 'credit' => $totalModalBaru, 'urutan' => $urutan++, 'tipe' => 'MODAL', 'id_cashflow' => null];
                        $modalSudahDitarik = true;
                    }
                }
            }

            $profit = $totalDebit - $totalCredit - $totalModalBaru + $modalLama;

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

            $this->db->table('transaction')->update(['closing' => 1], ['id' => $trxId]);
            $this->db->table('sales_product')->update(['closing' => 1], ['id_transaction' => $trxId]);
            foreach ($cashflows as $cf) {
                $this->db->table('cashflow')->update(['closing' => 1], ['id' => $cf->id]);
            }

            $results[] = [
                'invoice' => $invoice,
                'tanggal' => $tanggal,
                'profit' => $profit,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'total_modal' => $totalModalBaru,
                'detail' => $detail
            ];
        }

        return [
            'period' => "$start to $end",
            'data' => $results
        ];
    }

    public function listClosings()
    {
        $closings = $this->db->table('transaction_closing')
            ->select('period_start, period_end, COUNT(*) as jumlah_transaksi, SUM(total_profit) as total_profit, SUM(total_modal) as total_modal')
            ->groupBy('period_start, period_end')
            ->orderBy('period_start', 'desc')
            ->get()->getResult();

        return $this->response->setJSON($closings);
    }


    public function getClosingDetail($id)
    {
        $closing = $this->db->table('transaction_closing')->where('id', $id)->get()->getRow();
        $details = $this->db->table('closing_detail')->where('transaction_closing_id', $id)->orderBy('urutan')->get()->getResult();

        return $this->response->setJSON([
            'summary' => $closing,
            'detail' => $details
        ]);
    }
}
