<?php

namespace App\Controllers;

use App\Models\JsonResponse;
use App\Models\JasaServiceModel;
use CodeIgniter\RESTful\ResourceController;

class JasaServiceController extends ResourceController
{
    protected $jasaServiceModel;
    protected $jsonResponse;

    public function __construct()
    {
        $this->jasaServiceModel = new JasaServiceModel();
        $this->jsonResponse = new JsonResponse();
    }

    // GET: List all services with filters, search, pagination
    public function index()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $allowedSortFields = ['id', 'nama_jasa', 'kategori', 'harga', 'komisi', 'created_at', 'id_toko'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'jasa_service.id';
        } else {
            $sortBy = 'jasa_service.' . $sortBy;
        }

        $sortMethod = strtolower($this->request->getGet('sortMethod') ?? 'desc');
        if (!in_array($sortMethod, ['asc', 'desc'])) {
            $sortMethod = 'desc';
        }

        $search = trim($this->request->getGet('search') ?? '');
        $kategori = trim($this->request->getGet('kategori') ?? '');
        $idToko = $this->request->getGet('id_toko');
        $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
        $page = max((int) ($this->request->getGet('page') ?: 1), 1);
        $offset = ($page - 1) * $limit;

        $builder = $this->jasaServiceModel
            ->select('jasa_service.*, toko.toko_name')
            ->join('toko', 'toko.id = jasa_service.id_toko', 'left');

        if (!empty($search)) {
            $builder->like('jasa_service.nama_jasa', $search, 'both');
        }

        if (!empty($kategori)) {
            $builder->where('jasa_service.kategori', $kategori);
        }

        if ($idToko !== null && $idToko !== '') {
            if ($this->request->getGet('strict_toko') === 'true') {
                $builder->where('jasa_service.id_toko', (int) $idToko);
            } else {
                $builder->groupStart()
                    ->where('jasa_service.id_toko', (int) $idToko)
                    ->orWhere('jasa_service.id_toko', null)
                    ->groupEnd();
            }
        }

        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);
    }

    // GET: Formatted dropdown list
    public function dropdown()
    {
        $idToko = $this->request->getGet('id_toko');
        $builder = $this->jasaServiceModel->where('deleted_at', null);

        if ($idToko !== null && $idToko !== '') {
            $builder->groupStart()
                ->where('id_toko', (int) $idToko)
                ->orWhere('id_toko', null)
                ->groupEnd();
        }

        $result = $builder->orderBy('nama_jasa', 'asc')->get()->getResult();

        $formattedResult = array_map(function ($row) {
            return [
                'label' => $row->nama_jasa . ' (' . $row->kategori . ') - Rp' . number_format($row->harga, 0, ',', '.'),
                'value' => $row->id,
                'harga' => (float) $row->harga,
                'komisi' => (float) $row->komisi,
                'kategori' => $row->kategori,
                'id_toko' => $row->id_toko
            ];
        }, $result);

        return $this->jsonResponse->oneResp('', $formattedResult, 200);
    }

    // GET: Single service item details
    public function show($id = null)
    {
        $service = $this->jasaServiceModel
            ->select('jasa_service.*, toko.toko_name')
            ->join('toko', 'toko.id = jasa_service.id_toko', 'left')
            ->find($id);

        if (!$service) {
            return $this->jsonResponse->error("Jasa service dengan ID $id tidak ditemukan", 404);
        }

        return $this->jsonResponse->oneResp('successfully', $service, 200);
    }

    // POST: Create a new service item
    public function create()
    {
        $token = $this->request->user;
        $input = $this->request->getJSON();

        $data = [
            'nama_jasa'  => $input->nama_jasa ?? null,
            'kategori'   => $input->kategori ?? null,
            'harga'      => isset($input->harga) ? (float) $input->harga : 0.00,
            'komisi'     => isset($input->komisi) ? (float) $input->komisi : 0.00,
            'id_toko'    => (isset($input->id_toko) && $input->id_toko !== '') ? (int) $input->id_toko : null,
            'created_by' => $token['user_id'] ?? null,
        ];

        if (!$this->jasaServiceModel->insert($data)) {
            return $this->jsonResponse->error($this->jasaServiceModel->errors(), 400);
        }

        return $this->jsonResponse->oneResp('Jasa service berhasil ditambahkan', '', 201);
    }

    // PUT: Update an existing service item
    public function update($id = null)
    {
        $token = $this->request->user;
        $input = $this->request->getJSON();

        $existing = $this->jasaServiceModel->find($id);
        if (!$existing) {
            return $this->jsonResponse->error("Jasa service dengan ID $id tidak ditemukan", 404);
        }

        $data = [
            'nama_jasa'  => $input->nama_jasa ?? $existing['nama_jasa'],
            'kategori'   => $input->kategori ?? $existing['kategori'],
            'harga'      => isset($input->harga) ? (float) $input->harga : (float) $existing['harga'],
            'komisi'     => isset($input->komisi) ? (float) $input->komisi : (float) $existing['komisi'],
            'id_toko'    => property_exists($input, 'id_toko') ? (($input->id_toko !== '') ? (int)$input->id_toko : null) : $existing['id_toko'],
            'updated_by' => $token['user_id'] ?? null,
        ];

        if (!$this->jasaServiceModel->update($id, $data)) {
            return $this->jsonResponse->error($this->jasaServiceModel->errors(), 400);
        }

        return $this->jsonResponse->oneResp('Jasa service berhasil diperbarui', '', 200);
    }

    // DELETE: Soft delete a service item
    public function delete($id = null)
    {
        $existing = $this->jasaServiceModel->find($id);
        if (!$existing) {
            return $this->jsonResponse->error("Jasa service dengan ID $id tidak ditemukan", 404);
        }

        $this->jasaServiceModel->delete($id);
        return $this->jsonResponse->oneResp('Jasa service berhasil dihapus', '', 200);
    }

    // GET: Technician commission report
    public function reportKomisi()
    {
        $db = \Config\Database::connect();
        
        $idToko = $this->request->getGet('id_toko');
        $teknisiId = $this->request->getGet('teknisi_id');
        $dateStart = $this->request->getGet('date_start');
        $dateEnd = $this->request->getGet('date_end');
        $search = trim($this->request->getGet('search') ?? '');
        
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $allowedSortFields = ['id', 'invoice', 'nama_teknisi', 'nama_jasa', 'harga_jasa', 'komisi_nominal', 'created_at', 'status'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'tk.id';
        } else {
            if ($sortBy === 'invoice') {
                $sortBy = 't.invoice';
            } elseif ($sortBy === 'nama_teknisi') {
                $sortBy = 'u.name';
            } elseif ($sortBy === 'nama_jasa') {
                $sortBy = 'js.nama_jasa';
            } elseif ($sortBy === 'status') {
                $sortBy = 't.status';
            } else {
                $sortBy = 'tk.' . $sortBy;
            }
        }

        $sortMethod = strtolower($this->request->getGet('sortMethod') ?? 'desc');
        if (!in_array($sortMethod, ['asc', 'desc'])) {
            $sortMethod = 'desc';
        }

        $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
        $page = max((int) ($this->request->getGet('page') ?: 1), 1);
        $offset = ($page - 1) * $limit;

        $builder = $db->table('teknisi_komisi tk')
            ->select('tk.*, t.invoice, t.status as transaction_status, t.date_time as transaction_date, u.name as nama_teknisi, js.nama_jasa')
            ->join('transaction t', 'tk.transaction_id = t.id', 'inner')
            ->join('users u', 'tk.teknisi_id = u.user_id', 'left')
            ->join('jasa_service js', 'tk.jasa_service_id = js.id', 'left')
            ->where('tk.tenant_id', \App\Libraries\TenantContext::id());

        $statusFilter = $this->request->getGet('status');
        if (!empty($statusFilter)) {
            $builder->whereIn('t.status', explode(',', $statusFilter));
        } else {
            $builder->whereIn('t.status', ['PAID', 'SUCCESS']);
        }

        if ($idToko !== null && $idToko !== '') {
            $builder->where('tk.id_toko', (int) $idToko);
        }

        if ($teknisiId !== null && $teknisiId !== '') {
            $builder->where('tk.teknisi_id', (int) $teknisiId);
        }

        if ($dateStart && $dateEnd) {
            $builder->where('t.date_time >=', "{$dateStart} 00:00:00");
            $builder->where('t.date_time <=', "{$dateEnd} 23:59:59");
        } elseif ($dateStart) {
            $builder->where('t.date_time >=', "{$dateStart} 00:00:00");
        } elseif ($dateEnd) {
            $builder->where('t.date_time <=', "{$dateEnd} 23:59:59");
        }

        if (!empty($search)) {
            $builder->groupStart()
                ->like('t.invoice', $search, 'both')
                ->orLike('u.name', $search, 'both')
                ->orLike('js.nama_jasa', $search, 'both')
                ->groupEnd();
        }

        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        return $this->jsonResponse->multiResp('Commission report fetched successfully', $result, $total_data, $total_page, $page, $limit, 200);
    }
}
