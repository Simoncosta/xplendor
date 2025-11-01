<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'    => (int) $this->id,
            'name'  => (string) $this->name,
            'description' => (string) $this->description,
            'price' => (float) $this->price,
            'car_limit'  => (int) $this->car_limit,
            'features' => $this->features,
            'created_at' => optional($this->created_at)?->toDateTimeString(),
            'updated_at' => optional($this->updated_at)?->toDateTimeString(),
        ];
    }
}
