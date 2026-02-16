<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\TransactionModel;
use App\Models\TransactionMetaModel;
use App\Models\TransactionPaymentModel;

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
        $filename = 'Invoice-' . ($transaction['invoice_number'] ?? 'INV-' . str_pad($id, 6, '0', STR_PAD_LEFT)) . '.pdf';

        // Output PDF for download
        return $dompdf->stream($filename, ['Attachment' => true]);
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
            'tempDir' => WRITEPATH . 'cache'  // Use writable directory to avoid permission issues
        ]);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);

        // Generate filename
        $filename = 'Invoice-' . ($transaction['invoice_number'] ?? 'INV-' . str_pad($id, 6, '0', STR_PAD_LEFT)) . '.pdf';

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
        $filename = 'Receipt-' . ($transaction['invoice_number'] ?? 'RCP-' . str_pad($id, 6, '0', STR_PAD_LEFT)) . '.pdf';

        // Output PDF for download
        return $dompdf->stream($filename, ['Attachment' => true]);
    }

    private function getTransactionData($id)
    {
        $db = \Config\Database::connect();

        // Get Transaction with Toko info
        $transaction = $this->transactionModel
            ->select('transaction.*, toko.toko_name, toko.alamat as toko_alamat, toko.phone_number as toko_phone, toko.email_toko, toko.image_logo as toko_logo, toko.bank, toko.nama_pemilik, toko.nomer_rekening')
            ->join('toko', 'transaction.id_toko = toko.id', 'left')
            ->find($id);

        if (!$transaction) {
            return null;
        }

        // Get All Metadata
        $metas = $this->transactionMetaModel->where('transaction_id', $id)->findAll();
        $metaMap = [];
        foreach ($metas as $m) {
            $metaMap[$m['key']] = $m['value'];
        }
        $transaction['meta'] = $metaMap;

        // Get Items (Sales Product)
        $items = $db->table('sales_product sp')
            ->select("
                sp.*,
                p.nama_barang,
                mb.nama_model,
                s.seri,
                CONCAT(COALESCE(p.nama_barang,''), ' ', COALESCE(mb.nama_model,''), ' ', COALESCE(s.seri,'')) as nama_lengkap_barang
            ")
            ->join('product p', 'sp.kode_barang = p.id_barang', 'left')
            ->join('model_barang mb', 'p.id_model_barang = mb.id', 'left')
            ->join('seri s', 'p.id_seri_barang = s.id', 'left')
            ->where('sp.id_transaction', $id)
            ->get()
            ->getResultArray();

        $transaction['items'] = $items;

        // Get Payments
        $payments = $this->paymentModel
            ->where('transaction_id', $id)
            ->orderBy('paid_at', 'DESC')
            ->findAll();

        $transaction['payments'] = $payments;

        // Get Customer info if exists
        if (!empty($transaction['id_customer'])) {
            $customer = $db->table('customer')
                ->select('nama_customer, phone_number, alamat')
                ->where('id', $transaction['id_customer'])
                ->get()
                ->getRowArray();
            $transaction['customer'] = $customer;
        }

        return $transaction;
    }
}

