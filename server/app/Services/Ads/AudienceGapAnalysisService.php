<?php

namespace App\Services\Ads;

use App\Models\Car;
use App\Models\MetaAudienceInsight;

class AudienceGapAnalysisService
{
    private const GENERIC_INTERESTS = [
        'familia',
        'familias',
        'pessoas',
        'people',
        'viagens',
        'lifestyle',
    ];

    private const AUTOMOTIVE_KEYWORDS = [
        'carro',
        'carros',
        'automovel',
        'automoveis',
        'veiculo',
        'veiculos',
        'suv',
        'eletrico',
        'electrico',
        'hibrido',
        'diesel',
        'gasolina',
        'urbano',
        'familiar',
        'executivo',
    ];

    public function __construct(
        protected AudienceSuggestionService $audienceSuggestionService,
    ) {}

    public function analyze(Car $car): array
    {
        $car->loadMissing(['brand', 'model']);

        $currentTargeting = $this->getCurrentTargeting($car);
        $currentInterests = collect($currentTargeting['interests'] ?? [])
            ->filter(fn($item) => is_string($item) && trim($item) !== '')
            ->map(fn(string $item) => trim($item))
            ->values()
            ->all();

        $suggestedInterests = $this->audienceSuggestionService->suggestForCar($car);

        $currentMap = $this->mapByNormalizedName($currentInterests);
        $suggestedMap = collect($suggestedInterests)
            ->filter(fn(array $interest) => !empty($interest['name']))
            ->mapWithKeys(fn(array $interest) => [$this->normalize($interest['name']) => $interest])
            ->all();

        $keep = [];
        $remove = [];
        $add = [];

        foreach ($currentMap as $normalized => $name) {
            if (isset($suggestedMap[$normalized]) && !$this->isGenericInterest($normalized)) {
                $keep[] = $name;
                continue;
            }

            if ($this->isGenericInterest($normalized) || !isset($suggestedMap[$normalized])) {
                $remove[] = $name;
            }
        }

        foreach ($suggestedMap as $normalized => $interest) {
            if (!isset($currentMap[$normalized])) {
                $add[] = $interest['name'];
            }
        }

        $views = (int) ($car->views_count ?? $car->views()->count());
        $leads = (int) ($car->leads_count ?? $car->leads()->count());
        $conversionRate = $views > 0 ? round(($leads / $views) * 100, 2) : 0.0;

        $status = $this->resolveStatus($views, $leads, $keep, $remove, $add);
        $summary = $this->buildSummary($status, $views, $leads, $keep, $remove, $add);
        $insights = $this->buildInsights($views, $leads, $conversionRate, $keep, $remove, $add);
        $confidence = $this->buildConfidence($views, $leads, $currentInterests, $suggestedInterests);
        $impactEstimate = $this->buildImpactEstimate($remove, $add);

        return [
            'status' => $status,
            'summary' => $summary,
            'actions' => [
                'keep' => array_values(array_unique($keep)),
                'remove' => array_values(array_unique($remove)),
                'add' => array_values(array_unique($add)),
            ],
            'insights' => $insights,
            'confidence' => $confidence,
            'impact_estimate' => $impactEstimate,
            'current_targeting' => [
                'interests' => $currentInterests,
                'age_min' => $currentTargeting['age_min'] ?? null,
                'age_max' => $currentTargeting['age_max'] ?? null,
                'genders' => $currentTargeting['genders'] ?? [],
                'audience_mode' => $currentTargeting['audience_mode'] ?? null,
            ],
        ];
    }

    protected function getCurrentTargeting(Car $car): array
    {
        $latest = MetaAudienceInsight::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereNotNull('campaign_targeting_json')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        return $latest?->campaign_targeting_json ?? [
            'interests' => [],
            'age_min' => null,
            'age_max' => null,
            'genders' => [],
            'audience_mode' => null,
        ];
    }

    protected function mapByNormalizedName(array $names): array
    {
        return collect($names)
            ->mapWithKeys(fn(string $name) => [$this->normalize($name) => $name])
            ->all();
    }

    protected function resolveStatus(int $views, int $leads, array $keep, array $remove, array $add): string
    {
        if ($views > 200 && $leads === 0 && (count($remove) > 0 || count($add) > 1)) {
            return 'critical';
        }

        if (count($remove) > 0 || count($add) > 0 || empty($keep)) {
            return 'needs_optimization';
        }

        return 'good';
    }

    protected function buildSummary(string $status, int $views, int $leads, array $keep, array $remove, array $add): string
    {
        if ($status === 'critical') {
            return 'Público atual pode estar a limitar seriamente a conversão desta campanha.';
        }

        if ($status === 'good') {
            return $leads > 0
                ? 'Campanha com base de público saudável e sinais de conversão a manter.'
                : 'Público atual está alinhado, com ajustes pontuais apenas se quiseres escalar.';
        }

        if ($views < 100) {
            return 'Campanha com pouca distribuição e espaço para otimizar a cobertura do público.';
        }

        if (count($remove) > 0) {
            return 'Campanha com interesses a mais ou demasiado amplos a pedir otimização.';
        }

        if (count($add) > 0) {
            return 'Faltam interesses mais específicos para aproximar o público do comprador certo.';
        }

        return 'Há oportunidade de otimização no público atual.';
    }

    protected function buildInsights(int $views, int $leads, float $conversionRate, array $keep, array $remove, array $add): array
    {
        $insights = [];

        foreach ($remove as $interest) {
            $insights[] = "O interesse '{$interest}' pode estar a limitar a precisão do público.";
        }

        if (!empty($add)) {
            $insights[] = 'Faltam interesses mais próximos do carro e da intenção de compra.';
        }

        if ($views > 200 && $leads === 0) {
            $insights[] = 'Existe procura suficiente para testar mudanças de público antes de culpar apenas o criativo.';
        } elseif ($views < 100) {
            $insights[] = 'A campanha ainda tem pouca distribuição, por isso o público pode estar demasiado fechado.';
        } elseif ($leads > 0) {
            $insights[] = 'O público atual já mostra algum valor, por isso faz sentido preservar o que funciona.';
        }

        if ($conversionRate > 0) {
            $insights[] = "A taxa de conversão atual está em {$conversionRate}% e ajuda a validar parte do público.";
        }

        if (empty($insights)) {
            $insights[] = 'Ainda não existem sinais suficientes para uma leitura forte do público.';
        }

        return array_values(array_slice(array_unique($insights), 0, 4));
    }

    protected function buildConfidence(int $views, int $leads, array $currentInterests, array $suggestedInterests): int
    {
        $score = 40;

        if ($views >= 100) {
            $score += 15;
        }

        if ($views >= 200) {
            $score += 10;
        }

        if ($leads > 0) {
            $score += 10;
        }

        if (count($currentInterests) > 0) {
            $score += 10;
        }

        if (count($suggestedInterests) >= 2) {
            $score += 10;
        }

        return max(25, min(95, $score));
    }

    protected function buildImpactEstimate(array $remove, array $add): string
    {
        if ((count($remove) + count($add)) > 2) {
            return 'Alto impacto esperado na conversão';
        }

        if (!empty($add) && empty($remove)) {
            return 'Melhoria incremental';
        }

        if (!empty($remove) || !empty($add)) {
            return 'Impacto moderado esperado na qualidade do público';
        }

        return 'Sem necessidade de alteração imediata';
    }

    protected function isGenericInterest(string $normalizedName): bool
    {
        foreach (self::GENERIC_INTERESTS as $keyword) {
            if (str_contains($normalizedName, $keyword)) {
                return true;
            }
        }

        return $this->scoreInterestQuality($normalizedName) < 20;
    }

    protected function scoreInterestQuality(string $normalizedName): int
    {
        $car = 0;

        if ($this->containsAny($normalizedName, self::AUTOMOTIVE_KEYWORDS)) {
            $car += 20;
        }

        if ($this->containsAny($normalizedName, self::GENERIC_INTERESTS)) {
            $car -= 40;
        }

        return $car;
    }

    protected function containsAny(string $value, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($value, $this->normalize($keyword))) {
                return true;
            }
        }

        return false;
    }

    protected function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));

        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ];

        return strtr($value, $map);
    }
}
