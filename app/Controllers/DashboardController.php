<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\TransactionModel;
use App\Models\CustomerModel;
use App\Models\JsonResponse;

class DashboardController extends ResourceController
{
    protected $transactionModel;
    protected $customerModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        $this->transactionModel = new TransactionModel();
        $this->customerModel = new CustomerModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get Daily Summary Dashboard
     * Compares "Today" with "Yesterday" for Revenue, Transactions, Profit, and New Customers.
     */
    public function getSummary()
    {
        $id_toko = $this->request->getGet('id_toko');
        $role = $this->request->getGet('role');

        if (is_string($role)) {
            $role = array_filter(array_map('intval', explode(',', $role)));
        }

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $dataToday = $this->getMetricsForDay($today, $id_toko, $role);
        $dataYesterday = $this->getMetricsForDay($yesterday, $id_toko, $role);

        $response = [
            'revenue' => [
                'label' => 'Pendapatan Hari Ini',
                'current' => $dataToday['revenue'],
                'previous' => $dataYesterday['revenue'],
                'percentage' => $this->calculatePercentage($dataToday['revenue'], $dataYesterday['revenue'])
            ],
            'transactions' => [
                'label' => 'Total Transaksi',
                'current' => $dataToday['transactions'],
                'previous' => $dataYesterday['transactions'],
                'percentage' => $this->calculatePercentage($dataToday['transactions'], $dataYesterday['transactions'])
            ],
            'gross_profit' => [
                'label' => 'Laba Kotor',
                'current' => $dataToday['gross_profit'],
                'previous' => $dataYesterday['gross_profit'],
                'percentage' => $this->calculatePercentage($dataToday['gross_profit'], $dataYesterday['gross_profit'])
            ],
            'new_customers' => [
                'label' => 'Pelanggan Baru',
                'current' => $dataToday['new_customers'],
                'previous' => $dataYesterday['new_customers'],
                'diff' => $dataToday['new_customers'] - $dataYesterday['new_customers']
            ]
        ];

        return $this->jsonResponse->oneResp('Dashboard summary fetched successfully', $response, 200);
    }

    /**
     * Get Performance per Branch (Store)
     * Groups performance metrics by store for a specific month.
     */
    public function getBranchPerformance()
    {
        $id_toko = $this->request->getGet('id_toko'); // Can be string "1,2,3"
        $month = $this->request->getGet('month') ?: date('Y-m'); // Format: YYYY-MM

        $tokoIds = [];
        if (!empty($id_toko)) {
            if (is_array($id_toko)) {
                $tokoIds = $id_toko;
            } else {
                $tokoIds = array_filter(array_map('trim', explode(',', $id_toko)));
            }
        }

        $start = $month . '-01 00:00:00';
        $end = date('Y-m-t', strtotime($start)) . ' 23:59:59';

        $builder = $this->db->table('transaction t');
        $builder->select('
            t.id_toko,
            toko.toko_name as cabang_name,
            SUM(CASE WHEN t.status NOT IN ("CANCEL", "FAILED", "WAITING_PAYMENT") THEN t.actual_total ELSE 0 END) as revenue,
            COUNT(t.id) as total_transactions,
            SUM(CASE WHEN t.status IN ("SUCCESS", "PAID", "PACKING", "IN_DELIVERY", "PARTIALLY_PAID") THEN 1 ELSE 0 END) as paid_transactions
        ');
        $builder->join('toko', 'toko.id = t.id_toko', 'left');
        $builder->where('t.date_time >=', $start)
            ->where('t.date_time <=', $end);

        if (!empty($tokoIds)) {
            $builder->whereIn('t.id_toko', $tokoIds);
        }

        $builder->groupBy('t.id_toko');
        $builder->orderBy('revenue', 'DESC');

        $results = $builder->get()->getResultArray();

        foreach ($results as &$row) {
            $row['revenue'] = (float) $row['revenue'];
            $row['total_transactions'] = (int) $row['total_transactions'];
            $row['paid_transactions'] = (int) $row['paid_transactions'];

            // Success Rate calculation
            $successRate = $row['total_transactions'] > 0
                ? round(($row['paid_transactions'] / $row['total_transactions']) * 100, 1)
                : 0;

            $row['success_rate'] = $successRate;
            $row['progress_percentage'] = $successRate; // Use SR for the progress bar

            // Rating logic based on Success Rate
            if ($successRate >= 80) {
                $row['rating'] = 'Excellent';
            } elseif ($successRate >= 50) {
                $row['rating'] = 'Good';
            } else {
                $row['rating'] = 'Average';
            }
        }

        return $this->jsonResponse->oneResp('Branch performance fetched successfully', $results, 200);
    }

    /**
     * Query metrics for a specific day
     */
    private function getMetricsForDay($date, $id_toko = null, $role = null)
    {
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        // 1. Transaction Metrics (Revenue, Count, Profit)
        $builder = $this->db->table('transaction');
        $builder->select('
            SUM(actual_total) as revenue,
            COUNT(id) as transactions,
            SUM(actual_total - total_modal) as gross_profit
        ');
        $builder->where('date_time >=', $start)
            ->where('date_time <=', $end);

        // Exclude cancelled/failed/unpaid transactions to reflect "actual" sales
        $builder->whereNotIn('status', ['CANCEL', 'FAILED', 'WAITING_PAYMENT']);

        if (!empty($id_toko)) {
            $builder->where('id_toko', $id_toko);
        } elseif (!empty($role)) {
            $builder->whereIn('id_toko', $role);
        }

        $row = $builder->get()->getRow();

        // 2. Customer Metrics (Newly created today)
        $custBuilder = $this->db->table('customer');
        $custBuilder->where('created_at >=', $start)
            ->where('created_at <=', $end);

        $newCustomers = $custBuilder->countAllResults();

        return [
            'revenue' => (float) ($row->revenue ?? 0),
            'transactions' => (int) ($row->transactions ?? 0),
            'gross_profit' => (float) ($row->gross_profit ?? 0),
            'new_customers' => (int) ($newCustomers ?? 0)
        ];
    }

    /**
     * Logic for growth percentage
     */
    private function calculatePercentage($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        $growth = (($current - $previous) / abs($previous)) * 100;
        return round($growth, 1);
    }
}
