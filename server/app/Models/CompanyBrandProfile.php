<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil de marca simples da empresa (1:1). Mesmos nomes de campos do brand_profiles do
 * plano Social, para a passagem para as marcas ser uma cópia direta.
 */
class CompanyBrandProfile extends Model
{
    public const LIST_FIELDS = ['words_to_use', 'words_to_avoid', 'topics_to_avoid', 'hashtags_default'];

    /** Política de emojis (mesmos valores previstos para brand_profiles na F1). */
    public const EMOJI_POLICIES = ['none', 'light', 'free'];

    protected $fillable = [
        'company_id', 'tone_of_voice', 'audience', 'words_to_use', 'words_to_avoid',
        'topics_to_avoid', 'pillars', 'hashtags_default', 'cta_default', 'emoji_policy', 'notes',
        'language', 'updated_by_user_id',
    ];

    protected $casts = [
        'words_to_use' => 'array',
        'words_to_avoid' => 'array',
        'topics_to_avoid' => 'array',
        'pillars' => 'array',
        'hashtags_default' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * O mínimo para gerar ideias: tom de voz, público e pelo menos um pilar com nome.
     * Devolve o que falta (vazio = pronto). Sem perfil, falta tudo.
     *
     * @return string[]
     */
    public static function missingForIdeas(?self $profile): array
    {
        $missing = [];
        if (trim((string) $profile?->tone_of_voice) === '') {
            $missing[] = 'o tom de voz';
        }
        if (trim((string) $profile?->audience) === '') {
            $missing[] = 'o público';
        }
        $pillars = array_filter((array) ($profile?->pillars ?? []), fn ($p) => trim((string) (is_array($p) ? ($p['name'] ?? '') : $p)) !== '');
        if (! $pillars) {
            $missing[] = 'pelo menos um pilar';
        }

        return $missing;
    }

    /** Frase para o ecrã e para a recusa do servidor; null quando o mínimo está preenchido. */
    public static function ideasBlockedReason(?self $profile): ?string
    {
        $missing = self::missingForIdeas($profile);
        if (! $missing) {
            return null;
        }
        $last = array_pop($missing);
        $list = $missing ? implode(', ', $missing) . ' e ' . $last : $last;

        return "Para gerar ideias, preencha no Perfil da Marca {$list}.";
    }

    public function isEmpty(): bool
    {
        return trim((string) $this->tone_of_voice) === '' && trim((string) $this->audience) === ''
            && empty($this->words_to_use) && empty($this->words_to_avoid) && empty($this->topics_to_avoid)
            && empty($this->pillars) && empty($this->hashtags_default)
            && trim((string) $this->cta_default) === '' && trim((string) $this->notes) === '';
    }
}
