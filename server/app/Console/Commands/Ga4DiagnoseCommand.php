<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CompanyIntegration;
use App\Services\Ga4\Ga4ClientInterface;
use App\Services\GoogleAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Diagnóstico da leitura GA4. Mostra o ERRO REAL do Google (não a
 * mensagem genérica do painel) e, se pedido, limpa a cache do GA4 dessa empresa.
 *
 * Uso:
 *   php artisan ga4:diagnose {company_id}            # corre uma chamada mínima e mostra o resultado/erro cru
 *   php artisan ga4:diagnose {company_id} --clear    # limpa a cache antes (testar leitura fresca)
 *   php artisan ga4:diagnose {company_id} --days=28  # janela (por defeito 7)
 *
 * Distingue: acesso negado (PERMISSION_DENIED) · propriedade inexistente
 * (NOT_FOUND) · sem dados (sucesso, 0 linhas) · config errada (RuntimeException).
 */
class Ga4DiagnoseCommand extends Command
{
    protected $signature = 'ga4:diagnose {company : ID da empresa} {--days=7} {--clear : Limpar a cache GA4 antes}';

    protected $description = 'Corre uma chamada mínima à GA4 Data API e mostra o erro real do Google (diagnóstico).';

    public function handle(Ga4ClientInterface $client, GoogleAnalyticsService $service): int
    {
        $companyId = (int) $this->argument('company');
        $days = (int) $this->option('days');

        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'google')
            ->first();

        if (! $integration || empty($integration->property_id)) {
            $this->error("Empresa #{$companyId} não tem propriedade GA4 ligada (property_id vazio).");

            return self::FAILURE;
        }

        $propertyId = (int) $integration->property_id;
        $this->info("Empresa #{$companyId} · propriedade GA4: {$propertyId}");
        $this->line('SA email (config): ' . (config('services.ga4.sa_email') ?: '(não definido)'));
        $this->line('Credencial: ' . (config('services.ga4.credentials') ? 'ficheiro' : (config('services.ga4.credentials_json') ? 'JSON inline' : '(NENHUMA configurada!)')));

        if ($this->option('clear')) {
            foreach ([7, 28, 90] as $d) {
                $service->forget($companyId, $propertyId, $d);
            }
            $this->line('Cache GA4 limpa (7/28/90 dias).');
        }

        $end = CarbonImmutable::today();
        $start = $end->subDays(max(1, $days) - 1);

        $this->line("A correr chamada mínima (activeUsers, {$start->toDateString()} → {$end->toDateString()})…");

        try {
            $res = $client->runReport($propertyId, [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'metrics' => ['activeUsers'],
            ]);

            $rows = $res['rows'] ?? [];
            $value = $rows[0]['metrics'][0] ?? null;

            $this->newLine();
            $this->info('✅ ACESSO OK — a Service Account consegue ler esta propriedade.');
            $this->line('Linhas devolvidas: ' . count($rows));
            $this->line('activeUsers (período): ' . ($value ?? '0'));

            if ($value === null || (int) $value === 0) {
                $this->warn('Sem dados no período (0). Não é erro de acesso — pode ser propriedade nova/sem tráfego ou intervalo sem visitas.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $safe = \App\Services\Ga4\Ga4Redact::message($e->getMessage());
            $this->error('❌ ERRO REAL do Google (é isto que estava escondido):');
            $this->line('Exceção: ' . get_class($e));
            $this->line('Mensagem: ' . $safe); // private_key redigida
            $this->newLine();
            $this->line($this->hint($e->getMessage()));

            return self::FAILURE;
        }
    }

    /** Pista rápida em pt-PT a partir da mensagem crua do Google. */
    private function hint(string $message): string
    {
        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'permission') || str_contains($m, 'denied') || str_contains($m, '403')
                => 'PISTA: acesso negado — o email da SA não tem (ainda) permissão de Visualizador NESTA propriedade, ou a propagação ainda não terminou (pode demorar alguns minutos). Confirma em GA4 → Admin → Gestão de acesso à propriedade.',
            str_contains($m, 'not found') || str_contains($m, '404')
                => 'PISTA: propriedade não encontrada — confirma o property_id (é o número da PROPRIEDADE, não o "G-..." de medição, nem o ID da conta).',
            str_contains($m, 'credential') || str_contains($m, 'could not') || str_contains($m, 'default credentials') || str_contains($m, 'json')
                => 'PISTA: credencial da Service Account em falta/inválida — verifica GA4_SA_CREDENTIALS (caminho do JSON) ou GA4_SA_CREDENTIALS_JSON.',
            str_contains($m, 'quota') || str_contains($m, 'exhausted') || str_contains($m, '429')
                => 'PISTA: quota da Data API excedida — raro; espera e tenta mais tarde.',
            str_contains($m, 'api has not been used') || str_contains($m, 'disabled') || str_contains($m, 'not been enabled')
                => 'PISTA: a Google Analytics Data API não está ativada no projeto GCP — ativa em APIs & Services → Library.',
            default => 'PISTA: erro não catalogado — copia a mensagem acima para investigar.',
        };
    }
}
