<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Models\Car;
use App\Models\Company;
use App\Models\CompassText;
use App\Services\Ai\AiFunctionSettings;
use App\Services\Ai\AiSyncRequest;
use App\Services\Compass\CompassTexts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bússola do stand: a frase "O quê" de cada jogada (os três seletores: Todos, Carros e
 * Autocaravanas), com a função de IA bussola_jogadas e o núcleo comum (o perfil da marca: o
 * tom e as palavras a usar e a evitar; sem números inventados nem causas; o verificador do
 * português). Os dados de cada veículo vão como factos (marca, modelo, ano, quilómetros,
 * preço). Sem IA configurada, ou com uma frase que não passa, fica o modelo de frase.
 */
final class StandCompassTexts
{
    public function __construct(private readonly StandCompassService $compass) {}

    public function generate(int $companyId): int
    {
        $settings = AiFunctionSettings::for(CompassTexts::AI_FUNCTION);
        if (! StandSignalService::isEnabled($companyId) || (string) config("ai.providers.{$settings['provider']}.key") === '') {
            return 0;
        }
        $company = Company::findOrFail($companyId);
        $today = CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $plays = [];
        foreach (array_keys(StandCompassService::TYPES) as $scope) {
            foreach ($this->compass->choose($this->compass->candidateGroups($this->compass->visibleSignals($companyId, $scope, $today), $scope)) as $g) {
                $plays[$g['key']] = $g;
            }
        }
        if ($plays === []) {
            CompassText::where('provider', StandSignalService::PROVIDER)->where('company_id', $companyId)->delete();

            return 0;
        }
        try {
            $result = AiSyncRequest::run(CompassTexts::AI_FUNCTION, $this->prompt($company, $plays), $companyId, null, ['input' => ['jogadas' => array_keys($plays)]]);
        } catch (\Throwable $e) {
            Log::warning('[Bússola do stand] Frases da IA indisponíveis; ficam os modelos de frase.', ['company_id' => $companyId, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return 0;
        }
        $saved = 0;
        DB::transaction(function () use ($companyId, $result, $plays, &$saved) {
            CompassText::where('provider', StandSignalService::PROVIDER)->where('company_id', $companyId)->delete();
            if ($result->ptIssues !== []) {
                return;
            }
            foreach ((array) (($result->json ?? [])['jogadas'] ?? []) as $item) {
                $k = (string) ($item['chave'] ?? '');
                $text = trim((string) ($item['o_que'] ?? ''));
                if (! isset($plays[$k]) || ! CompassTexts::acceptable($text)) {
                    continue;
                }
                CompassText::create(['provider' => StandSignalService::PROVIDER, 'company_id' => $companyId, 'play_key' => $k, 'text' => $text, 'generated_at' => now()]);
                $saved++;
            }
        });

        return $saved;
    }

    public function prompt(Company $company, array $plays): \App\Services\Ai\AiPrompt
    {
        $lines = [];
        foreach ($plays as $k => $g) {
            $cars = Car::with(['brand', 'model'])->whereIn('id', $g['members']->pluck('subject_id')->take(StandCompassService::MAX_ITEMS)->all())->get()->keyBy('id');
            $vehicles = $g['members']->take(StandCompassService::MAX_ITEMS)->map(function ($s) use ($cars) {
                $car = $cars->get((int) $s->subject_id);

                return $car ? sprintf('%s %s, %s, %s km, %s €', $car->brand?->name, $car->model?->name, $car->registration_year ?: 'ano por indicar',
                    $car->mileage_km !== null ? number_format((int) $car->mileage_km, 0, ',', ' ') : 'por indicar',
                    \App\Support\PricePosition::effectivePriceForCar($car) !== null ? number_format((float) \App\Support\PricePosition::effectivePriceForCar($car), 0, ',', ' ') : 'por indicar') : null;
            })->filter()->values()->all();
            $lines[] = "Chave: {$k}\nTipo: " . StandCompassService::TYPE_META[$g['type']]['label'] . "\nO que se pretende: " . StandCompassService::TEMPLATES[$g['type']]
                . "\nVeículos (só para contexto; não repita números):\n- " . implode("\n- ", $vehicles);
        }

        return CompassTexts::prompt(
            (string) ($company->trade_name ?: $company->fiscal_name),
            CompassTexts::brand($company->id),
            'Para cada "jogada" da semana de um stand de veículos, escreva UMA frase com o que fazer nas redes sociais ou nos anúncios.',
            'Não invente equipamentos, garantias, descontos nem promoções que não estejam nos dados.',
            $lines,
        );
    }
}
