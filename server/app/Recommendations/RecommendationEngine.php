<?php

declare(strict_types=1);

namespace App\Recommendations;

use App\Models\Company;
use App\Models\CompanyRecommendationSetting;
use App\Recommendations\Contracts\RecommendationRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Motor de recomendações (regras de especialista, explicáveis).
 *
 * Para uma empresa e um ramo: corre as regras registadas desse ramo que estão
 * LIGADAS na configuração da empresa (sem linha = ligada, parâmetros por omissão),
 * junta as recomendações por PRIORIDADE (maior primeiro) e os avisos das regras que
 * não conseguiram avaliar. Calculado na hora (sem "dispensar" nesta versão).
 * Uma regra que rebente não derruba as outras.
 */
class RecommendationEngine
{
    /** @param RecommendationRule[] $rules */
    public function __construct(private readonly array $rules) {}

    /** @return RecommendationRule[] */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @return array{recommendations: array<int, array>, notices: array<int, array>}
     */
    public function forCompany(Company $company, string $vertical, ?CarbonImmutable $now = null): array
    {
        $context = new RecommendationContext($company, $vertical, $now ?? CarbonImmutable::now());

        $settings = CompanyRecommendationSetting::where('company_id', $company->id)
            ->get()
            ->keyBy('rule_key');

        $recommendations = [];
        $notices = [];

        foreach ($this->rules as $rule) {
            if (! in_array($vertical, $rule->verticals(), true)) {
                continue;
            }

            $setting = $settings->get($rule->key());
            if ($setting && ! $setting->enabled) {
                continue;
            }

            $params = $rule->normalizeParams(array_merge($rule->defaultParams(), (array) ($setting->params ?? [])));

            try {
                $result = $rule->evaluate($context, $params);
            } catch (\Throwable $e) {
                Log::error('RecommendationEngine: regra falhou', [
                    'rule' => $rule->key(), 'company_id' => $company->id, 'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($result->recommendations as $r) {
                $recommendations[] = $r;
            }
            if ($result->notice !== null) {
                $notices[] = ['rule_key' => $rule->key()] + $result->notice;
            }
        }

        usort($recommendations, fn (Recommendation $a, Recommendation $b) => $b->priority <=> $a->priority);

        return [
            'recommendations' => array_map(fn (Recommendation $r) => $r->toArray(), $recommendations),
            'notices' => $notices,
        ];
    }
}
