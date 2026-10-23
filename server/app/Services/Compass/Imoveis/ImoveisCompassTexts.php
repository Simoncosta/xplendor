<?php

declare(strict_types=1);

namespace App\Services\Compass\Imoveis;

use App\Models\Company;
use App\Models\CompassText;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiPrompt;
use App\Services\Ai\AiSyncRequest;
use App\Services\Compass\CompassAiBudget;
use App\Services\Compass\CompassTexts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bússola do imobiliário: as frases "O que fazer" pela função comum bussola_jogadas (as mesmas
 * regras da restauração e do stand: imperativo, sem números, sem causas). Sem chave (e sem
 * AI_FAKE) ficam os modelos de frase.
 */
final class ImoveisCompassTexts
{
    public function __construct(private readonly ImoveisCompassService $compass) {}

    public function generate(int $companyId): int
    {
        if (! ImoveisSignalService::isEnabled($companyId) || ! AiGateway::available(CompassTexts::AI_FUNCTION)) {
            return 0;
        }
        $company = Company::findOrFail($companyId);
        $today = CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $plays = [];
        foreach (array_keys(ImoveisCompassService::TYPES) as $scope) {
            foreach ($this->compass->choose($this->compass->candidateGroups($this->compass->visibleSignals($companyId, $scope, $today), $scope)) as $g) {
                $plays[ImoveisCompassService::textKey($g)] = $g;
            }
        }
        if ($plays === []) {
            CompassText::where('provider', ImoveisSignalService::PROVIDER)->where('company_id', $companyId)->delete();

            return 0;
        }
        // O limite mensal da empresa (CompassAiBudget): esgotado, ficam as frases deste mês; as jogadas sem frase ficam com o modelo.
        if (! CompassAiBudget::allows($companyId)) {
            CompassAiBudget::keepThisMonthOnly(CompassText::where('provider', ImoveisSignalService::PROVIDER)->where('company_id', $companyId));

            return 0;
        }
        try {
            $result = AiSyncRequest::run(CompassTexts::AI_FUNCTION, $this->prompt($company, $plays), $companyId, null, ['input' => ['jogadas' => array_keys($plays)]]);
        } catch (\Throwable $e) {
            Log::warning('[Bússola do imobiliário] Frases da IA indisponíveis; ficam os modelos de frase.', ['company_id' => $companyId, 'tipo' => class_basename($e)]);

            return 0;
        }
        $saved = 0;
        DB::transaction(function () use ($companyId, $result, $plays, &$saved) {
            CompassText::where('provider', ImoveisSignalService::PROVIDER)->where('company_id', $companyId)->delete();
            if ($result->ptIssues !== []) {
                return;
            }
            foreach ((array) (($result->json ?? [])['jogadas'] ?? []) as $item) {
                $k = (string) ($item['chave'] ?? '');
                $text = trim((string) ($item['o_que'] ?? ''));
                if (isset($plays[$k]) && CompassTexts::acceptable($text)) {
                    CompassText::create(['provider' => ImoveisSignalService::PROVIDER, 'company_id' => $companyId, 'play_key' => $k, 'text' => $text, 'generated_at' => now()]);
                    $saved++;
                }
            }
        });

        return $saved;
    }

    private function prompt(Company $company, array $plays): AiPrompt
    {
        $lines = [];
        foreach ($plays as $k => $g) {
            $context = $g['members']->take(ImoveisCompassService::MAX_ITEMS)->map(fn ($s) => (string) ($s->numbers['label'] ?? ''))->implode('; ');
            $lines[] = "Chave: {$k}\nTipo: " . ImoveisCompassService::TYPE_META[$g['type']]['label'] . "\nO que se pretende: " . ImoveisCompassService::TEMPLATES[$g['type']]
                . "\nImóveis ou zona (só para contexto; não repita números): {$context}";
        }

        return CompassTexts::prompt((string) ($company->trade_name ?: $company->fiscal_name), CompassTexts::brand($company->id),
            'Para cada "jogada" da semana de um consultor imobiliário, escreva UMA frase com o que fazer nas redes sociais.',
            'Não invente divisões, áreas, classes energéticas, preços, vistas, valorizações nem condições que não estejam nos dados; nunca sugira um preço.', $lines);
    }
}
