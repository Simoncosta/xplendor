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

    protected $fillable = ['company_id', 'reference', 'title', 'kind', 'typology', 'area_m2', 'parish_id', 'listing', 'price', 'energy_class', 'status',
        'listed_at', 'closed_at', 'contacts_30', 'visits_30', 'interest_updated_at'];

    protected $casts = ['listed_at' => 'date', 'closed_at' => 'date', 'interest_updated_at' => 'date', 'price' => 'integer', 'area_m2' => 'integer',
        'contacts_30' => 'integer', 'visits_30' => 'integer'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parish(): BelongsTo
    {
        return $this->belongsTo(Parish::class);
    }

    /** A classe energética está em falta (vazia ou em processo). */
    public function energyClassMissing(): bool
    {
        return $this->energy_class === null || $this->energy_class === '' || $this->energy_class === 'em_processo';
    }
}
