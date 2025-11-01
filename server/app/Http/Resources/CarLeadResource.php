<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarLeadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => (int) $this->id,
            'car_id'    => (int) $this->car_id,
            'name'      => (string) $this->name,
            'email'     => (string) $this->email,
            'phone'     => $this->phone ? (string) $this->phone : null,
            'mensagem'  => $this->mensagem ? (string) $this->mensagem : null,
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
