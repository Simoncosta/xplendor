<?php

namespace App\Services;

use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;

class CarImageService
{
    /**
     * @param  array<int, \Illuminate\Http\UploadedFile>  $images
     * @param  array<int, array{order?: int, is_primary?: bool, crop?: array{x: int, y: int, width: int, height: int}}>  $meta
     * @return array<int, array{image: string, original_path: string, order: int, is_primary: bool}>
     */
    public function handleUploads(array $images, array $meta, string $type, int $companyId, int $carId, string $carSlug): array
    {
        $imagesFolder   = "company_{$companyId}/cars/{$carSlug}-{$carId}/{$type}";
        $originalsFolder = "company_{$companyId}/cars/{$carSlug}-{$carId}/originals";

        Storage::disk('public')->makeDirectory($imagesFolder);
        Storage::disk('public')->makeDirectory($originalsFolder);

        $results = [];
        $manager = new ImageManager(new Driver());

        foreach ($images as $index => $image) {
            $imageMeta  = $meta[$index] ?? [];
            $order      = $imageMeta['order'] ?? $index + 1;
            $isPrimary  = $type === 'images' && ($imageMeta['is_primary'] ?? $index === 0);
            $crop       = $imageMeta['crop'] ?? null;
            $timestamp  = now()->format('YmdHisv');
            $suffix     = substr(md5(uniqid()), 0, 6);
            $basename   = "{$order}_{$timestamp}{$suffix}";

            // Save original with real extension (no conversion)
            $ext             = strtolower($image->getClientOriginalExtension() ?: 'jpg');
            $originalFilename = "{$basename}.{$ext}";
            $originalStoragePath = "{$originalsFolder}/{$originalFilename}";
            Storage::disk('public')->putFileAs($originalsFolder, $image, $originalFilename);
            $originalPublicPath = Storage::url($originalStoragePath);

            // Build converted/cropped version
            $img = $manager->read($image);

            if (
                $crop !== null
                && isset($crop['width'], $crop['height'])
                && (int) $crop['width'] > 0
                && (int) $crop['height'] > 0
            ) {
                $img = $img->crop(
                    (int) $crop['width'],
                    (int) $crop['height'],
                    (int) ($crop['x'] ?? 0),
                    (int) ($crop['y'] ?? 0),
                );
            }

            $croppedFilename = "{$basename}.webp";
            $croppedPath     = "{$imagesFolder}/{$croppedFilename}";
            Storage::disk('public')->put($croppedPath, $img->toWebp(85)->toString());
            $croppedPublicPath = Storage::url($croppedPath);

            $results[] = [
                'image'         => $croppedPublicPath,
                'original_path' => $originalPublicPath,
                'order'         => $order,
                'is_primary'    => $isPrimary,
            ];
        }

        return $results;
    }
}
