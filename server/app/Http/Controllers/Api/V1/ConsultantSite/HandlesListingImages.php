<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\ConsultantSite;

use App\Helpers\ApiResponse;
use App\Models\Development;
use App\Models\ListingImage;
use App\Models\Property;
use App\Services\ConsultantSite\ListingImageService;
use Illuminate\Http\Request;

/** Site do consultor: as fotografias de um imóvel ou de um empreendimento (enviar, ordenar, capa, texto alternativo, apagar). */
trait HandlesListingImages
{
    protected function storeImagesFor(Request $request, Property|Development $owner)
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:20'],
            'images.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
        ], ['images.*.mimetypes' => 'Só fotografias JPEG, PNG ou WebP.', 'images.*.max' => 'Cada fotografia pode ter até 10 MB.']);
        $count = ListingImage::where('owner_type', $this->ownerType($owner))->where('owner_id', $owner->id)->count();
        if ($count + count($request->file('images')) > ListingImageService::MAX_IMAGES) {
            return ApiResponse::error('Cada anúncio pode ter até ' . ListingImageService::MAX_IMAGES . ' fotografias.', 422);
        }
        app(ListingImageService::class)->store($owner, $request->file('images'));

        return ApiResponse::success($this->imagesOf($owner), 'Fotografias guardadas.', 201);
    }

    protected function orderImagesFor(Request $request, Property|Development $owner)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:' . ListingImageService::MAX_IMAGES], 'ids.*' => ['integer'], 'cover_id' => ['nullable', 'integer']]);
        app(ListingImageService::class)->reorder($owner, $data['ids'], $data['cover_id'] ?? null);

        return ApiResponse::success($this->imagesOf($owner), 'Ordem guardada.');
    }

    protected function updateImageFor(Request $request, Property|Development $owner, int $imageId)
    {
        $data = $request->validate(['alt' => ['nullable', 'string', 'max:160']]);
        app(ListingImageService::class)->updateAlt($this->findImage($owner, $imageId), $data['alt'] ?? null);

        return ApiResponse::success($this->imagesOf($owner), 'Fotografia guardada.');
    }

    protected function destroyImageFor(Property|Development $owner, int $imageId)
    {
        app(ListingImageService::class)->delete($this->findImage($owner, $imageId));

        return ApiResponse::success($this->imagesOf($owner), 'Fotografia apagada.');
    }

    /** @return array<int, array<string, mixed>> */
    protected function imagesOf(Property|Development $owner): array
    {
        return ListingImage::where('owner_type', $this->ownerType($owner))->where('owner_id', $owner->id)
            ->orderByDesc('is_cover')->orderBy('order')->orderBy('id')->get()
            ->map(fn (ListingImage $i) => ['id' => $i->id, 'url' => $i->path, 'thumb_url' => $i->path_480, 'width' => $i->width, 'height' => $i->height,
                'order' => $i->order, 'is_cover' => $i->is_cover, 'alt' => $i->alt])->values()->all();
    }

    private function findImage(Property|Development $owner, int $imageId): ListingImage
    {
        $image = ListingImage::where('owner_type', $this->ownerType($owner))->where('owner_id', $owner->id)->find($imageId);
        if (! $image) {
            abort(404, 'Fotografia não encontrada.');
        }

        return $image;
    }

    private function ownerType(Property|Development $owner): string
    {
        return $owner instanceof Property ? ListingImage::OWNER_PROPERTY : ListingImage::OWNER_DEVELOPMENT;
    }
}
