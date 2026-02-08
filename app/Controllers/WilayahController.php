<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;

class WilayahController extends ResourceController
{
    protected $jsonResponse;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
    }

    /**
     * Get list of provinces
     * 
     * @return Response
     */
    public function getProvinces()
    {
        try {
            $provinces = $this->loadProvinces();
            return $this->jsonResponse->multiResp('', $provinces, count($provinces), 1, 1, count($provinces), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Get cities/regencies by province ID or Name
     * 
     * @param string $provinceId (ID or Name)
     * @return Response
     */
    public function getCitiesByProvince($provinceId)
    {
        try {
            // Support lookup by Province Name (if not numeric)
            if (!is_numeric($provinceId)) {
                $provinces = $this->loadProvinces();
                $decodedName = urldecode($provinceId);
                $foundId = null;
                
                foreach ($provinces as $province) {
                    if (strcasecmp($province['name'], $decodedName) === 0) {
                        $foundId = $province['id'];
                        break;
                    }
                }

                if ($foundId) {
                    $provinceId = $foundId;
                } else {
                    return $this->jsonResponse->error('Province name not found', 404);
                }
            }

            $cities = $this->loadCities($provinceId);
            
            if (empty($cities)) {
                return $this->jsonResponse->error('Cities not found for this province', 404);
            }

            return $this->jsonResponse->multiResp('', $cities, count($cities), 1, 1, count($cities), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Search provinces by name
     * 
     * @return Response
     */
    public function searchProvinces()
    {
        try {
            $search = $this->request->getGet('q');
            $provinces = $this->loadProvinces();

            if ($search) {
                $provinces = array_filter($provinces, function($province) use ($search) {
                    return stripos($province['name'], $search) !== false;
                });
                $provinces = array_values($provinces); // Re-index array
            }

            return $this->jsonResponse->multiResp('', $provinces, count($provinces), 1, 1, count($provinces), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Search cities by name
     * 
     * @return Response
     */
    public function searchCities()
    {
        try {
            $search = $this->request->getGet('q');
            $provinceId = $this->request->getGet('province_id');

            $allCities = [];
            $provinces = $this->loadProvinces();

            foreach ($provinces as $province) {
                if ($provinceId && $province['id'] != $provinceId) {
                    continue;
                }
                
                $cities = $this->loadCities($province['id']);
                foreach ($cities as $city) {
                    $city['province_name'] = $province['name'];
                    $allCities[] = $city;
                }
            }

            if ($search) {
                $allCities = array_filter($allCities, function($city) use ($search) {
                    return stripos($city['name'], $search) !== false;
                });
                $allCities = array_values($allCities);
            }

            return $this->jsonResponse->multiResp('', $allCities, count($allCities), 1, 1, count($allCities), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Load provinces data
     * 
     * @return array
     */
    private function loadProvinces()
    {
        return [
            ['id' => '11', 'name' => 'Aceh'],
            ['id' => '12', 'name' => 'Sumatera Utara'],
            ['id' => '13', 'name' => 'Sumatera Barat'],
            ['id' => '14', 'name' => 'Riau'],
            ['id' => '15', 'name' => 'Jambi'],
            ['id' => '16', 'name' => 'Sumatera Selatan'],
            ['id' => '17', 'name' => 'Bengkulu'],
            ['id' => '18', 'name' => 'Lampung'],
            ['id' => '19', 'name' => 'Kepulauan Bangka Belitung'],
            ['id' => '21', 'name' => 'Kepulauan Riau'],
            ['id' => '31', 'name' => 'DKI Jakarta'],
            ['id' => '32', 'name' => 'Jawa Barat'],
            ['id' => '33', 'name' => 'Jawa Tengah'],
            ['id' => '34', 'name' => 'DI Yogyakarta'],
            ['id' => '35', 'name' => 'Jawa Timur'],
            ['id' => '36', 'name' => 'Banten'],
            ['id' => '51', 'name' => 'Bali'],
            ['id' => '52', 'name' => 'Nusa Tenggara Barat'],
            ['id' => '53', 'name' => 'Nusa Tenggara Timur'],
            ['id' => '61', 'name' => 'Kalimantan Barat'],
            ['id' => '62', 'name' => 'Kalimantan Tengah'],
            ['id' => '63', 'name' => 'Kalimantan Selatan'],
            ['id' => '64', 'name' => 'Kalimantan Timur'],
            ['id' => '65', 'name' => 'Kalimantan Utara'],
            ['id' => '71', 'name' => 'Sulawesi Utara'],
            ['id' => '72', 'name' => 'Sulawesi Tengah'],
            ['id' => '73', 'name' => 'Sulawesi Selatan'],
            ['id' => '74', 'name' => 'Sulawesi Tenggara'],
            ['id' => '75', 'name' => 'Gorontalo'],
            ['id' => '76', 'name' => 'Sulawesi Barat'],
            ['id' => '81', 'name' => 'Maluku'],
            ['id' => '82', 'name' => 'Maluku Utara'],
            ['id' => '91', 'name' => 'Papua Barat'],
            ['id' => '94', 'name' => 'Papua'],
        ];
    }

    /**
     * Load cities data by province ID
     * 
     * @param string $provinceId
     * @return array
     */
    private function loadCities($provinceId)
    {
        $citiesData = [
            '31' => [ // DKI Jakarta
                ['id' => '3171', 'name' => 'Jakarta Selatan'],
                ['id' => '3172', 'name' => 'Jakarta Timur'],
                ['id' => '3173', 'name' => 'Jakarta Pusat'],
                ['id' => '3174', 'name' => 'Jakarta Barat'],
                ['id' => '3175', 'name' => 'Jakarta Utara'],
                ['id' => '3176', 'name' => 'Kepulauan Seribu'],
            ],
            '32' => [ // Jawa Barat
                ['id' => '3201', 'name' => 'Kabupaten Bogor'],
                ['id' => '3202', 'name' => 'Kabupaten Sukabumi'],
                ['id' => '3203', 'name' => 'Kabupaten Cianjur'],
                ['id' => '3204', 'name' => 'Kabupaten Bandung'],
                ['id' => '3205', 'name' => 'Kabupaten Garut'],
                ['id' => '3206', 'name' => 'Kabupaten Tasikmalaya'],
                ['id' => '3207', 'name' => 'Kabupaten Ciamis'],
                ['id' => '3208', 'name' => 'Kabupaten Kuningan'],
                ['id' => '3209', 'name' => 'Kabupaten Cirebon'],
                ['id' => '3210', 'name' => 'Kabupaten Majalengka'],
                ['id' => '3211', 'name' => 'Kabupaten Sumedang'],
                ['id' => '3212', 'name' => 'Kabupaten Indramayu'],
                ['id' => '3213', 'name' => 'Kabupaten Subang'],
                ['id' => '3214', 'name' => 'Kabupaten Purwakarta'],
                ['id' => '3215', 'name' => 'Kabupaten Karawang'],
                ['id' => '3216', 'name' => 'Kabupaten Bekasi'],
                ['id' => '3217', 'name' => 'Kabupaten Bandung Barat'],
                ['id' => '3218', 'name' => 'Kabupaten Pangandaran'],
                ['id' => '3271', 'name' => 'Kota Bogor'],
                ['id' => '3272', 'name' => 'Kota Sukabumi'],
                ['id' => '3273', 'name' => 'Kota Bandung'],
                ['id' => '3274', 'name' => 'Kota Cirebon'],
                ['id' => '3275', 'name' => 'Kota Bekasi'],
                ['id' => '3276', 'name' => 'Kota Depok'],
                ['id' => '3277', 'name' => 'Kota Cimahi'],
                ['id' => '3278', 'name' => 'Kota Tasikmalaya'],
                ['id' => '3279', 'name' => 'Kota Banjar'],
            ],
            '33' => [ // Jawa Tengah
                ['id' => '3301', 'name' => 'Kabupaten Cilacap'],
                ['id' => '3302', 'name' => 'Kabupaten Banyumas'],
                ['id' => '3303', 'name' => 'Kabupaten Purbalingga'],
                ['id' => '3304', 'name' => 'Kabupaten Banjarnegara'],
                ['id' => '3305', 'name' => 'Kabupaten Kebumen'],
                ['id' => '3306', 'name' => 'Kabupaten Purworejo'],
                ['id' => '3307', 'name' => 'Kabupaten Wonosobo'],
                ['id' => '3308', 'name' => 'Kabupaten Magelang'],
                ['id' => '3309', 'name' => 'Kabupaten Boyolali'],
                ['id' => '3310', 'name' => 'Kabupaten Klaten'],
                ['id' => '3311', 'name' => 'Kabupaten Sukoharjo'],
                ['id' => '3312', 'name' => 'Kabupaten Wonogiri'],
                ['id' => '3313', 'name' => 'Kabupaten Karanganyar'],
                ['id' => '3314', 'name' => 'Kabupaten Sragen'],
                ['id' => '3315', 'name' => 'Kabupaten Grobogan'],
                ['id' => '3316', 'name' => 'Kabupaten Blora'],
                ['id' => '3317', 'name' => 'Kabupaten Rembang'],
                ['id' => '3318', 'name' => 'Kabupaten Pati'],
                ['id' => '3319', 'name' => 'Kabupaten Kudus'],
                ['id' => '3320', 'name' => 'Kabupaten Jepara'],
                ['id' => '3321', 'name' => 'Kabupaten Demak'],
                ['id' => '3322', 'name' => 'Kabupaten Semarang'],
                ['id' => '3323', 'name' => 'Kabupaten Temanggung'],
                ['id' => '3324', 'name' => 'Kabupaten Kendal'],
                ['id' => '3325', 'name' => 'Kabupaten Batang'],
                ['id' => '3326', 'name' => 'Kabupaten Pekalongan'],
                ['id' => '3327', 'name' => 'Kabupaten Pemalang'],
                ['id' => '3328', 'name' => 'Kabupaten Tegal'],
                ['id' => '3329', 'name' => 'Kabupaten Brebes'],
                ['id' => '3371', 'name' => 'Kota Magelang'],
                ['id' => '3372', 'name' => 'Kota Surakarta'],
                ['id' => '3373', 'name' => 'Kota Salatiga'],
                ['id' => '3374', 'name' => 'Kota Semarang'],
                ['id' => '3375', 'name' => 'Kota Pekalongan'],
                ['id' => '3376', 'name' => 'Kota Tegal'],
            ],
            '35' => [ // Jawa Timur
                ['id' => '3501', 'name' => 'Kabupaten Pacitan'],
                ['id' => '3502', 'name' => 'Kabupaten Ponorogo'],
                ['id' => '3503', 'name' => 'Kabupaten Trenggalek'],
                ['id' => '3504', 'name' => 'Kabupaten Tulungagung'],
                ['id' => '3505', 'name' => 'Kabupaten Blitar'],
                ['id' => '3506', 'name' => 'Kabupaten Kediri'],
                ['id' => '3507', 'name' => 'Kabupaten Malang'],
                ['id' => '3508', 'name' => 'Kabupaten Lumajang'],
                ['id' => '3509', 'name' => 'Kabupaten Jember'],
                ['id' => '3510', 'name' => 'Kabupaten Banyuwangi'],
                ['id' => '3511', 'name' => 'Kabupaten Bondowoso'],
                ['id' => '3512', 'name' => 'Kabupaten Situbondo'],
                ['id' => '3513', 'name' => 'Kabupaten Probolinggo'],
                ['id' => '3514', 'name' => 'Kabupaten Pasuruan'],
                ['id' => '3515', 'name' => 'Kabupaten Sidoarjo'],
                ['id' => '3516', 'name' => 'Kabupaten Mojokerto'],
                ['id' => '3517', 'name' => 'Kabupaten Jombang'],
                ['id' => '3518', 'name' => 'Kabupaten Nganjuk'],
                ['id' => '3519', 'name' => 'Kabupaten Madiun'],
                ['id' => '3520', 'name' => 'Kabupaten Magetan'],
                ['id' => '3521', 'name' => 'Kabupaten Ngawi'],
                ['id' => '3522', 'name' => 'Kabupaten Bojonegoro'],
                ['id' => '3523', 'name' => 'Kabupaten Tuban'],
                ['id' => '3524', 'name' => 'Kabupaten Lamongan'],
                ['id' => '3525', 'name' => 'Kabupaten Gresik'],
                ['id' => '3526', 'name' => 'Kabupaten Bangkalan'],
                ['id' => '3527', 'name' => 'Kabupaten Sampang'],
                ['id' => '3528', 'name' => 'Kabupaten Pamekasan'],
                ['id' => '3529', 'name' => 'Kabupaten Sumenep'],
                ['id' => '3571', 'name' => 'Kota Kediri'],
                ['id' => '3572', 'name' => 'Kota Blitar'],
                ['id' => '3573', 'name' => 'Kota Malang'],
                ['id' => '3574', 'name' => 'Kota Probolinggo'],
                ['id' => '3575', 'name' => 'Kota Pasuruan'],
                ['id' => '3576', 'name' => 'Kota Mojokerto'],
                ['id' => '3577', 'name' => 'Kota Madiun'],
                ['id' => '3578', 'name' => 'Kota Surabaya'],
                ['id' => '3579', 'name' => 'Kota Batu'],
            ],
            '36' => [ // Banten
                ['id' => '3601', 'name' => 'Kabupaten Pandeglang'],
                ['id' => '3602', 'name' => 'Kabupaten Lebak'],
                ['id' => '3603', 'name' => 'Kabupaten Tangerang'],
                ['id' => '3604', 'name' => 'Kabupaten Serang'],
                ['id' => '3671', 'name' => 'Kota Tangerang'],
                ['id' => '3672', 'name' => 'Kota Cilegon'],
                ['id' => '3673', 'name' => 'Kota Serang'],
                ['id' => '3674', 'name' => 'Kota Tangerang Selatan'],
            ],
        ];

        return $citiesData[$provinceId] ?? [];
    }
}
