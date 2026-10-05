<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Colaborador no painel da empresa (uso interno; o site usa o TeamPublicResource). */
class CollaboratorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $invite = $this->relationLoaded('pendingInvite') ? $this->pendingInvite : null;

        return [
            'id'            => $this->id,
            'company_id'    => $this->company_id,
            'department_id' => $this->department_id,
            'department'    => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'name'          => $this->name,
            'role_title'    => $this->role_title,
            'bio'           => $this->bio,
            'photo_path'    => $this->photo_path,
            'photo_url'     => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'whatsapp'      => $this->whatsapp,
            'phone'         => $this->phone,
            'phone_type'    => $this->phone_type,
            'email'         => $this->email,
            'contact_mode'  => $this->contact_mode,
            'show_on_site'  => (bool) $this->show_on_site,
            'publish_consent_at'          => optional($this->publish_consent_at)->toIso8601String(),
            'personal_contact_consent_at' => optional($this->personal_contact_consent_at)->toIso8601String(),
            'on_site'       => (bool) ($this->active && $this->show_on_site && $this->publish_consent_at),
            'sort'          => (int) $this->sort,
            'active'        => (bool) $this->active,
            'deactivated_at' => optional($this->deactivated_at)->toIso8601String(),
            // Acesso à plataforma: none | invited | active | revoked.
            'access_status' => $this->accessStatus(),
            'user'          => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id, 'email' => $this->user->email, 'role' => $this->user->role,
                'deactivated_at' => optional($this->user->deactivated_at)->toIso8601String(),
            ] : null),
            'invite'        => $invite && $invite->expires_at?->isFuture() ? ['email' => $invite->email, 'expires_at' => $invite->expires_at->toIso8601String()] : null,
            'created_at'    => optional($this->created_at)->toIso8601String(),
        ];
    }
}
