<?php

use App\Controllers\TransactionController;
use App\Libraries\TenantContext;
use App\Models\JsonResponse;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Database;

final class SalesProductTransferHoldTest extends CIUnitTestCase
{
    private $reportDb;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(['id' => 1]);
        $this->reportDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $schemas = [
            'sales_product' => 'id INTEGER, id_transaction INTEGER, kode_barang TEXT, jumlah INTEGER, modal_system REAL, total_modal REAL, actual_total REAL, tenant_id INTEGER',
            'transaction' => 'id INTEGER, tenant_id INTEGER, invoice TEXT, date_time TEXT, created_at TEXT, status TEXT, id_toko INTEGER',
            'product' => 'id_barang TEXT, tenant_id INTEGER, nama_barang TEXT, id_model_barang INTEGER, id_seri_barang INTEGER',
            'model_barang' => 'id INTEGER, nama_model TEXT',
            'seri' => 'id INTEGER, seri TEXT',
            'stock_transfer' => 'id INTEGER, tenant_id INTEGER, ref_id TEXT, date TEXT, created_at TEXT, updated_at TEXT, status TEXT, source_toko_id INTEGER, target_toko_id INTEGER, deleted_at TEXT',
            'stock_transfer_item' => 'id INTEGER, transfer_id INTEGER, kode_barang TEXT, qty INTEGER, harga_modal REAL',
        ];
        foreach ($schemas as $table => $columns) {
            $this->reportDb->query('CREATE TABLE "' . $table . '" (' . $columns . ')');
        }
        $this->reportDb->table('product')->insertBatch([
            ['id_barang' => 'A310', 'tenant_id' => 1, 'nama_barang' => 'Battery', 'id_model_barang' => 1, 'id_seri_barang' => 1],
            ['id_barang' => 'A310', 'tenant_id' => 2, 'nama_barang' => 'Other tenant', 'id_model_barang' => 1, 'id_seri_barang' => 1],
        ]);
        $this->reportDb->table('model_barang')->insert(['id' => 1, 'nama_model' => 'Model']);
        $this->reportDb->table('seri')->insert(['id' => 1, 'seri' => 'Series']);
        $this->reportDb->table('transaction')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'invoice' => 'INV-1', 'date_time' => '2026-10-01 10:00:00', 'created_at' => '2026-10-01 10:00:00', 'status' => 'WAITING_PAYMENT', 'id_toko' => 1],
            ['id' => 2, 'tenant_id' => 1, 'invoice' => 'INV-2', 'date_time' => '2026-10-01 10:00:00', 'created_at' => '2026-10-01 10:00:00', 'status' => 'PAID', 'id_toko' => 1],
        ]);
        foreach ([1, 2] as $id) {
            $this->reportDb->table('sales_product')->insert([
                'id' => $id, 'id_transaction' => $id, 'kode_barang' => 'A310', 'jumlah' => 1,
                'modal_system' => 60, 'total_modal' => 60, 'actual_total' => 100, 'tenant_id' => 1,
            ]);
        }
        foreach ([
            15 => [],
            16 => ['status' => 'APPROVED'],
            17 => ['status' => 'REJECTED'],
            18 => ['tenant_id' => 2],
            19 => ['source_toko_id' => 2],
            20 => ['date' => '2026-09-29'],
            21 => ['date' => '2026-10-31'],
            22 => ['deleted_at' => '2026-10-03 00:00:00'],
            23 => ['status' => 'WAITING'],
        ] as $id => $overrides) {
            $this->reportDb->table('stock_transfer')->insert(array_replace([
                'id' => $id, 'tenant_id' => 1, 'ref_id' => 'TRF-' . $id, 'date' => '2026-10-02',
                'created_at' => '2026-10-02 10:00:00', 'updated_at' => null, 'status' => 'PENDING',
                'source_toko_id' => 1, 'target_toko_id' => 2, 'deleted_at' => null,
            ], $overrides));
            $this->reportDb->table('stock_transfer_item')->insert([
                'id' => $id + 100, 'transfer_id' => $id, 'kode_barang' => 'A310', 'qty' => 3, 'harga_modal' => 60,
            ]);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        $this->reportDb->close();
        parent::tearDown();
    }

    private function report(array $overrides = []): array
    {
        $params = array_replace([
            'id_toko' => '1', 'date_start' => '2026-09-30', 'date_end' => '2026-10-30',
            'search' => 'A310', 'status' => 'WAITING_PAYMENT', 'page' => 1, 'limit' => 10,
        ], $overrides);
        $request = new IncomingRequest(new App(), new URI('http://example.com'), null, new UserAgent());
        $request->setGlobal('get', $params);
        $controller = new class($this->reportDb, $request) extends TransactionController {
            public function __construct($db, $request)
            {
                $this->db = $db;
                $this->request = $request;
                $this->jsonResponse = new JsonResponse();
            }
        };
        return json_decode($controller->listSalesProductWithTransactionBaru()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testWaitingPaymentIncludesOnlyMatchingTransferHolds(): void
    {
        $response = $this->report();
        $this->assertSame(3, $response['page']['total_data']);
        $rows = $response['data']['result'];
        $this->assertSame(['WAITING_PAYMENT', 'Hold Transfer Stock', 'Hold Transfer Stock'], array_column($rows, 'status'));
        $this->assertSame([null, 15, 23], array_column($rows, 'transfer_id'));
        $this->assertSame(3, $rows[1]['jumlah']);
        $this->assertNull($rows[1]['id_transaction']);
        $this->assertSame('TRF-15', $rows[1]['invoice']);
        $this->assertSame('Battery', $rows[1]['nama_barang']);
        $this->assertSame(['total_modal' => 60, 'total_actual' => 100], $response['data']['sum']);
    }

    public function testCombinedRowsArePaginatedAndSortedBeforeResponse(): void
    {
        $response = $this->report(['limit' => 1, 'page' => 2, 'sortBy' => 'sp.jumlah', 'sortMethod' => 'desc']);
        $this->assertSame(3, $response['page']['total_data']);
        $this->assertEquals(3, $response['page']['total_page']);
        $this->assertCount(1, $response['data']['result']);
        $this->assertSame('Hold Transfer Stock', $response['data']['result'][0]['status']);
        $this->assertSame(3, $response['data']['result'][0]['jumlah']);
    }

    public function testPaidAndUnfilteredReportsExcludeTransfers(): void
    {
        foreach (['paid' => 1, '' => 2] as $status => $total) {
            $response = $this->report(['status' => $status]);
            $this->assertSame($total, $response['page']['total_data']);
            $this->assertNotContains('Hold Transfer Stock', array_column($response['data']['result'], 'status'));
        }
    }

    public function testRoleLimitsTransfersBySourceStore(): void
    {
        $response = $this->report(['id_toko' => '', 'role' => '2']);
        $this->assertSame(1, $response['page']['total_data']);
        $this->assertSame(19, $response['data']['result'][0]['transfer_id']);
    }

    public function testSearchMatchesTransferReferenceAndHandlesEmptyResults(): void
    {
        $response = $this->report(['search' => 'TRF-15']);
        $this->assertSame(1, $response['page']['total_data']);
        $this->assertSame(15, $response['data']['result'][0]['transfer_id']);
        $response = $this->report(['search' => 'missing']);
        $this->assertSame(0, $response['page']['total_data']);
        $this->assertSame([], $response['data']['result']);
    }

    public function testUnpaidAliasAndMultipleStatusesIncludeTransfers(): void
    {
        foreach (['unpaid', 'PAID,WAITING_PAYMENT', ['WAITING_PAYMENT', 'PAID']] as $status) {
            $response = $this->report(['status' => $status]);
            $this->assertContains(15, array_column($response['data']['result'], 'transfer_id'));
        }
    }
}
