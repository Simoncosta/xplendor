<?php

declare(strict_types=1);

namespace App\Services\ConsultantSite;

use App\Models\Development;
use App\Models\ListingImage;
use App\Models\Property;
use App\Support\Images\ImageMetadata;
use App\Support\Storage\PublicUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Site do consultor: as fotografias públicas dos imóveis e dos empreendimentos, no disco público,
 * pelo padrão das imagens dos veículos (sem tocar no stand): o original recodificado sem metadados
 * (sem EXIF nem GPS: um usado nunca revela a casa pela fotografia) e três larguras WebP para o
 * srcset do site (1600, 960 e 480 px), geradas uma vez na gravação.
 */
class ListingImageService
{
    public const WIDTHS = [1600 => 'path', 960 => 'path_960', 480 => 'path_480'];
    public const MAX_IMAGES = 40;

    /** @param UploadedFile[] $files @return ListingImage[] */
    public function store(Property|Development $owner, array $files): array
    {
        [$type, $folder] = $this->folder($owner);
        $disk = Storage::disk('public');
        $manager = new ImageManager(new Driver());
        $order = (int) ListingImage::where('owner_type', $type)->where('owner_id', $owner->id)->max('order');
        $hasCover = ListingImage::where('owner_type', $type)->where('owner_id', $owner->id)->where('is_cover', true)->exists();
        $out = [];
        foreach ($files as $file) {
            $base = now()->format('YmdHisv') . Str::lower(Str::random(6));
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
            $binary = (string) file_get_contents($file->getRealPath());
            $originalPath = "{$folder}/originals/{$base}.{$ext}";
            $disk->put($originalPath, ImageMetadata::strippedBinary($binary, $ext));

            $image = $manager->read($binary)->orient();
            $paths = [];
            $size = null;
            foreach (self::WIDTHS as $width => $column) {
                $path = "{$folder}/{$width}/{$base}.webp";
                $variant = (clone $image)->scaleDown(width: $width);
                $size ??= [$variant->width(), $variant->height()]; // as medidas da maior (a do src)
                $disk->put($path, $variant->toWebp(82)->toString());
                $paths[$column] = PublicUrl::path($path);
            }
            $out[] = ListingImage::create($paths + [
                'company_id' => $owner->company_id, 'owner_type' => $type, 'owner_id' => $owner->id,
                'original_path' => PublicUrl::path($originalPath), 'width' => $size[0], 'height' => $size[1],
                'order' => ++$order, 'is_cover' => ! $hasCover && $out === [],
            ]);
        }

        return $out;
    }

    /** A nova ordem (a capa é a indicada, ou a primeira). @param int[] $ids */
    public function reorder(Property|Development $owner, array $ids, ?int $coverId): void
    {
        [$type] = $this->folder($owner);
        $images = ListingImage::where('owner_type', $type)->where('owner_id', $owner->id)->get()->keyBy('id');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $images->has($id))));
        foreach ($images->keys() as $id) {
            if (! in_array($id, $ids, true)) {
                $ids[] = $id; // as que não vieram ficam no fim, pela ordem atual
            }
        }
        $coverId = $coverId && $images->has($coverId) ? $coverId : ($ids[0] ?? null);
        DB::transaction(function () use ($ids, $images, $coverId) {
            foreach ($ids as $i => $id) {
                $images[$id]->forceFill(['order' => $i + 1, 'is_cover' => $id === $coverId])->save();
            }
        });
    }

    public function updateAlt(ListingImage $image, ?string $alt): void
    {
        $image->forceFill(['alt' => $alt !== null && trim($alt) !== '' ? trim($alt) : null])->save();
    }

    /** Apaga a fotografia e os ficheiros; a capa passa para a seguinte. */
    public function delete(ListingImage $image): void
    {
        $this->deleteFiles($image);
        $wasCover = $image->is_cover;
        $type = $image->owner_type;
        $ownerId = $image->owner_id;
        $image->delete();
        if ($wasCover) {
            ListingImage::where('owner_type', $type)->where('owner_id', $ownerId)->orderBy('order')->orderBy('id')->first()?->forceFill(['is_cover' => true])->save();
        }
    }

    public function deleteAll(Property|Development $owner): void
    {
        [$type] = $this->folder($owner);
        foreach (ListingImage::where('owner_type', $type)->where('owner_id', $owner->id)->get() as $image) {
            $this->deleteFiles($image);
            $image->delete();
        }
    }

    private function deleteFiles(ListingImage $image): void
    {
        $disk = Storage::disk('public');
        foreach ([$image->path, $image->path_960, $image->path_480, $image->original_path] as $path) {
            $disk->delete(ltrim((string) preg_replace('#^/storage/#', '', (string) $path), '/'));
        }
    }

    /** @return array{0: string, 1: string} */
    private function folder(Property|Development $owner): array
    {
        $type = $owner instanceof Property ? ListingImage::OWNER_PROPERTY : ListingImage::OWNER_DEVELOPMENT;
        $name = $owner instanceof Property ? 'imovel' : 'empreendimento';

        return [$type, "company_{$owner->company_id}/imoveis/{$name}-{$owner->id}"];
    }
}
