<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use \Config\Services;

class ShippingController extends ResourceController
{
    protected $format = 'json';

    /**
     * Check shipping status using CekPengiriman scraping logic
     * 
     * @return mixed
     */
    public function track()
    {
        $courier = $this->request->getGet('kurir') ?: $this->request->getGet('courier');
        $resi = $this->request->getGet('resi');

        if (!$courier || !$resi) {
            return $this->respond([
                'status' => 400,
                'message' => 'Courier and Receipt Number (Resi) are required parameters'
            ], 400);
        }

        // Normalize J&T courier code if needed (user used 'jnt' in URL)
        if ($courier === 'jne') $courier = 'jne';
        if ($courier === 'j&t' || $courier === 'jnt') $courier = 'jnt';

        // Cache check disabled for debugging
        /*
        $cacheName = 'tracking_cache_cp_' . $courier . '_' . $resi;
        if ($cachedData = cache($cacheName)) {
            return $this->respond($cachedData);
        }
        */

        $mainUrl = "https://www.cekpengiriman.com/cek-resi?resi=$resi&kurir=$courier";
        $apiUrl = "https://www.cekpengiriman.com/wp-content/themes/simple/includes/widget/resultResi.php";
        
        try {
            $client = Services::curlrequest();
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

            // 1. Get Token and Cookies
            $mainResponse = $client->request('GET', $mainUrl, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ],
                'timeout' => 10,
                'http_errors' => false
            ]);

            if ($mainResponse->getStatusCode() !== 200) {
                return $this->respond([
                    'status' => $mainResponse->getStatusCode(),
                    'message' => 'Failed to reach tracking site'
                ], $mainResponse->getStatusCode());
            }

            $mainHtml = $mainResponse->getBody();
            // Match token in: formData.append("token", "...")
            preg_match('/"token",\s*"([^"]+)"/', $mainHtml, $matches);
            $token = $matches[1] ?? '';

            if (!$token) {
                return $this->respond([
                    'status' => 500,
                    'message' => 'Could not extract security token from CekPengiriman',
                    'debug_resi' => $resi,
                    'debug_kurir' => $courier
                ], 500);
            }

            // Get cookies from header
            $cookieHeaders = $mainResponse->getHeader('Set-Cookie') ?? [];
            $cookieParts = [];
            foreach ((array)$cookieHeaders as $header) {
                $parts = explode(';', $header);
                $cookieParts[] = trim($parts[0]);
            }
            $cookieStr = implode('; ', $cookieParts);

            // 2. Fetch tracking details via internal API
            $apiResponse = $client->request('POST', $apiUrl, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $mainUrl,
                    'Cookie' => $cookieStr,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'form_params' => [
                    'token' => $token,
                    'resi' => $resi,
                    'kurir' => $courier
                ],
                'timeout' => 15,
                'http_errors' => false
            ]);

            $apiHtml = $apiResponse->getBody();
            
            if (empty($apiHtml) || mb_stripos($apiHtml, 'tidak ditemukan') !== false || mb_stripos($apiHtml, 'alert-danger') !== false) {
                return $this->respond([
                    'status' => 404,
                    'message' => 'Tracking number not found in CekPengiriman',
                    'debug_len' => strlen($apiHtml)
                ], 404);
            }

            // 3. Parse result
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            // Use @ to suppress warnings from malformed HTML
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $apiHtml);
            $xpath = new \DOMXPath($dom);

            // Summary Extraction
            $summary = [];
            $summaryTable = $xpath->query('//table[@id="summary"]')->item(0);
            
            if (!$summaryTable) {
                // Fallback: search for first table after "Ringkasan" heading
                $summaryHeading = $xpath->query('//h4[contains(text(), "Ringkasan")]')->item(0);
                if ($summaryHeading) {
                    $summaryTable = $xpath->query('following::table[1]', $summaryHeading)->item(0);
                }
            }

            if ($summaryTable) {
                $summaryRows = $xpath->query('.//tr', $summaryTable);
                foreach ($summaryRows as $row) {
                    $cols = $xpath->query('./td', $row);
                    if ($cols->length >= 2) {
                        $key = trim(str_replace(':', '', $cols->item(0)->textContent));
                        $val = trim($cols->item(1)->textContent);
                        $summary[$key] = $val;
                    }
                }
            }

            // History Extraction
            $track = [];
            // Find the table that follows "Riwayat Pengiriman" heading
            $historyHeading = $xpath->query('//h4[contains(text(), "Riwayat")]');
            $historyTable = null;
            
            // If heading not found, try searching table contents
            if ($historyHeading->length > 0) {
                $historyTable = $xpath->query('./following-sibling::table[1]', $historyHeading->item(0))->item(0);
            }
            
            if (!$historyTable) {
                // Fallback: look for table containing "Riwayat Pengiriman" text in first row
                $tables = $xpath->query('//table[contains(@class, "table-bordered")]');
                foreach ($tables as $t) {
                    if (strpos($t->textContent, 'Riwayat Pengiriman') !== false) {
                        $historyTable = $t;
                        break;
                    }
                }
            }

            if ($historyTable) {
                $rows = $xpath->query('.//tr', $historyTable);
                foreach ($rows as $index => $row) {
                    // Skip header row if it contains "Riwayat Pengiriman"
                    if ($index === 0 && strpos($row->textContent, 'Riwayat Pengiriman') !== false) continue;
                    
                    $content = trim($row->textContent);
                    if (empty($content)) continue;

                    // Parse patterns like:
                    // "24 February 2026 08:31:33 JAKARTA - Manifes"
                    // "25 Feb 2026 12:29:51 - 【Kota Bekasi】..."
                    preg_match('/^(\d{1,2}\s+[A-Za-z]+\s+\d{4})\s+(\d{2}:\d{2}:\d{2})\s*(?:-)?\s*(.*)$/', $content, $m);
                    
                    if ($m) {
                        $fullDesc = trim($m[3]);
                        $descParts = explode(' - ', $fullDesc, 2);
                        
                        // If no " - " found, check for other delimiters like "】" or specific J&T Cargo patterns
                        if (count($descParts) < 2 && mb_strpos($fullDesc, '】') !== false) {
                            $descParts = explode('】', $fullDesc, 2);
                            $descParts[0] .= '】'; // Keep the bracket
                        }

                        $location = trim($descParts[0] ?? '');
                        $status = trim($descParts[1] ?? $fullDesc);

                        $track[] = [
                            'date' => $m[1],
                            'time' => $m[2],
                            'location' => $location,
                            'status' => $status,
                            'full_description' => $content
                        ];
                    } else {
                        $track[] = [
                            'status' => $content,
                            'full_description' => $content
                        ];
                    }
                }
            }

            $result = [
                'status' => 200,
                'summary' => $summary,
                'track' => array_reverse($track) // Newest first
            ];

            // Cache saving disabled for debugging
            // cache()->save($cacheName, $result, 3600);

            return $this->respond($result);

        } catch (\Exception $e) {
            return $this->respond([
                'status' => 500,
                'message' => 'Scraper error: ' . $e->getMessage()
            ], 500);
        }
    }
}
