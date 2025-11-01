<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'car_id'        => $this->car_id,
            'user_id'       => $this->user_id,
            'field_changed' => $this->field_changed,
            'old_value'     => $this->old_value,
            'new_value'     => $this->new_value,
            'created_at'    => optional($this->created_at)->toDateTimeString(),
            'updated_at'    => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
