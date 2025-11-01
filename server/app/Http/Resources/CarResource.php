<?php

namespace App\Http\Resources;

use App\Models\CarImage;
use App\Models\CarLead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'status' => $this->status,
            'is_imported' => $this->is_imported,
            'licence_plate' => $this->licence_plate,
            'km' => $this->km,
            'vin' => $this->vin,
            'month_registration' => $this->month_registration,
            'year_registration' => $this->year_registration,
            'mark' => $this->mark,
            'model' => $this->model,
            'fuel' => $this->fuel,
            'power' => $this->power,
            'number_doors' => $this->number_doors,
            'gearbox' => $this->gearbox,
            'version' => $this->version,
            'segment' => $this->segment,
            'color' => $this->color,
            'link_youtube' => $this->link_youtube,
            'description' => $this->description,
            'price' => (float) $this->price,
            'show_price' => $this->show_price,
            'discount_type' => $this->discount_type,
            'discount' => (float) $this->discount,
            'leads' => CarLeadResource::collection($this->whenLoaded('leads')),
            'images' => CarImageResource::collection($this->whenLoaded('images')),
            'rotate_exterior_images' => CarRotateExteriorImagesResource::collection($this->whenLoaded('rotateExteriorImages')),
            'company_id' => $this->company_id,
            'seller_id' => $this->seller_id,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
        ];
    }
}
