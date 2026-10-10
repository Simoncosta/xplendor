<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ACL: o registo de cada alteração de perfis e de atribuições (quem, quando, o quê). */
class PermissionProfileEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'profile_id', 'actor_user_id', 'target_user_id', 'event', 'payload'];

    protected $casts = ['payload' => 'array'];

    public static function log(string $event, ?int $companyId, ?int $profileId = null, ?int $targetUserId = null, array $payload = []): self
    {
        return static::create([
            'company_id' => $companyId, 'profile_id' => $profileId, 'actor_user_id' => auth()->id(),
            'target_user_id' => $targetUserId, 'event' => $event, 'payload' => $payload,
        ]);
    }
}
