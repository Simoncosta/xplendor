<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
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
            'name' => (string) $this->name,
            'logo' => (string) $this->logo,
            'banner_main' => (string) $this->banner_main,
            'title_about' => (string) $this->title_about,
            'description_about' => (string) $this->description_about,
            'address' => (string) $this->address,
            'number' => (string) $this->number,
            'city' => (string) $this->city,
            'state' => (string) $this->state,
            'zip_code' => (string) $this->zip_code,
            'show_address' => (string) $this->show_address,
            'social_links' => SocialLinkResource::collection($this->whenLoaded('socialLinks')),
            'operations' => CompanyOperationResource::collection($this->whenLoaded('operations')),
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'country' => new CountryResource($this->whenLoaded('country')),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
