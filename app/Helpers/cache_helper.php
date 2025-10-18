<?php

use CodeIgniter\Database\BaseBuilder;

if (!function_exists('cacheQuery')) {
    /**
     * Simpan hasil query ke cache jika tersedia, atau jalankan dan simpan jika belum.
     *
     * @param BaseBuilder $builder
     * @param int $ttl Waktu dalam detik untuk menyimpan cache
     * @param string|null $cacheKey Jika null, cache key akan digenerate otomatis dari SQL
     * @return array
     */
    function cacheQuery(BaseBuilder $builder, int $ttl = 600, string $cacheKey = null): array
    {
        $cache = \Config\Services::cache();

        if (!$cacheKey) {
            // Buat key cache dari query SQL
            $sql      = $builder->getCompiledSelect(false); // jangan reset
            $binds    = json_encode($builder->getBinds());
            $cacheKey = 'query_' . md5($sql . $binds);
        }

        // Cek cache
        $data = $cache->get($cacheKey);
        if ($data === null) {
            $query = $builder->get();
            $data  = $query->getResultArray();
            $cache->save($cacheKey, $data, $ttl); // Simpan cache
        }

        return $data;
    }
}
