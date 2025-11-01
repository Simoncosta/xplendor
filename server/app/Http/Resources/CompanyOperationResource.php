<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyOperationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'day_week' => (string) $this->day_week,
            'opens_at' => (string) $this->opens_at,
            'closes_at' => (string) $this->closes_at,
        ];
    }
}
