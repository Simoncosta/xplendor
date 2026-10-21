<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Company;
use App\Models\FinancingLink;
use App\Services\Agency\AgencyNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Tarefa diária (interna): os documentos de financiamento apagam-se sozinhos N dias depois do
 * último envio (90 por omissão, por empresa), com aviso aos administradores 7 dias antes (no
 * sino e por email, sem dados pessoais). No apagamento saem os ficheiros (do disco e do
 * catálogo) e o nome do cliente; o registo do pedido fica, com as datas dos consentimentos.
 * Os links nunca usados apagam-se 30 dias depois de expirarem.
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

        foreach (FinancingLink::with('company')->whereNotNull('last_upload_at')->whereNull('purged_at')->orderBy('id')->cursor() as $link) {
            $purgeOn = RetentionDays::purgeOn($link);
            if ($purgeOn === null) {
                continue;
            }
            if ($now->gte($purgeOn)) {
                $this->purge($link);
                $stats['apagados']++;
            } elseif ($link->purge_warned_at === null && $now->gte($purgeOn->copy()->subDays($warnDays))) {
                $w = $warn[$link->company_id] ?? ['links' => [], 'first' => $purgeOn];
                $w['links'][] = $link;
                $w['first'] = $purgeOn->lt($w['first']) ? $purgeOn : $w['first'];
                $warn[$link->company_id] = $w;
            }
        }

        foreach ($warn as $companyId => $w) {
            $company = Company::find($companyId);
            if (! $company) {
                continue;
            }
            $n = count($w['links']);
            $cars = collect($w['links'])->pluck('car_id')->filter()->unique();
            $path = $cars->count() === 1 ? "/cars/{$cars->first()}/ficha" : '/ficheiros';
            $this->notifier->companyAdmins($company, 'Documentos para financiamento a apagar', [
                ($n === 1 ? 'Um pedido de documentos para financiamento vai ser apagado' : "{$n} pedidos de documentos para financiamento vão ser apagados")
                    . ' automaticamente a partir de ' . $w['first']->format('d/m/Y') . '.',
                'O prazo conta-se do último envio (' . RetentionDays::for($company) . ' dias). Se ainda precisar dos documentos, descarregue-os antes.',
            ], $path, 'warning');
            foreach ($w['links'] as $link) {
                $link->forceFill(['purge_warned_at' => $now])->save();
            }
            $stats['avisados'] += $n;
        }

        $grace = (int) config('financing.unused_link_grace_days');
        foreach (FinancingLink::whereNull('last_upload_at')->where('expires_at', '<', $now->copy()->subDays($grace))->orderBy('id')->cursor() as $link) {
            FinancingFiles::deleteAll($link);
            $link->delete();
            $stats['links_sem_uso']++;
        }

        if (array_sum($stats) > 0) {
            Log::info('[Financiamento] Retenção diária', $stats);
        }

        return $stats;
    }

    public function purge(FinancingLink $link): void
    {
        FinancingFiles::deleteAll($link);
        $link->forceFill(['purged_at' => now(), 'customer_name' => null, 'revoked_at' => $link->revoked_at ?? now()])->save();
    }
}
