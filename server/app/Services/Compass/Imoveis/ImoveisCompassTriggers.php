<?php

declare(strict_types=1);

namespace App\Services\Compass\Imoveis;

use App\Jobs\RecomputeImoveisCompassJob;
use App\Models\IneHousingPrice;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/** Bússola do imobiliário: recalcula quando a carteira ou a referência do INE mudam (só com o módulo ligado). */
final class ImoveisCompassTriggers
{
    public const PROPERTY_FIELDS = ['status', 'price', 'area_m2', 'listing', 'parish_id', 'listed_at', 'closed_at', 'contacts_30', 'visits_30', 'interest_updated_at', 'energy_class', 'deleted_at'];

    public static function register(): void
    {
        Property::saved(function (Property $p) {
            if ($p->wasRecentlyCreated || $p->wasChanged(self::PROPERTY_FIELDS)) {
                self::dispatch((int) $p->company_id);
            }
        });
        Property::deleted(fn (Property $p) => self::dispatch((int) $p->company_id));
    }

    /** Depois de gravar a referência do INE: as empresas com imóveis em carteira. */
    public static function afterIneUpdate(): void
    {
        DB::table('properties')->whereNull('deleted_at')->distinct()->pluck('company_id')->each(fn ($id) => self::dispatch((int) $id));
    }

    public static function dispatch(int $companyId): void
    {
        if (ImoveisSignalService::isEnabled($companyId)) {
            RecomputeImoveisCompassJob::dispatch($companyId)->afterCommit();
        }
    }
}
