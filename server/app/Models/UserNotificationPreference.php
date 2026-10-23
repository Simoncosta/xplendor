<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bússola 7: as preferências de avisos de uma pessoa. Sem linha, tudo ligado.
 */
class UserNotificationPreference extends Model
{
    /** O tipo de aviso (alerts.kind) dos sinais de nota alta do Radar. */
    public const RADAR_HIGH_SCORE = 'radar_high_score';

    protected $fillable = ['user_id', 'radar_high_score'];

    protected $casts = ['radar_high_score' => 'boolean'];

    public static function radarHighScoreOn(int $userId): bool
    {
        return (bool) (self::where('user_id', $userId)->value('radar_high_score') ?? true);
    }

    /** Os tipos de aviso que esta pessoa não quer ver no sino. @return list<string> */
    public static function hiddenKinds(?int $userId): array
    {
        return $userId !== null && ! self::radarHighScoreOn($userId) ? [self::RADAR_HIGH_SCORE] : [];
    }
}
