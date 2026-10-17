<?php

use App\Controllers\LogAktivitasController;
use App\Libraries\TenantContext;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Database;

final class LogAktivitasFilterTest extends CIUnitTestCase
{
    private $logDb;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(['id' => 1]);
        $this->logDb = Database::connect('tests', false);
        $this->logDb->query('CREATE TABLE ' . $this->logDb->prefixTable('users') . ' (user_id INTEGER, name TEXT)');
        $this->logDb->query('CREATE TABLE ' . $this->logDb->prefixTable('log_aktivitas') . ' (id INTEGER, tenant_id INTEGER, user_id INTEGER, action_type TEXT, target_table TEXT, target_id TEXT, description TEXT, created_at TEXT)');
        $this->logDb->table('users')->insert(['user_id' => 1, 'name' => 'OC175823']);
        foreach ([
            [1, 1, 'MOVE_TO_CACAT', 'stock', '2026-10-18 10:00:00'],
            [2, 1, 'UPDATE', 'stock', '2026-10-18 10:00:00'],
            [3, 2, 'MOVE_TO_CACAT', 'stock', '2026-10-18 10:00:00'],
            [4, 1, 'MOVE_TO_CACAT', 'product', '2026-10-18 10:00:00'],
            [5, 1, 'MOVE_TO_CACAT', 'stock', '2026-09-01 10:00:00'],
        ] as [$id, $tenant, $action, $table, $createdAt]) {
            $this->logDb->table('log_aktivitas')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'user_id' => 1, 'action_type' => $action,
                'target_table' => $table, 'target_id' => (string) $id,
                'description' => 'Produk OC175823', 'created_at' => $createdAt,
            ]);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        $this->logDb->close();
        parent::tearDown();
    }

    private function logs(array $params): array
    {
        $request = new IncomingRequest(new App(), new URI('http://example.com'), null, new UserAgent());
        $request->setGlobal('get', $params);
        $controller = new class($request) extends LogAktivitasController {
            public function __construct($request)
            {
                parent::__construct();
                $this->request = $request;
            }
        };
        return json_decode($controller->index()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSearchRespectsActionAndTenantFilters(): void
    {
        $response = $this->logs(['action_type' => 'MOVE_TO_CACAT', 'search' => 'OC175823']);
        $this->assertSame(3, $response['page']['total_data']);
        $this->assertSame(['MOVE_TO_CACAT'], array_values(array_unique(array_column($response['data'], 'action_type'))));
        $this->assertSame([1], array_values(array_unique(array_column($response['data'], 'tenant_id'))));
    }

    public function testSearchRespectsOtherFiltersAndPagination(): void
    {
        $response = $this->logs([
            'action_type' => 'MOVE_TO_CACAT', 'search' => 'OC175823', 'target_table' => 'stock',
            'target_id' => '1', 'limit' => 1,
        ]);
        $this->assertSame(1, $response['page']['total_data']);
        $this->assertSame([1], array_column($response['data'], 'id'));
    }

    public function testSearchWithoutActionStaysWithinTenant(): void
    {
        $response = $this->logs(['search' => 'OC175823']);
        $this->assertSame(4, $response['page']['total_data']);
        $this->assertNotContains(2, array_column($response['data'], 'tenant_id'));
    }
}
