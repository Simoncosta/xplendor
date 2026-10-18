<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Site do consultor: um empreendimento em construção. As frações são imóveis ligados
 * (properties.development_id); o "preço desde" calcula-se das frações visíveis, nunca se grava.
 * A localização é exata (é uma obra, não a casa de alguém).
 */
class Development extends Model
{
    use SoftDeletes;

    public const PHASES = ['projeto' => 'Projeto', 'licenciamento' => 'Licenciamento', 'fundacoes' => 'Fundações', 'estrutura' => 'Estrutura',
        'acabamentos' => 'Acabamentos', 'concluido' => 'Concluído'];

    protected $fillable = ['company_id', 'name', 'slug', 'short_description', 'description', 'parish_id', 'lat', 'lng', 'construction_phase',
        'expected_completion', 'payment_plan', 'youtube_id', 'chave_nova_url', 'chave_nova_reference', 'energy_class_expected', 'featured'];

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'expected_completion' => 'date', 'featured' => 'boolean', 'published_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parish(): BelongsTo
    {
        return $this->belongsTo(Parish::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ListingImage::class, 'owner_id')->where('owner_type', ListingImage::OWNER_DEVELOPMENT)
            ->orderByDesc('is_cover')->orderBy('order')->orderBy('id');
    }

    public function scopeVisibleOnSite(Builder $q): Builder
    {
        return $q->where('site_status', 'publicado');
    }

    /** Na pré-visualização do site: também os rascunhos. */
    public function scopeVisibleInPreview(Builder $q): Builder
    {
        return $q;
    }
}
