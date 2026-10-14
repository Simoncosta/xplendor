<?php

declare(strict_types=1);

namespace App\Services\Compass\Imoveis;

use App\Jobs\GenerateImoveisCompassTextsJob;
use App\Models\CompassSignal;
use App\Models\RadarTopic;
use App\Services\CompanyModuleService;
use App\Services\Radar\Calendar\OfficialDates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bússola do imobiliário: os sinais (DESENHO.md, 8.3). O interruptor é o módulo imoveis.
 *  · new:{id}: entrou em carteira há 7 dias ou menos e não tem publicação em 30 dias;
 *  · review_price:{id}: disponível há 90 dias ou mais (venda) ou 45 (arrendamento), com a
 *    referência do INE da freguesia quando existe (nunca um preço sugerido);
 *  · closed:{id}: fechado nos últimos 30 dias, sem publicação de testemunho;
 *  · zone:{parish_id}: freguesia com imóveis e sem publicação da zona em 30 dias;
 *  · interest:{id}: 3 ou mais contactos (contagem manual, atualizada há 30 dias ou menos);
 *  · bce:{AAAAMMDD}: hoje há decisão de política monetária do BCE (calendário oficial).
 */
final class ImoveisSignalService
{
    public const PROVIDER = 'imobiliario';
    public const STALE_AFTER_HOURS = 24;

    public static function isEnabled(int $companyId): bool
    {
        return app(CompanyModuleService::class)->isEnabled($companyId, 'imoveis');
    }

    public static function computedAt(int $companyId): ?CarbonImmutable
    {
        $at = DB::table('compass_runs')->where('provider', self::PROVIDER)->where('company_id', $companyId)->value('computed_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    public function ensureFresh(int $companyId): void
    {
        $at = self::computedAt($companyId);
        if ($at === null || $at->lt(CarbonImmutable::now()->subHours(self::STALE_AFTER_HOURS))) {
            $this->compute($companyId);
        }
    }

    public function compute(int $companyId, ?CarbonImmutable $now = null): int
    {
        if (! self::isEnabled($companyId)) {
            return 0;
        }
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone('Europe/Lisbon')->startOfDay();
        $facts = ImoveisFacts::forCompany($companyId, $today);
        $rows = [];
        $add = function (string $key, string $type, string $subjectType, int $subjectId, string $confidence, array $numbers) use (&$rows) {
            $rows[$key] = ['signal_key' => $key, 'type' => $type, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'vehicle_type' => null,
                'confidence' => $confidence, 'numbers' => $numbers];
        };
        foreach ($facts as $id => $f) {
            $base = ['label' => $f['label'], 'listing' => $f['listing'], 'days' => $f['days']];
            if ($f['is_new'] && ! $f['posted_30']) {
                $add("new:{$id}", 'new', 'property', $id, 'alta', $base + ['energy_class' => $f['energy_class']]);
            }
            if ($f['stale'] && $f['status'] === 'disponivel') {
                $add("review_price:{$id}", 'review_price', 'property', $id, $f['position'] === 'above_ref' ? 'media' : 'alta', $base + [
                    'threshold' => $f['threshold'], 'position' => $f['position'], 'diff_pct' => $f['diff_pct'], 'price_m2' => $f['price_m2'],
                    'ine_median_m2' => $f['ine_median_m2'], 'ine_period' => $f['ine_period'], 'ine_reason' => $f['ine_reason'],
                    'muni_median_m2' => $f['muni_median_m2'], 'muni_period' => $f['muni_period'], 'municipality' => $f['municipality'],
                ]);
            }
            if ($f['interest_days'] !== null && $f['interest_days'] <= ImoveisFacts::POST_DAYS && $f['contacts_30'] >= ImoveisFacts::INTEREST_MIN_CONTACTS && ! $f['posted_30']) {
                $add("interest:{$id}", 'interest', 'property', $id, 'media', $base + ['contacts_30' => $f['contacts_30'], 'visits_30' => $f['visits_30'], 'updated_days' => $f['interest_days']]);
            }
        }
        foreach (ImoveisFacts::closed($companyId, $today) as $id => $c) {
            if (! $c['testimonial']) {
                $add("closed:{$id}", 'closed', 'property', $id, 'alta', ['label' => $c['label'], 'listing' => $c['listing'], 'closed_days' => $c['closed_days']]);
            }
        }
        foreach (ImoveisFacts::zones($facts, $companyId, $today) as $pid => $z) {
            if (! $z['posted_30']) {
                $add("zone:{$pid}", 'zone', 'parish', $pid, 'media', ['label' => $z['label'], 'properties' => $z['properties'],
                    'ine_median_m2' => $z['ine_median_m2'], 'ine_period' => $z['ine_period']]);
            }
        }
        if (OfficialDates::isBceDecisionDay($today)) {
            $topic = RadarTopic::where('kind', 'bce')->where('first_seen_at', '>=', $today->setTimezone(config('app.timezone', 'UTC')))->orderByDesc('id')->first();
            $url = $topic ? (((array) $topic->sources)[0]['url'] ?? null) : null;
            $add('bce:' . $today->format('Ymd'), 'bce', 'calendar', (int) $today->format('Ymd'), 'alta', ['label' => 'Decisão de política monetária do BCE', 'url' => $url]);
        }

        DB::transaction(function () use ($companyId, $rows, $now) {
            CompassSignal::where('provider', self::PROVIDER)->where('company_id', $companyId)->delete();
            foreach ($rows as $r) {
                CompassSignal::create($r + ['provider' => self::PROVIDER, 'company_id' => $companyId, 'computed_at' => $now]);
            }
            DB::table('compass_runs')->updateOrInsert(['provider' => self::PROVIDER, 'company_id' => $companyId], ['computed_at' => $now]);
        });
        GenerateImoveisCompassTextsJob::dispatch($companyId)->afterCommit();

        return count($rows);
    }
}
