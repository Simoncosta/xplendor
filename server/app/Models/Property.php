<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bússola Master: um imóvel da carteira (sem morada nem dados de pessoas). */
class Property extends Model
{
    use SoftDeletes;

    public const KINDS = ['apartamento' => 'Apartamento', 'moradia' => 'Moradia', 'terreno' => 'Terreno', 'loja' => 'Loja', 'escritorio' => 'Escritório', 'armazem' => 'Armazém', 'outro' => 'Outro'];
    public const TYPOLOGIES = ['T0', 'T1', 'T2', 'T3', 'T4', 'T5', 'T6'];
    public const LISTINGS = ['venda' => 'Venda', 'arrendamento' => 'Arrendamento'];
    public const STATUSES = ['disponivel' => 'Disponível', 'reservado' => 'Reservado', 'fechado' => 'Fechado'];
    public const AVAILABLE = ['disponivel', 'reservado'];
    public const ENERGY_CLASSES = ['A+', 'A', 'B', 'B-', 'C', 'D', 'E', 'F', 'isento', 'em_processo'];

    /** Site do consultor: o estado da construção e a publicação no site. */
    public const CONSTRUCTION_STATES = ['usado' => 'Usado', 'novo' => 'Novo', 'em_construcao' => 'Em construção'];
    public const SITE_STATUSES = ['rascunho' => 'Rascunho', 'publicado' => 'Publicado'];

    protected $fillable = ['company_id', 'reference', 'title', 'kind', 'typology', 'area_m2', 'parish_id', 'listing', 'price', 'energy_class', 'status',
        'listed_at', 'closed_at', 'contacts_30', 'visits_30', 'interest_updated_at',
        // Site do consultor (F1): campos opcionais; a Bússola não os usa.
        'slug', 'development_id', 'bedrooms', 'bathrooms', 'gross_area_m2', 'construction_state', 'approx_lat', 'approx_lng', 'approx_radius_m',
        'price_on_request', 'energy_precertificate', 'short_description', 'description', 'youtube_id', 'chave_nova_url', 'chave_nova_reference',
        'show_as_sold', 'featured', 'contacts_30_manual', 'visits_30_manual', 'contacts_30_site', 'visits_30_site'];

    protected $casts = ['listed_at' => 'date', 'closed_at' => 'date', 'interest_updated_at' => 'date', 'price' => 'integer', 'area_m2' => 'float',
        'contacts_30' => 'integer', 'visits_30' => 'integer',
        'bedrooms' => 'integer', 'bathrooms' => 'integer', 'gross_area_m2' => 'float', 'approx_lat' => 'float', 'approx_lng' => 'float',
        'approx_radius_m' => 'integer', 'price_on_request' => 'boolean', 'energy_precertificate' => 'boolean', 'show_as_sold' => 'boolean',
        'featured' => 'boolean', 'published_at' => 'datetime', 'contacts_30_manual' => 'integer', 'visits_30_manual' => 'integer',
        'contacts_30_site' => 'integer', 'visits_30_site' => 'integer'];

    /**
     * Uma área escrita como "92,1" ou "92.1" (ou "1 250,5") com ponto decimal ("1250.5"); vazio é
     * nulo. O que não for um número passa como veio, para a validação o recusar.
     */
    public static function parseArea(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value)) {
            return $value;
        }
        $v = str_replace([' ', "\u{00A0}"], '', trim($value));
        $v = str_contains($v, ',') ? str_replace(['.', ','], ['', '.'], $v) : $v;

        return is_numeric($v) ? $v : $value; // a validação confirma as duas casas decimais
    }

    /** "92,1 m²" (sem zeros à direita), ou nulo. */
    public static function areaLabel(?float $m2): ?string
    {
        if (! $m2) {
            return null;
        }

        return rtrim(rtrim(number_format($m2, 2, ',', ' '), '0'), ',') . ' m²';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parish(): BelongsTo
    {
        return $this->belongsTo(Parish::class);
    }

    public function development(): BelongsTo
    {
        return $this->belongsTo(Development::class);
    }

    /** As fotografias do site, pela ordem (a capa primeiro). */
    public function images(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ListingImage::class, 'owner_id')->where('owner_type', ListingImage::OWNER_PROPERTY)
            ->orderByDesc('is_cover')->orderBy('order')->orderBy('id');
    }

    /**
     * Na pré-visualização do site (link assinado): também os rascunhos, com a etiqueta
     * "Rascunho"; o fechado continua a exigir a autorização do proprietário.
     */
    public function scopeVisibleInPreview(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where(fn ($w) => $w->whereIn('status', self::AVAILABLE)->orWhere(fn ($c) => $c->where('status', 'fechado')->where('show_as_sold', true)));
    }

    /** Aparece no site público (o mesmo critério de scopeVisibleOnSite)? */
    public function isVisibleOnSite(): bool
    {
        return $this->site_status === 'publicado' && ! $this->energyClassMissing()
            && (in_array($this->status, self::AVAILABLE, true) || ($this->status === 'fechado' && $this->show_as_sold));
    }

    /** O endereço no site (um rascunho sem endereço tem um provisório, só para a pré-visualização). */
    public function siteSlug(): string
    {
        return $this->slug ?: "rascunho-{$this->id}";
    }

    /**
     * No site: publicado, com classe energética (também se alguém a apagar depois de publicar), e
     * disponível, reservado, ou fechado com a autorização do proprietário.
     */
    public function scopeVisibleOnSite(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('site_status', 'publicado')
            ->whereNotNull('energy_class')->whereNotIn('energy_class', ['', 'em_processo'])
            ->where(fn ($w) => $w->whereIn('status', self::AVAILABLE)->orWhere(fn ($c) => $c->where('status', 'fechado')->where('show_as_sold', true)));
    }

    /** A classe energética está em falta (vazia ou em processo). */
    public function energyClassMissing(): bool
    {
        return $this->energy_class === null || $this->energy_class === '' || $this->energy_class === 'em_processo';
    }
}
