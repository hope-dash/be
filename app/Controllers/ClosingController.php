<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ClosingModel;
use App\Models\CashflowModel;
use App\Models\ClosingDetailModel;
use App\Models\JsonResponse;
use App\Controllers\TransactionController;

class ClosingController extends BaseController
{
    protected $jsonResponse;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
    }

    public function store()
    {
        $request = $this->request->getJSON(true);

        $dateStart = $request['date_start'] ?? null;
        $dateEnd = $request['date_end'] ?? null;
        $inputToko = $request['id_toko'] ?? null;

        $token = $this->request->user ?? null;
        $createdBy = $token['user_id'] ?? null;
        $tokenToko = $token['id_toko'] ?? null;
        $idToko = $inputToko ?? $tokenToko;

        if (!$dateStart || !$dateEnd) {
            return $this->jsonResponse->error("Tanggal awal dan akhir wajib diisi.", 400);
        }

        if (!$createdBy) {
            return $this->jsonResponse->error("Token tidak valid atau tidak lengkap.", 401);
        }

        $closingModel = new ClosingModel();
        $closingModel->groupStart()
            ->where('date_start <=', $dateEnd)
            ->where('date_end >=', $dateStart)
            ->groupEnd();

        if ($idToko) {
            $closingModel->where('id_toko', $idToko);
        }

        if ($closingModel->first()) {
            return $this->jsonResponse->error(
                "Sudah ada data closing dalam rentang waktu yang sama" . ($idToko ? " di toko ini." : "."),
                409
            );
        }

        $startVal = $dateStart . ' 00:00:00';
        $endVal = $dateEnd . ' 23:59:59';

        $cashflowModel = new CashflowModel();
        $cashflowQuery = $cashflowModel
            ->where('type', 'transaction')
            ->where("date_time BETWEEN '{$startVal}' AND '{$endVal}'");

        if ($idToko) {
            $cashflowQuery->where('id_toko', $idToko);
        }

        $cashflow = $cashflowQuery
            ->select('SUM(debit) AS total_debit, SUM(credit) AS total_credit')
            ->first();

        $debit = floatval($cashflow['total_debit'] ?? 0);
        $credit = floatval($cashflow['total_credit'] ?? 0);
        $selisih = $debit - $credit;

        $transaction = new TransactionController();
        $summary = $transaction->getRevenueProfitData($dateStart, $dateEnd, $idToko);

        $totalRevenue = floatval($summary->total_revenue ?? 0);
        $totalModal = floatval($summary->total_modal ?? 0);
        $totalProfit = floatval($summary->total_profit ?? 0);
        $totalBeban = floatval($summary->total_beban ?? 0);

        if (abs($selisih - $totalRevenue) > 0.01) {
            return $this->jsonResponse->error("Selisih cashflow tidak sesuai dengan total revenue. Closing dibatalkan.", 400);
        }

        $closingId = $closingModel->insert([
            'id_toko' => $idToko ?? 0,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'status' => 'success',
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $createdBy,
            'total_pemasukan' => $totalRevenue,
            'total_modal' => $totalModal,
            'total_laba' => $totalProfit,
            'total_beban' => $totalBeban,
        ]);


        $queryBuilder = $transaction->getSalesProductWithTransactionQuery([
            'id_toko' => $idToko,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
        ]);

        $results = $queryBuilder->select('
            sp.*,
            t.invoice,
            t.id_toko,
            tm_paid.value as paid_at,
            tm_partial.value as dp_at
        ')
            ->get()
            ->getResultArray();

        $closingDetailData = array_map(function ($row) use ($closingId) {
            return [
                'closing_id' => $closingId,
                'id_transaction' => $row['id_transaction'],
                'kode_barang' => $row['kode_barang'],
                'qty' => $row['jumlah'],
                'harga_jual' => $row['actual_total'],
                'harga_modal' => $row['total_modal'],
                'invoice' => $row['invoice'],
                'id_toko' => $row['id_toko'],
                'paid_at' => $row['paid_at'],
                'dp_at' => $row['dp_at'],
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }, $results);

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            if (!empty($closingDetailData)) {
                $closingDetailModel = new ClosingDetailModel();
                $closingDetailModel->insertBatch($closingDetailData);

                $salesProductIds = array_column($results, 'id');
                if (!empty($salesProductIds)) {
                    $db->table('sales_product')
                        ->whereIn('id', $salesProductIds)
                        ->update(['closing' => "VALID"]);
                }
            }

            $db->transCommit();
            return $this->jsonResponse->oneResp('Closing berhasil dilakukan', $closingId, 201);

        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->jsonResponse->error("Terjadi kesalahan saat closing: " . $e->getMessage(), 500);
        }
    }

    public function list()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $sortMethod = strtolower($this->request->getGet('sortMethod') ?? 'asc');
        $searchBulan = $this->request->getGet('search_bulan') ?? ''; // format: YYYY-MM
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;
        $offset = ($page - 1) * $limit;

        $closingModel = new ClosingModel();
        $builder = $closingModel;

        if (!empty($searchBulan)) {
            // Format filter bulan
            $builder = $builder->groupStart()
                ->like('date_start', $searchBulan, 'after')
                ->orLike('date_end', $searchBulan, 'after')
                ->groupEnd();
        }

        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);
    }

    public function getDetail($id = null)
    {
        if (!$id) {
            return $this->jsonResponse->error('ID Closing tidak boleh kosong', 400);
        }

        $db = \Config\Database::connect();

        // Ambil data cashflow utama
        $cashflow = $db->table('closing')
            ->where('id', $id)
            ->get()
            ->getResultArray();

        if (empty($cashflow)) {
            return $this->jsonResponse->error('Data cashflow tidak ditemukan', 404);
        }

        // Ambil semua ID cashflow
        $cashflowIds = array_column($cashflow, 'id');

        // Ambil detail dari closing_detail
        $details = [];
        if (!empty($cashflowIds)) {
            $details = $db->table('closing_detail')
                ->whereIn('closing_id', $cashflowIds)
                ->get()
                ->getResultArray();
        }

        return $this->jsonResponse->oneResp('Data ditemukan', [
            'data' => $cashflow,
            'details' => $details,
        ], 200);
    }

}
