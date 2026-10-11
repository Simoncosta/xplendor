<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bússola comum: ignorar (4 semanas), restaurar e "publicação criada" de um sinal. Registo só de inserção. */
class CompassSignalAction extends Model
{
    public const IGNORED = 'ignored';
    public const RESTORED = 'restored';
    public const POST_CREATED = 'post_created';

    public const UPDATED_AT = null;

    protected $fillable = ['provider', 'company_id', 'signal_key', 'action', 'hidden_until', 'editorial_post_id', 'user_id'];

    protected $casts = ['hidden_until' => 'date'];

    /** As chaves escondidas hoje (o último registo de cada chave é um "ignorado" ainda válido). @return string[] */
    public static function hiddenKeys(string $provider, int $companyId, string $today): array
    {
        $last = [];
        foreach (self::where('provider', $provider)->where('company_id', $companyId)->whereIn('action', [self::IGNORED, self::RESTORED])
            ->orderBy('id')->get(['signal_key', 'action', 'hidden_until']) as $a) {
            $last[$a->signal_key] = $a;
        }

        return array_values(array_keys(array_filter($last, fn ($a) => $a->action === self::IGNORED && $a->hidden_until && $a->hidden_until->toDateString() >= $today)));
    }
}
