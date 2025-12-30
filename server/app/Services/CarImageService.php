<?php

namespace App\Services;

use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;

class CarImageService
{
    public function handleUploads(array $images, array $meta = [], string $type, int $companyId, int $carId, string $carSlug): array
    {
        $folder = "company_{$companyId}/cars/{$carSlug}-{$carId}/{$type}";
        Storage::disk('public')->makeDirectory($folder);

        $results = [];
        $manager = new ImageManager(new Driver());

        foreach ($images as $index => $image) {
            $order = $meta[$index]['order'] ?? $index + 1;
            $isPrimary = $type === 'images' && ($meta[$index]['is_primary'] ?? $index === 0);
            $timestamp = now()->format('YmdHisv');

            $filename = "{$order}_{$timestamp}" . substr(md5(uniqid()), 0, 6) . ".webp";
            $path = "{$folder}/{$filename}";

            // WebP conversion
            $converted = $manager->read($image)->toWebp(85)->toString();
            Storage::disk('public')->put($path, $converted);

            // Generate public URL
            $publicPath = Storage::url($path); // → /storage/company_xxx/...

            $results[] = [
                'image' => $publicPath,
                'order' => $order,
                'is_primary' => $isPrimary,
            ];
        }

        return $results;
    }
}
