<?php

use App\Controllers\ProductController;
use App\Libraries\TenantContext;
use App\Models\ImageModel;
use App\Models\JsonResponse;
use App\Models\ProductModel;
use App\Models\StockModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Database;

final class ProductCacatFilterTest extends CIUnitTestCase
{
    private $productDb;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(['id' => 1]);
        $this->productDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $schemas = [
            'product' => 'id INTEGER, id_barang TEXT, tenant_id INTEGER, notes TEXT, nama_barang TEXT, harga_modal REAL, harga_jual REAL, harga_jual_toko REAL, description TEXT, id_model_barang INTEGER, id_seri_barang INTEGER, suplier TEXT, dropship INTEGER, berat REAL, deleted_at TEXT',
            'stock' => 'id INTEGER, id_barang TEXT, tenant_id INTEGER, id_toko INTEGER, stock INTEGER, barang_cacat INTEGER, dropship INTEGER, tiktok_product_id TEXT, product_tiktok_status TEXT, product_tokopedia_status TEXT',
            'toko' => 'id INTEGER, tenant_id INTEGER, toko_name TEXT',
            'model_barang' => 'id INTEGER, nama_model TEXT',
            'seri' => 'id INTEGER, seri TEXT',
            'sales_product' => 'kode_barang TEXT, jumlah INTEGER, id_transaction INTEGER, tenant_id INTEGER',
            'transaction' => 'id INTEGER, id_toko INTEGER, status TEXT',
            'stock_transfer_item' => 'kode_barang TEXT, transfer_id INTEGER, qty INTEGER',
            'stock_transfer' => 'id INTEGER, source_toko_id INTEGER, status TEXT, deleted_at TEXT, tenant_id INTEGER',
            'pembelian_detail' => 'kode_barang TEXT, pembelian_id INTEGER, jumlah INTEGER, tenant_id INTEGER',
            'pembelian' => 'id INTEGER, id_toko INTEGER, status TEXT, deleted_at TEXT',
            'image' => 'id INTEGER, kode INTEGER, url TEXT, type TEXT, tenant_id INTEGER, "index" INTEGER',
        ];
        foreach ($schemas as $table => $columns) {
            $this->productDb->query('CREATE TABLE "' . $table . '" (' . $columns . ')');
        }
        $this->productDb->table('toko')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'toko_name' => 'BEKASI'],
            ['id' => 3, 'tenant_id' => 1, 'toko_name' => 'yenyen'],
        ]);
        for ($id = 1; $id <= 14; $id++) {
            $this->productDb->table('product')->insert([
                'id' => $id, 'id_barang' => 'P' . $id, 'tenant_id' => 1, 'nama_barang' => 'Product ' . $id,
            ]);
            $this->productDb->table('stock')->insertBatch([
                ['id_barang' => 'P' . $id, 'tenant_id' => 1, 'id_toko' => 1, 'stock' => 0, 'barang_cacat' => $id <= 12 ? 1 : 0],
                ['id_barang' => 'P' . $id, 'tenant_id' => 1, 'id_toko' => 3, 'stock' => 0, 'barang_cacat' => $id <= 12 ? -1 : ($id === 13 ? 1 : null)],
            ]);
        }
        // A different tenant's defective stock must not qualify P14.
        $this->productDb->table('stock')->insert([
            'id_barang' => 'P14', 'tenant_id' => 2, 'id_toko' => 3, 'stock' => 0, 'barang_cacat' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        $this->productDb->close();
        parent::tearDown();
    }

    private function products(array $overrides = []): array
    {
        $request = new IncomingRequest(new App(), new URI('http://example.com'), null, new UserAgent());
        $request->setGlobal('get', array_replace([
            'page' => 1, 'limit' => 10, 'stock' => 'cacat', 'sortMethod' => 'desc',
        ], $overrides));
        $controller = new class($this->productDb, $request) extends ProductController {
            public function __construct($db, $request)
            {
                $this->db = $db;
                $this->request = $request;
                $this->productModel = new ProductModel($db);
                $this->stockModel = new StockModel($db);
                $this->imageModel = new ImageModel($db);
                $this->jsonResponse = new JsonResponse();
            }
        };
        return json_decode($controller->getAllProduct()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testGlobalFilterIncludesEveryStoreBeforePagination(): void
    {
        $response = $this->products();
        $this->assertTrue($response['status']);
        $this->assertSame(13, $response['page']['total_data']);
        $this->assertEquals(2, $response['page']['total_page']);
        $this->assertCount(10, $response['data']);
        $this->assertSame('P13', $response['data'][0]['kode_barang']);
        foreach ($response['data'] as $row) {
            $this->assertSame(1, $row['total_cacat']);
        }
        $secondPage = $this->products(['page' => 2]);
        $this->assertSame(['P3', 'P2', 'P1'], array_column($secondPage['data'], 'kode_barang'));
    }

    public function testStoreFilterIncludesDamagedStockEvenWithNoReadyStock(): void
    {
        $response = $this->products(['id_toko' => '1', 'only_toko' => 'true']);
        $this->assertSame(12, $response['page']['total_data']);
        foreach ($response['data'] as $row) {
            $this->assertSame(1, $row['total_cacat']);
            $this->assertSame([1], array_column($row['stock'], 'id_toko'));
        }
        $response = $this->products(['id_toko' => '3', 'only_toko' => 'true']);
        $this->assertSame(1, $response['page']['total_data']);
        $this->assertSame('P13', $response['data'][0]['kode_barang']);
    }
}
