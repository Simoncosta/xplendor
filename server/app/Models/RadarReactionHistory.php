<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bússola 4: uma decisão sobre o Radar (reagir, ignorar, não mostrar), guardada 12 meses, sem o
 * título nem o conteúdo dos artigos. Sobrevive à purga dos 30 dias das tendências e dos sinais.
 */
class RadarReactionHistory extends Model
{
    public const UPDATED_AT = null;

    public const REACTED = 'reacted';
    public const IGNORED = 'ignored';
    public const MUTED = 'muted';

    public const KIND_CALENDAR = 'calendar';

    public const RETENTION_MONTHS = 12;

    protected $table = 'radar_reaction_history';

    protected $fillable = ['company_id', 'signal_id', 'topic_kind', 'sector_slug', 'theme', 'score', 'decision', 'decided_at'];

    protected $casts = ['score' => 'integer', 'decided_at' => 'datetime'];

    /** Regista a decisão sobre um sinal: o tema é o da relevância do ramo da empresa. */
    public static function recordSignal(RadarSignal $signal, string $decision): self
    {
        $slug = Company::with('contentSector')->find($signal->company_id)?->contentSector?->slug;
        $theme = $slug ? $signal->topic?->relevances()->where('sector_slug', $slug)->value('theme') : null;

        return self::create([
            'company_id' => $signal->company_id, 'signal_id' => $signal->id, 'topic_kind' => (string) ($signal->topic?->kind ?? 'trend'),
            'sector_slug' => $slug, 'theme' => $theme, 'score' => (int) $signal->score, 'decision' => $decision, 'decided_at' => now(),
        ]);
    }

    /** Regista o "Reagir" a uma data do calendário: o tema é a chave da data (por exemplo bce.monetary_policy_decision). */
    public static function recordCalendar(Company $company, string $key): self
    {
        return self::create([
            'company_id' => $company->id, 'signal_id' => null, 'topic_kind' => self::KIND_CALENDAR,
            'sector_slug' => $company->contentSector?->slug, 'theme' => mb_substr($key, 0, 80), 'score' => 0,
            'decision' => self::REACTED, 'decided_at' => now(),
        ]);
    }
}
