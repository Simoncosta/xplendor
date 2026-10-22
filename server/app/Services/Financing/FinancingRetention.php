<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Company;
use App\Models\FinancingLink;
use App\Models\FinancingRequest;
use App\Services\Agency\AgencyNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Tarefa diária (interna): os documentos de financiamento e os ficheiros das retomas apagam-se
 * sozinhos N dias depois do último envio do pedido (de qualquer titular; 90 por omissão, por
 * empresa), com aviso aos administradores 7 dias antes (no sino e por email, sem dados pessoais).
 * No apagamento saem os ficheiros (do disco e do catálogo), os nomes e os dados preenchidos
 * (telemóvel, email, habitação) e as observações da retoma; o registo do pedido fica, com as
 * datas dos consentimentos. Os pedidos nunca usados apagam-se 30 dias depois de expirarem.
 */
class FinancingRetention
{
    public function __construct(private readonly AgencyNotifier $notifier) {}

    /** @return array{avisados: int, apagados: int, links_sem_uso: int} */
    public function run(): array
    {
        $stats = ['avisados' => 0, 'apagados' => 0, 'links_sem_uso' => 0];
        $now = now();
        $warn = []; // empresa => [pedidos, primeira data]
        $warnDays = (int) config('financing.warn_days_before');

        foreach (FinancingRequest::with('company')->whereNotNull('last_upload_at')->whereNull('purged_at')->orderBy('id')->cursor() as $request) {
            $purgeOn = RetentionDays::purgeOn($request);
            if ($purgeOn === null) {
                continue;
            }
            if ($now->gte($purgeOn)) {
                $this->purge($request);
                $stats['apagados']++;
            } elseif ($request->purge_warned_at === null && $now->gte($purgeOn->copy()->subDays($warnDays))) {
                $w = $warn[$request->company_id] ?? ['requests' => [], 'first' => $purgeOn];
                $w['requests'][] = $request;
                $w['first'] = $purgeOn->lt($w['first']) ? $purgeOn : $w['first'];
                $warn[$request->company_id] = $w;
            }
        }

        foreach ($warn as $companyId => $w) {
            $company = Company::find($companyId);
            if (! $company) {
                continue;
            }
            $n = count($w['requests']);
            $cars = collect($w['requests'])->pluck('car_id')->filter()->unique();
            $path = $cars->count() === 1 ? "/cars/{$cars->first()}/ficha" : '/ficheiros';
            $this->notifier->automatic()->companyAdmins($company, 'Documentos para financiamento a apagar', [
                ($n === 1 ? 'Um pedido de documentos (financiamento ou retoma) vai ser apagado' : "{$n} pedidos de documentos (financiamento ou retoma) vão ser apagados")
                    . ' automaticamente a partir de ' . $w['first']->format('d/m/Y') . '.',
                'O prazo conta-se do último envio (' . RetentionDays::for($company) . ' dias). Se ainda precisar dos documentos, descarregue-os antes.',
            ], $path, 'warning');
            foreach ($w['requests'] as $request) {
                $request->forceFill(['purge_warned_at' => $now])->save();
            }
            $stats['avisados'] += $n;
        }

        // Pedidos nunca usados: todos os links expiraram há mais de 30 dias e nunca houve um envio.
        $limit = $now->copy()->subDays((int) config('financing.unused_link_grace_days'));
        foreach (FinancingRequest::whereNull('last_upload_at')->orderBy('id')->cursor() as $request) {
            $links = FinancingLink::where('financing_request_id', $request->id)->get();
            if ($links->isNotEmpty() && $links->every(fn (FinancingLink $l) => $l->expires_at->lt($limit))) {
                FinancingFiles::deleteAll($request);
                $request->delete();
                $stats['links_sem_uso']++;
            }
        }

        if (array_sum($stats) > 0) {
            Log::info('[Financiamento] Retenção diária', $stats);
        }

        return $stats;
    }

    public function purge(FinancingRequest $request): void
    {
        FinancingFiles::deleteAll($request);
        $now = now();
        foreach (FinancingLink::where('financing_request_id', $request->id)->get() as $link) {
            $link->forceFill(['purged_at' => $now, 'customer_name' => null, 'phone' => null, 'email' => null, 'housing' => null,
                'housing_other' => null, 'housing_monthly_eur' => null, 'revoked_at' => $link->revoked_at ?? $now])->save();
        }
        $request->forceFill(['purged_at' => $now, 'notes' => null])->save();
    }
}
