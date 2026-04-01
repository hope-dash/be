<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\TransactionModel;
use App\Models\TransactionMetaModel;
use App\Models\TransactionPaymentModel;
use App\Libraries\TenantContext;

class InvoiceController extends Controller
{
    protected $transactionModel;
    protected $transactionMetaModel;
    protected $paymentModel;

    public function __construct()
    {
        $this->transactionModel = new TransactionModel();
        $this->transactionMetaModel = new TransactionMetaModel();
        $this->paymentModel = new TransactionPaymentModel();
    }


    public function view($id = null)
    {
        if (!$id) {
            return redirect()->to('/');
        }

        $transaction = $this->getTransactionData($id);

        if (!$transaction) {
            return view('errors/html/error_404');
        }

        $data = [
            'transaction' => $transaction
        ];

        return view('invoice/view', $data);
    }

    public function downloadPdf($id = null)
    {
        if (!$id) {
            return redirect()->to('/');
        }

        $transaction = $this->getTransactionData($id);

        if (!$transaction) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Transaction not found']);
        }

        $data = [
            'transaction' => $transaction
        ];

        // Load PDF-optimized view (uses table layout instead of flexbox)
        $html = view('invoice/view_pdf', $data);

        // Initialize Dompdf with proper options
        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', realpath(FCPATH));

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);

        // Set paper size to A4 with proper dimensions
        $dompdf->setPaper('A4', 'portrait');

        // Render PDF
        $dompdf->render();

        // Generate filename
        $rawCustName = $transaction['meta']['customer_name'] ?? '';
        $customerSuffix = $rawCustName ? '-' . preg_replace('/[^A-Za-z0-9\-]/', '_', trim($rawCustName)) : '';
        $invNumber = $transaction['invoice_number'] ?? $transaction['invoice'] ?? 'INV-' . str_pad($id, 6, '0', STR_PAD_LEFT);
        $filename = $invNumber . ' - ' . $customerSuffix . '.pdf';

        // Output PDF for download using CI response to ensure filters (CORS) are applied
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($dompdf->output());
    }

    public function downloadPdfMpdf($id = null)
    {
        if (!$id) {
            return redirect()->to('/');
        }

        $transaction = $this->getTransactionData($id);

        if (!$transaction) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Transaction not found']);
        }

        $data = [
            'transaction' => $transaction
        ];

        // Load modern view (can use flexbox because mPDF supports it better)
        $html = view('invoice/view', $data);

        // Initialize mPDF with writable temp directory
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'tempDir' => WRITEPATH . 'cache' // Use writable directory to avoid permission issues
        ]);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);

        // Generate filename
        $rawCustName = $transaction['customer']['nama_customer'] ?? $transaction['meta']['customer_name'] ?? '';
        $customerSuffix = $rawCustName ? '-' . preg_replace('/[^A-Za-z0-9\-]/', '_', trim($rawCustName)) : '';
        $invNumber = $transaction['invoice_number'] ?? $transaction['invoice'] ?? 'INV-' . str_pad($id, 6, '0', STR_PAD_LEFT);
        $filename = 'Invoice-' . $invNumber . $customerSuffix . '.pdf';

        // Output PDF for download
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($mpdf->Output($filename, 'S'));
    }

    public function receipt($id = null)
    {
        if (!$id) {
            return redirect()->to('/');
        }

        $transaction = $this->getTransactionData($id);

        if (!$transaction) {
            return view('errors/html/error_404');
        }

        $data = [
            'transaction' => $transaction
        ];

        return view('invoice/receipt', $data);
    }

    public function downloadReceiptPdf($id = null)
    {
        if (!$id) {
            return redirect()->to('/');
        }

        $transaction = $this->getTransactionData($id);

        if (!$transaction) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Transaction not found']);
        }

        $data = [
            'transaction' => $transaction
        ];

        // Load view
        $html = view('invoice/receipt', $data);

        // Initialize Dompdf with proper options
        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', realpath(FCPATH));

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);

        // Set paper size to 80mm width with very long height to accommodate any content
        // 80mm = 226.77 points, 2000mm = 5669.29 points (very long to ensure content fits)
        $dompdf->setPaper([0, 0, 226.77, 5669.29], 'portrait');

        // Render PDF
        $dompdf->render();

        // Generate filename
        $rawCustName = $transaction['customer']['nama_customer'] ?? $transaction['meta']['customer_name'] ?? '';
        $customerSuffix = $rawCustName ? '-' . preg_replace('/[^A-Za-z0-9\-]/', '_', trim($rawCustName)) : '';
        $invNumber = $transaction['invoice_number'] ?? $transaction['invoice'] ?? 'RCP-' . str_pad($id, 6, '0', STR_PAD_LEFT);
        $filename = 'Receipt-' . $invNumber . $customerSuffix . '.pdf';

        // Output PDF for download using CI response to ensure filters (CORS) are applied
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($dompdf->output());
    }

    private function getTransactionData($id)
    {
        $db = \Config\Database::connect();

        // 1. Get Transaction with Toko info (Matches TransactionControllerV2::getDetail)
        $transaction = $this->transactionModel
            ->select('transaction.*, toko.toko_name, toko.alamat as toko_alamat, toko.phone_number as toko_phone, toko.image_logo as toko_logo, toko.bank, toko.nomer_rekening, toko.nama_pemilik')
            ->join('toko', 'transaction.id_toko = toko.id AND toko.tenant_id = transaction.tenant_id', 'left')
            ->find($id);

        if (!$transaction) {
            return null;
        }

        // 2. Get All Metadata
        $metas = $this->transactionMetaModel->where('transaction_id', $id)->findAll();
        $metaMap = [];
        foreach ($metas as $m) {
            $metaMap[$m['key']] = $m['value'];
        }

        // Resolve Regional Names if they are stored as IDs/Codes
        $regions = [
            'provinsi' => 'provincy',
            'kota_kabupaten' => 'kota_kabupaten',
            'kecamatan' => 'kecamatan',
            'kelurahan' => 'kelurahan'
        ];
        foreach ($regions as $key => $table) {
            if (isset($metaMap[$key]) && is_numeric($metaMap[$key]) && !empty($metaMap[$key])) {
                $regionalData = $db->table($table)->where('code', $metaMap[$key])->get()->getRowArray();
                if ($regionalData) {
                    $metaMap[$key] = $regionalData['name'];
                }
            }
        }

        $transaction['meta'] = $metaMap;

        // 3. Get Items (Sales Product)
        $items = $db->table('sales_product sp')
            ->select("
                sp.*,
                p.nama_barang,
                p.berat,
                mb.nama_model,
                s.seri,
                CONCAT(COALESCE(p.nama_barang,''), ' ', COALESCE(mb.nama_model,''), ' ', COALESCE(s.seri,'')) as nama_lengkap_barang
            ")
            ->join('product p', 'sp.kode_barang = p.id_barang AND p.tenant_id = sp.tenant_id', 'left')
            ->join('model_barang mb', 'p.id_model_barang = mb.id AND mb.tenant_id = sp.tenant_id', 'left')
            ->join('seri s', 'p.id_seri_barang = s.id AND s.tenant_id = sp.tenant_id', 'left')
            ->where('sp.id_transaction', $id)
            ->where('sp.tenant_id', TenantContext::id())
            ->get()
            ->getResultArray();

        $transaction['items'] = $items;

        // 4. Get Payments
        $payments = $this->paymentModel
            ->where('transaction_id', $id)
            ->orderBy('paid_at', 'DESC')
            ->findAll();

        $transaction['payments'] = $payments;

        // Add Customer info if exists (from id_customer)
        if (!empty($transaction['id_customer'])) {
            $customer = $db->table('customer')
                ->select('nama_customer, no_hp_customer as phone_number, alamat')
                ->where('id', $transaction['id_customer'])
                ->get()
                ->getRowArray();
            $transaction['customer'] = $customer;
        }

        return $transaction;
    }
}