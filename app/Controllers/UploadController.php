<?php

namespace App\Controllers;

use App\Models\JsonResponse;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class UploadController extends ResourceController
{
    use ResponseTrait;

    protected $jsonResponse;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
    }

    public function uploadImage()
    {
        $file = $this->request->getFile('file');
        $folder = $this->request->getPost('folder') ?? 'general';
        
        // Clean folder name to prevent directory traversal
        $folder = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $folder);
        $folder = trim($folder, '/');

        if (!$file || !$file->isValid()) {
            return $this->jsonResponse->error('Invalid file uploaded', 400);
        }

        $tempPath = $file->getTempName();
        $mimeType = $file->getMimeType();
        $ext = strtolower($file->getExtension());
        
        // Ensure target directory exists
        $targetDir = ROOTPATH . 'public/uploads/' . $folder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $newName = bin2hex(random_bytes(10)) . '.webp';
        $savePath = $targetDir . '/' . $newName;

        if ($ext === 'webp' || $mimeType === 'image/webp') {
            // Already WebP, just move it
            if ($file->move($targetDir, $newName)) {
                $relativeUrl = 'uploads/' . $folder . '/' . $newName;
                return $this->jsonResponse->oneResp('Image uploaded successfully', [
                    'path' => $relativeUrl,
                    'url' => base_url($relativeUrl)
                ], 200);
            } else {
                return $this->jsonResponse->error('Failed to move uploaded file', 500);
            }
        }

        // Convert to WebP
        try {
            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $source = imagecreatefromjpeg($tempPath);
                    break;
                case 'image/png':
                    $source = imagecreatefrompng($tempPath);
                    imagepalettetotruecolor($source);
                    imagealphablending($source, true);
                    imagesavealpha($source, true);
                    break;
                case 'image/avif':
                    if (function_exists('imagecreatefromavif')) {
                        $source = imagecreatefromavif($tempPath);
                    } else {
                        throw new \Exception('AVIF not supported on this server');
                    }
                    break;
                default:
                    return $this->jsonResponse->error('Unsupported image format: ' . $mimeType, 400);
            }

            if (!$source) {
                throw new \Exception('Failed to process image');
            }

            // Save as WebP
            if (!imagewebp($source, $savePath, 80)) {
                 throw new \Exception('Failed to save WebP image');
            }
            imagedestroy($source);

            $relativeUrl = 'uploads/' . $folder . '/' . $newName;
            $fullUrl = base_url($relativeUrl);

            return $this->jsonResponse->oneResp('Image uploaded and converted to WebP successfully', [
                'path' => $relativeUrl,
                'url' => $fullUrl
            ], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
