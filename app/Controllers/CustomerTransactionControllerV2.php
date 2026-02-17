<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\CartModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use App\Models\TransactionModel;
use App\Models\SalesProductModel;
use App\Models\TransactionMetaModel;
use App\Models\TransactionPaymentModel;
use App\Models\JsonResponse;
use CodeIgniter\API\ResponseTrait;

class CustomerTransactionControllerV2 extends ResourceController
{
    use ResponseTrait;

    protected $cartModel;
    protected $productModel;
    protected $stockModel;
    protected $transactionModel;
    protected $salesProductModel;
    protected $transactionMetaModel;
    protected $paymentModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        helper('log');
        $this->cartModel = new CartModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->transactionModel = new TransactionModel();
        $this->salesProductModel = new SalesProductModel();
        $this->transactionMetaModel = new TransactionMetaModel();
        $this->paymentModel = new TransactionPaymentModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // --- CART API ---

    public function getCart()
    {
        try {
            $customerId = $this->request->customer['id'];

            $items = $this->cartModel
                ->select('cart.*, product.nama_barang, product.harga_jual, model_barang.nama_model, seri.seri')
                ->join('product', 'product.id_barang = cart.id_barang', 'left')
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->where('customer_id', $customerId)
                ->findAll();

            $formattedItems = [];
            foreach ($items as $item) {
                $namaLengkap = trim(implode(' ', array_filter([
                    $item['nama_barang'],
                    $item['nama_model'] ?? '',
                    $item['seri'] ?? ''
                ])));

                $formattedItems[] = [
                    'id' => $item['id'],
                    'id_barang' => $item['id_barang'],
                    'nama_barang' => $item['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'harga_jual' => (int) $item['harga_jual'],
                    'jumlah' => (int) $item['jumlah'],
                    'id_toko' => $item['id_toko']
                ];
            }

            return $this->jsonResponse->oneResp('Cart items fetched', $formattedItems);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function saveCart()
    {
        try {
            $customerId = $this->request->customer['id'];
            $data = $this->request->getJSON();

            if (empty($data->id_barang) || empty($data->jumlah)) {
                return $this->jsonResponse->error("Product ID and quantity are required", 400);
            }

            $existing = $this->cartModel
                ->where('customer_id', $customerId)
                ->where('id_barang', $data->id_barang)
                ->first();

            if ($existing) {
                $this->cartModel->update($existing['id'], [
                    'jumlah' => $data->jumlah,
                    'id_toko' => $data->id_toko ?? $existing['id_toko']
                ]);
                $message = "Cart item updated";
            } else {
                $this->cartModel->insert([
                    'customer_id' => $customerId,
                    'id_barang' => $data->id_barang,
                    'jumlah' => $data->jumlah,
                    'id_toko' => $data->id_toko ?? null
                ]);
                $message = "Item added to cart";
            }

            return $this->jsonResponse->oneResp($message, [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function deleteCartItem($id = null)
    {
        try {
            $customerId = $this->request->customer['id'];
            $item = $this->cartModel->find($id);

            if (!$item || $item['customer_id'] != $customerId) {
                return $this->jsonResponse->error("Item not found in your cart", 404);
            }

            $this->cartModel->delete($id);
            return $this->jsonResponse->oneResp('Item removed from cart', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // --- CHECKOUT API ---

    public function checkout()
    {
        $data = $this->request->getJSON();
        $customer = $this->request->customer;
        $customerId = $customer['id'];

        $this->db->transStart();

        try {
            $items = $data->items ?? [];
            if (empty($items)) {
                throw new \Exception("Cart items are empty");
            }

            $grossAmount = 0;
            $totalModal = 0;
            $itemsProcessed = [];

            foreach ($items as $item) {
                $idBarang = $item->id_barang;
                $qty = $item->jumlah ?? $item->qty ?? 0;

                $product = $this->productModel->where('id_barang', $idBarang)->first();
                if (!$product) {
                    throw new \Exception("Product {$idBarang} not found");
                }

                $price = (float) $product['harga_jual'];

                // Apply customer discount
                if (!empty($customer['discount_type'])) {
                    if (strtolower($customer['discount_type']) === 'percentage') {
                        $price -= ($price * (float) $customer['discount_value']) / 100;
                    } else {
                        $price -= (float) $customer['discount_value'];
                    }
                }
                $price = max(0, $price);

                $itemTotal = $price * $qty;
                $grossAmount += $itemTotal;
                $totalModal += ($product['harga_modal'] * $qty);

                $itemsProcessed[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'price' => $price,
                    'modal' => $product['harga_modal'] * $qty
                ];
            }

            // Simple checkout - no PPN/Ongkir for now unless requested
            $grandTotal = $grossAmount + ($data->biaya_pengiriman ?? 0);

            $trxData = [
                'invoice' => 'CS-' . time() . '-' . $customerId,
                'id_toko' => $data->id_toko ?? null,
                'amount' => $grossAmount,
                'actual_total' => $grandTotal,
                'total_payment' => 0,
                'status' => 'WAITING_PAYMENT',
                'delivery_status' => 'NOT_READY',
                'discount_type' => 'FIXED',
                'discount_amount' => 0,
                'total_modal' => $totalModal,
                'created_by' => 0, // Customer created
                'date_time' => date('Y-m-d H:i:s'),
            ];

            $this->transactionModel->insert($trxData);
            $trxId = $this->transactionModel->getInsertID();

            $invoice = 'INV' . date('ymd') . $trxId;
            $this->transactionModel->update($trxId, ['invoice' => $invoice]);

            // Save Metadata
            $meta = [
                'customer_id' => $customerId,
                'customer_name' => $customer['nama_customer'],
                'customer_phone' => $customer['no_hp_customer'],
                'alamat' => $data->alamat ?? $customer['alamat'],
                'source' => 'CUSTOMER_APP',
                'biaya_pengiriman' => $data->biaya_pengiriman ?? 0
            ];

            foreach ($meta as $key => $val) {
                $this->transactionMetaModel->insert([
                    'transaction_id' => $trxId,
                    'key' => $key,
                    'value' => (string) $val
                ]);
            }

            // Save Items
            foreach ($itemsProcessed as $it) {
                $this->salesProductModel->insert([
                    'id_transaction' => $trxId,
                    'kode_barang' => $it['product']['id_barang'],
                    'jumlah' => $it['qty'],
                    'harga_system' => $it['product']['harga_jual'],
                    'harga_jual' => $it['price'],
                    'total' => $it['price'] * $it['qty'],
                    'modal_system' => $it['product']['harga_modal'],
                    'total_modal' => $it['modal'],
                    'actual_per_piece' => $it['price'],
                    'actual_total' => $it['price'] * $it['qty']
                ]);
            }

            // Clear Cart after checkout if successful
            $this->cartModel->where('customer_id', $customerId)->delete();

            $this->db->transComplete();

            return $this->jsonResponse->oneResp('Checkout successful', [
                'id' => $trxId,
                'invoice' => $invoice,
                'total_amount' => $grandTotal
            ], 201);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // --- PAYMENT API ---

    public function uploadPaymentProof()
    {
        try {
            $customerId = $this->request->customer['id'];
            $data = $this->request->getJSON();

            if (empty($data->transaction_id) || empty($data->image_url)) {
                return $this->jsonResponse->error("Transaction ID and proof image are required", 400);
            }

            $trx = $this->transactionModel->find($data->transaction_id);
            if (!$trx) {
                return $this->jsonResponse->error("Transaction not found", 404);
            }

            // Verify it belongs to this customer
            $meta = $this->transactionMetaModel
                ->where('transaction_id', $data->transaction_id)
                ->where('key', 'customer_id')
                ->where('value', (string) $customerId)
                ->first();

            if (!$meta) {
                return $this->jsonResponse->error("Unauthorized access to this transaction", 403);
            }

            $this->db->transStart();

            $this->paymentModel->insert([
                'transaction_id' => $data->transaction_id,
                'amount' => $trx['actual_total'],
                'payment_method' => 'TRANSFER',
                'status' => 'PENDING', // Waiting verification
                'paid_at' => date('Y-m-d H:i:s'),
                'image_url' => $data->image_url,
                'note' => $data->note ?? 'Payment from customer app'
            ]);

            $this->transactionModel->update($data->transaction_id, [
                'status' => 'WAITING_VERIFICATION'
            ]);

            $this->db->transComplete();

            return $this->jsonResponse->oneResp('Payment proof uploaded successfully. Waiting for verification.', [], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
