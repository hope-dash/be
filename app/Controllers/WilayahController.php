<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;
use App\Models\ProvinceModel;
use App\Models\CityModel;
use App\Models\DistrictModel;
use App\Models\VillageModel;

class WilayahController extends ResourceController
{
    protected $jsonResponse;
    protected $provinceModel;
    protected $cityModel;
    protected $districtModel;
    protected $villageModel;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->provinceModel = new ProvinceModel();
        $this->cityModel = new CityModel();
        $this->districtModel = new DistrictModel();
        $this->villageModel = new VillageModel();
    }

    /**
     * Get list of provinces
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function getProvinces()
    {
        try {
            $cacheKey = 'wilayah_provinces';
            $provinces = cache()->get($cacheKey);

            if ($provinces === null) {
                $provinces = $this->provinceModel->findAll();
                cache()->save($cacheKey, $provinces, 2592000); // 30 days
            }

            return $this->jsonResponse->multiResp('', $provinces, count($provinces), 1, 1, count($provinces), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Get cities/regencies by province code or Name
     * 
     * @param string $provinceCode (Code or Name)
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function getCitiesByProvince($provinceCode)
    {
        try {
            $cacheKey = 'wilayah_cities_' . md5($provinceCode);
            $cities = cache()->get($cacheKey);

            if ($cities !== null) {
                return $this->jsonResponse->multiResp('', $cities, count($cities), 1, 1, count($cities), 200);
            }

            // Support lookup by Province Name if it doesn't look like a code
            if (!is_numeric($provinceCode)) {
                $decodedName = urldecode($provinceCode);
                $province = $this->provinceModel->like('name', $decodedName)->first();

                if ($province) {
                    $provinceCode = $province['code'];
                } else {
                    return $this->jsonResponse->error('Province name not found', 404);
                }
            }

            $cities = $this->cityModel->where('province_code', $provinceCode)->findAll();

            if (empty($cities)) {
                return $this->jsonResponse->error('Cities not found for this province', 404);
            }

            cache()->save($cacheKey, $cities, 2592000); // 30 days

            return $this->jsonResponse->multiResp('', $cities, count($cities), 1, 1, count($cities), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Get districts/kecamatan by city/regency code
     * 
     * @param string $cityCode
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function getDistrictsByCity($cityCode)
    {
        try {
            $cacheKey = 'wilayah_districts_' . md5($cityCode);
            $districts = cache()->get($cacheKey);

            if ($districts !== null) {
                return $this->jsonResponse->multiResp('', $districts, count($districts), 1, 1, count($districts), 200);
            }

            $districts = $this->districtModel->where('regency_code', $cityCode)->findAll();

            if (empty($districts)) {
                return $this->jsonResponse->error('Districts not found for this city', 404);
            }

            cache()->save($cacheKey, $districts, 2592000); // 30 days

            return $this->jsonResponse->multiResp('', $districts, count($districts), 1, 1, count($districts), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Get villages/kelurahan by district code
     * 
     * @param string $districtCode
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function getVillagesByDistrict($districtCode)
    {
        try {
            $cacheKey = 'wilayah_villages_' . md5($districtCode);
            $villages = cache()->get($cacheKey);

            if ($villages !== null) {
                return $this->jsonResponse->multiResp('', $villages, count($villages), 1, 1, count($villages), 200);
            }

            $villages = $this->villageModel->where('district_code', $districtCode)->findAll();

            if (empty($villages)) {
                return $this->jsonResponse->error('Villages not found for this district', 404);
            }

            cache()->save($cacheKey, $villages, 2592000); // 30 days

            return $this->jsonResponse->multiResp('', $villages, count($villages), 1, 1, count($villages), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Search provinces by name
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function searchProvinces()
    {
        try {
            $search = trim($this->request->getGet('q'));
            $cacheKey = 'wilayah_search_provinces_' . md5($search);
            $provinces = cache()->get($cacheKey);

            if ($provinces !== null) {
                return $this->jsonResponse->multiResp('', $provinces, count($provinces), 1, 1, count($provinces), 200);
            }

            if ($search) {
                $provinces = $this->provinceModel->like('name', $search)->findAll();
            } else {
                $provinces = $this->provinceModel->findAll();
            }

            cache()->save($cacheKey, $provinces, 2592000); // 30 days

            return $this->jsonResponse->multiResp('', $provinces, count($provinces), 1, 1, count($provinces), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Search cities by name
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function searchCities()
    {
        try {
            $search = trim($this->request->getGet('q' ?? ''));
            $provinceCode = $this->request->getGet('province_code');
            $cacheKey = 'wilayah_search_cities_' . md5($search . $provinceCode);
            $cities = cache()->get($cacheKey);

            if ($cities !== null) {
                return $this->jsonResponse->multiResp('', $cities, count($cities), 1, 1, count($cities), 200);
            }

            $query = $this->cityModel;

            if ($provinceCode) {
                $query = $query->where('province_code', $provinceCode);
            }

            if ($search) {
                $query = $query->like('name', $search);
            }

            $cities = $query->findAll();

            cache()->save($cacheKey, $cities, 2592000); // 30 days

            return $this->jsonResponse->multiResp('', $cities, count($cities), 1, 1, count($cities), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Search districts by name
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function searchDistricts()
    {
        try {
            $search = trim($this->request->getGet('q' ?? ''));
            $regencyCode = $this->request->getGet('regency_code');
            $cacheKey = 'wilayah_search_districts_' . md5($search . $regencyCode);
            $districts = cache()->get($cacheKey);

            if ($districts !== null) {
                return $this->jsonResponse->multiResp('', $districts, count($districts), 1, 1, count($districts), 200);
            }

            $query = $this->districtModel;

            if ($regencyCode) {
                $query = $query->where('regency_code', $regencyCode);
            }

            if ($search) {
                $query = $query->like('name', $search);
            }

            $districts = $query->findAll();

            cache()->save($cacheKey, $districts, 2592000); // 30 days

            return $this->jsonResponse->multiResp('', $districts, count($districts), 1, 1, count($districts), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Search villages by name
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function searchVillages()
    {
        try {
            $search = trim($this->request->getGet('q') ?? '');
            $districtCode = $this->request->getGet('district_code');
            $cacheKey = 'wilayah_search_villages_' . md5($search . $districtCode);
            $villages = cache()->get($cacheKey);

            if ($villages !== null) {
                return $this->jsonResponse->multiResp('', $villages, count($villages), 1, 1, count($villages), 200);
            }

            $query = $this->villageModel;

            if ($districtCode) {
                $query = $query->where('district_code', $districtCode);
            }

            if ($search) {
                $query = $query->like('name', $search);
            }

            // Villages can be huge, you might want to limit this if q is empty
            if (!$search && !$districtCode) {
                $villages = $query->limit(100)->findAll();
            } else {
                $villages = $query->findAll();
            }

            cache()->save($cacheKey, $villages, 2592000); // 30 days

            return $this->jsonResponse->multiResp('', $villages, count($villages), 1, 1, count($villages), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
