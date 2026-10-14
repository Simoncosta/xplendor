<?php

declare(strict_types=1);

namespace App\Services\Compass\Imoveis;

use App\Models\IneHousingPrice;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bússola do imobiliário: os factos de cada imóvel em carteira, só a partir dos dados da carteira,
 * das publicações da Linha Editorial e da referência oficial do INE (o valor mediano das vendas por
 * m² da freguesia). A comparação só usa a freguesia; o valor do concelho fica como nota, sem
 * comparar. Nunca se calcula nem se sugere um preço. Sem dados de pessoas.
 */
final class ImoveisFacts
{
    public const NEW_DAYS = 7;
    public const POST_DAYS = 30;
    public const STALE_DAYS = ['venda' => 90, 'arrendamento' => 45];
    public const PRICE_BAND_PCT = 10.0;
    public const INTEREST_MIN_CONTACTS = 3;

    public static function label(Property $p): string
    {
        $what = in_array($p->kind, ['apartamento', 'moradia'], true) && $p->typology ? $p->typology : (Property::KINDS[$p->kind] ?? 'Imóvel');
        $where = $p->parish?->name ? " em {$p->parish->name}" : '';

        return "{$what}{$where} ({$p->reference})";
    }

    /** @return array<int, array<string, mixed>> property_id => factos dos imóveis em carteira (disponíveis ou reservados) */
    public static function forCompany(int $companyId, CarbonImmutable $today): array
    {
        $posted = self::postedSubjects($companyId, 'property', $today);
        $out = [];
        foreach (Property::with('parish.municipality')->where('company_id', $companyId)->whereIn('status', Property::AVAILABLE)->orderBy('id')->get() as $p) {
            $days = (int) CarbonImmutable::parse($p->listed_at)->startOfDay()->diffInDays($today, false);
            $threshold = self::STALE_DAYS[$p->listing] ?? 90;
            $ref = IneHousingPrice::latestFor($p->parish?->dicofre);
            $ref = $ref && $ref->has_value ? $ref : null;
            $muni = IneHousingPrice::latestFor($p->parish?->municipality?->dico);
            $muni = $muni && $muni->has_value ? $muni : null;
            $priceM2 = $p->listing === 'venda' && $p->area_m2 > 0 ? (int) round($p->price / $p->area_m2) : null;
            $reason = $p->listing !== 'venda' ? 'arrendamento' : (! $p->parish_id ? 'sem_freguesia' : (! $p->area_m2 ? 'sem_area' : ($ref ? null : 'sem_referencia')));
            $diff = $reason === null ? round(($priceM2 / $ref->median_m2 - 1) * 100, 1) : null;
            $out[$p->id] = [
                'property_id' => $p->id, 'listing' => $p->listing, 'status' => $p->status, 'label' => self::label($p), 'kind' => $p->kind, 'typology' => $p->typology,
                'area_m2' => $p->area_m2, 'parish_id' => $p->parish_id, 'parish' => $p->parish?->name, 'municipality' => $p->parish?->municipality?->name,
                'price' => $p->price, 'price_m2' => $priceM2, 'days' => max(0, $days), 'threshold' => $threshold, 'stale' => $days >= $threshold, 'is_new' => $days <= self::NEW_DAYS,
                'ine_reason' => $reason, 'diff_pct' => $diff,
                'position' => $diff === null ? null : ($diff >= self::PRICE_BAND_PCT ? 'above_ref' : ($diff <= -self::PRICE_BAND_PCT ? 'below_ref' : 'aligned_ref')),
                'ine_median_m2' => $ref?->median_m2, 'ine_period' => $ref?->period_label,
                'muni_median_m2' => $muni?->median_m2, 'muni_period' => $muni?->period_label,
                'contacts_30' => $p->contacts_30, 'visits_30' => $p->visits_30,
                'interest_days' => $p->interest_updated_at ? (int) CarbonImmutable::parse($p->interest_updated_at)->diffInDays($today) : null,
                'energy_class' => $p->energy_class, 'posted_30' => isset($posted[$p->id]),
            ];
        }

        return $out;
    }

    /** Os fechados nos últimos 30 dias e se já há publicação de testemunho (ligada ao imóvel depois do fecho). */
    public static function closed(int $companyId, CarbonImmutable $today): array
    {
        $out = [];
        foreach (Property::with('parish')->where('company_id', $companyId)->where('status', 'fechado')
            ->where('closed_at', '>=', $today->subDays(self::POST_DAYS - 1)->toDateString())->orderBy('id')->get() as $p) {
            $testimonial = DB::table('compass_post_subjects')->join('editorial_posts', 'editorial_posts.id', '=', 'compass_post_subjects.editorial_post_id')
                ->where('compass_post_subjects.company_id', $companyId)->where('subject_type', 'property')->where('subject_id', $p->id)
                ->where('editorial_posts.publish_date', '>=', CarbonImmutable::parse($p->closed_at)->toDateString())->exists();
            $out[$p->id] = ['property_id' => $p->id, 'label' => self::label($p), 'listing' => $p->listing,
                'closed_days' => (int) CarbonImmutable::parse($p->closed_at)->diffInDays($today), 'testimonial' => $testimonial];
        }

        return $out;
    }

    /** As freguesias com imóveis em carteira: quantos, a referência do INE e se a zona teve publicação em 30 dias. */
    public static function zones(array $facts, int $companyId, CarbonImmutable $today): array
    {
        $posted = self::postedSubjects($companyId, 'parish', $today);
        $out = [];
        foreach ($facts as $f) {
            if (! $f['parish_id']) {
                continue;
            }
            $out[$f['parish_id']] ??= ['parish_id' => $f['parish_id'], 'label' => (string) $f['parish'], 'municipality' => $f['municipality'], 'properties' => 0,
                'ine_median_m2' => $f['ine_median_m2'], 'ine_period' => $f['ine_period'], 'posted_30' => isset($posted[$f['parish_id']])];
            $out[$f['parish_id']]['properties']++;
        }

        return $out;
    }

    /** @return array<int, true> os sujeitos com publicação da Bússola nos últimos 30 dias */
    private static function postedSubjects(int $companyId, string $type, CarbonImmutable $today): array
    {
        return DB::table('compass_post_subjects')->join('editorial_posts', 'editorial_posts.id', '=', 'compass_post_subjects.editorial_post_id')
            ->where('compass_post_subjects.company_id', $companyId)->where('subject_type', $type)
            ->where('editorial_posts.publish_date', '>=', $today->subDays(self::POST_DAYS - 1)->toDateString())
            ->pluck('subject_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }
}
