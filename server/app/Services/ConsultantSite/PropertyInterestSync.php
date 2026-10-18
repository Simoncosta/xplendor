<?php

declare(strict_types=1);

namespace App\Services\ConsultantSite;

use App\Models\InboundContact;
use App\Models\Property;
use App\Models\SiteDailyCount;
use App\Services\CompanyModuleService;
use App\Services\Compass\Imoveis\ImoveisCompassTriggers;
use Carbon\CarbonImmutable;

/**
 * Site do consultor: a parte do site nas contagens de interesse da carteira, que se SOMA à parte
 * manual (os outros canais: o portal da rede, o telefone, as indicações). contacts_30 e visits_30,
 * que a Bússola do imobiliário usa, são o total. Nas empresas com o módulo site_consultor:
 *  · contacts_30_site: os contactos recebidos sobre o imóvel nos últimos 30 dias, mais os cliques
 *    no WhatsApp da página do imóvel nos últimos 30 dias;
 *  · visits_30_site: os pedidos de visita do imóvel nos últimos 30 dias.
 * Corre a cada contacto e a cada lote de eventos (só os imóveis tocados) e de noite (a janela dos
 * 30 dias anda). Nas outras empresas nada muda.
 */
class PropertyInterestSync
{
    public function __construct(private readonly CompanyModuleService $modules) {}

    /** @param int[]|null $propertyIds null = todos os imóveis da empresa @return int imóveis atualizados */
    public function refreshProperties(int $companyId, ?array $propertyIds = null): int
    {
        if (! $this->modules->isEnabled($companyId, 'site_consultor')) {
            return 0;
        }
        $today = CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $since = $today->subDays(29);
        $q = Property::where('company_id', $companyId);
        if ($propertyIds !== null) {
            $q->whereIn('id', array_values(array_unique(array_map('intval', $propertyIds))));
        }
        $properties = $q->get();
        if ($properties->isEmpty()) {
            return 0;
        }
        $ids = $properties->pluck('id')->all();
        $contacts = InboundContact::where('company_id', $companyId)->where('subject_type', InboundContact::SUBJECT_PROPERTY)->whereIn('subject_id', $ids)
            ->where('created_at', '>=', $since)->selectRaw('subject_id, COUNT(*) as total, SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as visits', ['visita'])
            ->groupBy('subject_id')->get()->keyBy('subject_id');
        $clicks = SiteDailyCount::where('company_id', $companyId)->where('subject_type', 'property')->whereIn('subject_id', $ids)->where('event', 'whatsapp_click')
            ->where('day', '>=', $since->toDateString())->groupBy('subject_id')->selectRaw('subject_id, SUM(count) as total')->pluck('total', 'subject_id');

        $changed = 0;
        foreach ($properties as $p) {
            $row = $contacts->get($p->id);
            $site = min(65535, (int) ($row->total ?? 0) + (int) ($clicks[$p->id] ?? 0));
            $siteVisits = min(65535, (int) ($row->visits ?? 0));
            $p->fill([
                'contacts_30_site' => $site,
                'visits_30_site' => $siteVisits,
                'contacts_30' => min(65535, (int) $p->contacts_30_manual + $site),
                'visits_30' => min(65535, (int) $p->visits_30_manual + $siteVisits),
            ]);
            if ($p->isDirty()) {
                $p->interest_updated_at = $today->toDateString();
                $p->saveQuietly(); // sem revalidar o site (as contagens não aparecem lá)
                $changed++;
            }
        }
        if ($changed > 0) {
            ImoveisCompassTriggers::dispatch($companyId); // a Bússola do imobiliário recalcula uma vez
        }

        return $changed;
    }
}
