<?php

use App\Controllers\TiktokController;
use App\Libraries\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class TiktokSystemPriceTest extends CIUnitTestCase
{
    private $priceDb;

    protected function setUp(): void
    {
        parent::setUp();
        helper('log');
        TenantContext::set(['id' => 1]);
        $this->priceDb = Database::connect('tests', false);
        $schemas = [
            'toko' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, deleted_at TEXT',
            'product' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, id_barang TEXT, harga_jual REAL, harga_modal REAL, tiktok_product_id TEXT, deleted_at TEXT',
            'transaction' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice TEXT, amount REAL, actual_total REAL, total_payment REAL, status TEXT, id_toko INTEGER, date_time TEXT, is_service INTEGER, source TEXT, pengiriman TEXT, biaya_pengiriman REAL, total_modal REAL, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'sales_product' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, id_transaction INTEGER, actual_per_piece REAL, actual_total REAL, kode_barang TEXT, jumlah INTEGER, harga_system REAL, harga_jual REAL, total REAL, modal_system REAL, total_modal REAL, is_service INTEGER',
            'transaction_meta' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, transaction_id INTEGER, key TEXT, value TEXT',
            'stock' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, id_barang TEXT, id_toko INTEGER, stock INTEGER, barang_cacat INTEGER',
            'stock_ledgers' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, id_barang TEXT, id_toko INTEGER, qty INTEGER, balance INTEGER, reference_type TEXT, reference_id INTEGER, description TEXT, created_at TEXT, updated_at TEXT',
            'log_aktivitas' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, action_type TEXT, target_table TEXT, target_id INTEGER, description TEXT, detail TEXT, created_at TEXT',
            'journals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, id_toko INTEGER, reference_type TEXT, reference_id INTEGER, reference_no TEXT, date TEXT, description TEXT, created_at TEXT, updated_at TEXT, total_debit REAL, total_credit REAL',
            'journal_items' => 'id INTEGER PRIMARY KEY, journal_id INTEGER, account_id INTEGER, debit REAL, credit REAL, created_at TEXT',
            'accounts' => 'id INTEGER PRIMARY KEY, code TEXT, base_code TEXT, id_toko INTEGER',
        ];
        foreach ($schemas as $table => $columns) {
            $this->priceDb->query('CREATE TABLE ' . $this->priceDb->protectIdentifiers($this->priceDb->prefixTable($table)) . ' (' . $columns . ')');
        }
        $this->priceDb->table('toko')->insert(['id' => 1, 'tenant_id' => 1]);
        $this->priceDb->table('product')->insert(['id' => 1, 'tenant_id' => 1, 'id_barang' => 'LB175919', 'harga_jual' => 150000, 'harga_modal' => 84600]);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        $this->priceDb->close();
        parent::tearDown();
    }

    private function importOrder(string $status, string $sku = 'LB175919'): TiktokController
    {
        $controller = new class extends TiktokController {
            public function makeTiktokRequest($idToko, $method, $path, $params = [], $body = [])
            {
                return ['data' => ['orders' => []]];
            }
        };
        $order = [
            'id' => '585591543272670970', 'status' => $status,
            'payment' => ['sub_total' => 452000, 'total_amount' => 452000],
            'line_items' => [
                ['seller_sku' => $sku, 'sale_price' => 226000, 'original_price' => 250000, 'quantity' => 1],
                ['seller_sku' => $sku, 'sale_price' => 226000, 'original_price' => 250000, 'quantity' => 1],
            ],
        ];
        (new ReflectionMethod(TiktokController::class, 'syncTiktokOrder'))->invoke($controller, 1, $order);
        return $controller;
    }

    public function testNewInvoiceUsesLocalPriceAndMarketplaceSalePrice(): void
    {
        $this->importOrder('UNPAID');
        $rows = $this->priceDb->table('sales_product')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertEquals(150000, $rows[0]['harga_system']);
        $this->assertEquals(226000, $rows[0]['harga_jual']);
        $this->assertEquals(452000, $rows[0]['total']);
        $this->assertEquals(2, $rows[0]['jumlah']);
    }

    public function testCancelledInvoiceAlsoUsesLocalPrice(): void
    {
        $this->importOrder('CANCELLED');
        $row = $this->priceDb->table('sales_product')->get()->getRowArray();
        $this->assertEquals(150000, $row['harga_system']);
        $this->assertEquals(226000, $row['harga_jual']);
    }

    public function testInvoiceFixPreservesPriceSnapshotAfterMasterPriceChanges(): void
    {
        $controller = $this->importOrder('UNPAID');
        $this->priceDb->table('product')->where('id', 1)->update(['harga_jual' => 175000]);
        $response = $controller->fixTiktokInvoice(1);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $row = $this->priceDb->table('sales_product')->get()->getRowArray();
        $this->assertEquals(150000, $row['harga_system']);
        $this->assertEquals(226000, $row['harga_jual']);
    }

    public function testUnmappedProductDoesNotUseMarketplaceAsSystemPrice(): void
    {
        $this->importOrder('CANCELLED', 'UNKNOWN');
        $row = $this->priceDb->table('sales_product')->get()->getRowArray();
        $this->assertEquals(0, $row['harga_system']);
        $this->assertEquals(226000, $row['harga_jual']);
    }
}
