<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F3: registo do que se fez a um sinal (ignorar, voltar a mostrar, publicação
 * criada na Linha Editorial), com quem e quando. Só se acrescenta; nunca se apaga.
 */
class RestaurantSignalAction extends Model
{
    public const IGNORED = 'ignored';
    public const RESTORED = 'restored';
    public const POST_CREATED = 'post_created';

    protected $fillable = ['company_id', 'signal_key', 'action', 'hidden_until', 'editorial_post_id', 'user_id'];

    protected $casts = [
        'hidden_until' => 'date',
    ];

    /** Chaves dos sinais escondidos hoje (a última ação foi ignorar e ainda não passou o prazo). */
    public static function hiddenKeys(int $companyId, string $today): array
    {
        $last = [];
        foreach (self::where('company_id', $companyId)->whereIn('action', [self::IGNORED, self::RESTORED])->orderBy('id')->get() as $a) {
            $last[$a->signal_key] = $a->action === self::IGNORED ? $a->hidden_until?->toDateString() : null;
        }

        return array_keys(array_filter($last, fn ($until) => $until !== null && $until >= $today));
    }
}
