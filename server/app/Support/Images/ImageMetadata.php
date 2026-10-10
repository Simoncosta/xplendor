<?php

declare(strict_types=1);

namespace App\Support\Images;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Imagens sem metadados (pré-deploy, ponto 5): recodifica a imagem com a orientação já
 * aplicada e sem EXIF (que pode trazer a localização GPS, o aparelho e a data). O GD nunca
 * copia o EXIF para a imagem nova.
 */
final class ImageMetadata
{
    /** Recodifica no mesmo formato (JPEG 92, PNG, WebP 92); outro formato fica em JPEG. */
    public static function stripped(ImageInterface $image, string $extension): string
    {
        $image = $image->orient();

        return match (strtolower($extension)) {
            'png' => $image->toPng()->toString(),
            'webp' => $image->toWebp(92)->toString(),
            default => $image->toJpeg(92)->toString(),
        };
    }

    /** Recodifica um ficheiro de imagem (conteúdo binário) sem metadados. */
    public static function strippedBinary(string $binary, string $extension): string
    {
        return self::stripped((new ImageManager(new Driver()))->read($binary), $extension);
    }

    /** O ficheiro tem EXIF? E localização GPS? (só JPEG e WebP guardam EXIF que o PHP lê) */
    public static function inspect(string $absolutePath): array
    {
        $exif = @exif_read_data($absolutePath, null, true);
        if (! is_array($exif)) {
            return ['exif' => false, 'gps' => false];
        }
        // Só as secções de EXIF propriamente ditas (o GD escreve um comentário "CREATOR", que não conta).
        $relevant = array_intersect(array_keys($exif), ['IFD0', 'EXIF', 'GPS', 'THUMBNAIL', 'INTEROP']);

        return ['exif' => $relevant !== [], 'gps' => isset($exif['GPS']) && $exif['GPS'] !== []];
    }
}
