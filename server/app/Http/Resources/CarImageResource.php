<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => (int) $this->id,
            'image'         => (string) $this->image,
            'is_primary'    => (bool) $this->is_primary,
            'car_id'        => (int) $this->car_id,
            'created_at'    => optional($this->created_at)->toDateTimeString(),
            'updated_at'    => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
