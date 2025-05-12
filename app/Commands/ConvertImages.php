<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ConvertImages extends BaseCommand
{
    protected $group = 'Image';
    protected $name = 'image:convert-webp';
    protected $description = 'Convert all images to WebP and update database URLs';

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        $query = $db->query("SELECT id, url FROM image");
        $images = $query->getResultArray();

        $sourceBasePath = FCPATH . 'uploads/images/';
        $targetBasePath = FCPATH . 'hope/images/';

        if (!is_dir($targetBasePath)) {
            mkdir($targetBasePath, 0755, true);
        }

        foreach ($images as $img) {
            $id = $img['id'];
            $relativePath = $img['url'];
            $filename = basename($relativePath);
            $sourceFile = $sourceBasePath . $filename;

            if (!file_exists($sourceFile)) {
                CLI::write("File tidak ditemukan: $sourceFile", 'yellow');
                continue;
            }

            $targetFile = $targetBasePath . pathinfo($filename, PATHINFO_FILENAME) . '.webp';

            $imageInfo = getimagesize($sourceFile);
            if (!$imageInfo) {
                CLI::write("File bukan gambar valid: $sourceFile", 'yellow');
                continue;
            }

            $mime = $imageInfo['mime'];

            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourceFile);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourceFile);
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($sourceFile);
                    break;
                default:
                    CLI::write("Format gambar tidak didukung: $sourceFile", 'red');
                    continue 2;
            }

            if (!$image) {
                CLI::write("Gagal memuat gambar: $sourceFile", 'red');
                continue;
            }

            $result = imagewebp($image, $targetFile, 80);

            if ($result) {
                CLI::write("Berhasil konversi: $filename -> " . basename($targetFile), 'green');

                $newUrl = 'hope/images/' . pathinfo($filename, PATHINFO_FILENAME) . '.webp';

                $builder = $db->table('image');
                $builder->where('id', $id)->update(['url' => $newUrl]);
            } else {
                CLI::write("Gagal konversi: $filename", 'red');
            }

            imagedestroy($image);
        }

        CLI::write("Proses konversi dan update database selesai.", 'green');
    }
}
