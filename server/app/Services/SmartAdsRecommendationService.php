<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarRepositoryInterface;

class SmartAdsRecommendationService
{
    public function __construct(
        protected CarRepositoryInterface $carRepository,
    ) {}

    public function generate(Car $car): ?array
    {
        $context = $this->carRepository->getSmartAdsContext($car->id, $car->company_id);

        $recommendation = $this->resolveRecommendation(
            status: $context['status'],
            views: $context['views'],
            leads: $context['leads'],
            spend: $context['spend'],
            conversionRate: $context['conversion_rate'],
            costPerLead: $context['cost_per_lead'],
            ipsScore: $context['ips_score'],
            daysInStock: $context['days_in_stock'],
        );

        return [
            ...$recommendation,
            'source' => 'smart_ads',
            'is_fallback' => false,
        ];
    }

    protected function resolveRecommendation(
        string $status,
        int $views,
        int $leads,
        float $spend,
        float $conversionRate,
        ?float $costPerLead,
        int $ipsScore,
        int $daysInStock,
    ): array {
        if (
            $status === 'active'
            && $spend == 0.0
            && $leads <= 1
            && $views >= 20
        ) {
            $totalBudget = $views >= 80 ? 60 : 30;
            $dailyBudget = $views >= 80 ? 10 : 5;

            return [
                'action' => 'test_campaign',
                'total_budget' => $totalBudget,
                'daily_budget' => $dailyBudget,
                'duration_days' => 5,
                'confidence_score' => $this->clamp(60 + min((int) floor($views / 40), 10), 60, 70),
                'expected_leads' => '2 a 5 leads',
                'title' => 'Testar campanha inicial',
                'summary' => 'Esta viatura ainda não tem dados suficientes. Recomenda-se um teste inicial para validar procura.',
                'reason' => 'Sem histórico de campanha pago suficiente para decidir com segurança.',
                'next_step' => 'Lançar campanha inicial com budget controlado',
                'creative_direction' => 'Criativo de descoberta orientado a gerar interesse inicial e recolher sinais de mercado.',
                'cta_primary_label' => 'Criar campanha',
                'cta_secondary_label' => 'Ver detalhe',
            ];
        }

        if ($leads >= 2 && $ipsScore >= 65) {
            $totalBudget = $costPerLead !== null && $costPerLead <= 30 ? 175 : 125;
            $durationDays = 5;

            return [
                'action' => 'scale_ads',
                'total_budget' => $totalBudget,
                'daily_budget' => (int) round($totalBudget / $durationDays),
                'duration_days' => $durationDays,
                'confidence_score' => $this->clamp(75 + (int) floor($ipsScore / 6) + min($leads * 2, 8) + ($views >= 300 ? 3 : 0), 75, 90),
                'expected_leads' => $this->estimateLeadRange($views, $conversionRate, 'high'),
                'title' => 'Escalar investimento nesta viatura',
                'summary' => 'Esta viatura já demonstra tração comercial e reúne sinais suficientes para acelerar investimento pago.',
                'reason' => 'IPS elevado, tráfego consistente e sinais de interesse comercial acima da média.',
                'next_step' => 'Aumentar investimento e ativar criativo com foco direto em conversão.',
                'creative_direction' => 'Criativo comercial direto, orientado a venda e resposta imediata.',
                'cta_primary_label' => 'Criar campanha',
                'cta_secondary_label' => 'Ver detalhe',
            ];
        }

        if ($spend > 0 && $leads === 0) {
            return [
                'action' => 'review_campaign',
                'total_budget' => 0,
                'daily_budget' => 0,
                'duration_days' => 0,
                'confidence_score' => $this->clamp(55 + (int) floor($ipsScore / 5) + ($views >= 100 ? 5 : 0), 55, 78),
                'expected_leads' => '1 a 3 leads',
                'title' => 'Rever campanha antes de reinvestir',
                'summary' => 'Já existiu investimento recente, mas sem sinais de conversão suficientes para recomendar novo spend.',
                'reason' => 'Existe investimento acumulado sem leads nos últimos 7 dias, o que indica necessidade de rever criativo, segmentação ou CTA.',
                'next_step' => 'Rever criativo e testar nova abordagem antes de reinvestir.',
                'creative_direction' => 'Criativo demasiado fraco para conversão direta; reforçar clareza da proposta e do CTA.',
                'cta_primary_label' => 'Rever campanha',
                'cta_secondary_label' => 'Ver detalhe',
            ];
        }

        if ($ipsScore >= 60 && $views >= 100 && $leads === 0) {
            $totalBudget = $views >= 250 ? 100 : 60;
            $durationDays = $views >= 250 ? 5 : 3;

            return [
                'action' => 'test_campaign',
                'total_budget' => $totalBudget,
                'daily_budget' => (int) round($totalBudget / $durationDays),
                'duration_days' => $durationDays,
                'confidence_score' => $this->clamp(62 + (int) floor($ipsScore / 4) + ($views >= 200 ? 4 : 0), 62, 80),
                'expected_leads' => $this->estimateLeadRange($views, max($conversionRate, 0.8), 'medium'),
                'title' => 'Testar campanha com orçamento controlado',
                'summary' => 'A viatura mostra procura suficiente para justificar um teste pago curto e controlado.',
                'reason' => 'IPS sólido e volume de visualizações relevante, mas ainda sem conversões registadas.',
                'next_step' => 'Lançar teste pago curto para validar criativo, audiência e resposta comercial.',
                'creative_direction' => 'Criativo de validação com gancho forte e promessa clara de valor.',
                'cta_primary_label' => 'Criar campanha',
                'cta_secondary_label' => 'Ver detalhe',
            ];
        }

        if ($ipsScore < 40 && $views < 20 && $leads === 0 && $daysInStock >= 45) {
            return [
                'action' => 'do_not_invest',
                'total_budget' => 0,
                'daily_budget' => 0,
                'duration_days' => 0,
                'confidence_score' => $this->clamp(35 + (int) floor($ipsScore / 4), 35, 55),
                'expected_leads' => '1 a 3 leads',
                'title' => 'Não investir em ads nesta fase',
                'summary' => 'Os sinais atuais são demasiado fracos para justificar investimento pago imediato nesta viatura.',
                'reason' => 'IPS baixo, pouco tráfego, ausência de leads e tempo em stock elevado sugerem baixa probabilidade de retorno.',
                'next_step' => 'Rever posicionamento da viatura antes de considerar investimento.',
                'creative_direction' => null,
                'cta_primary_label' => 'Ver detalhe',
                'cta_secondary_label' => 'Acompanhar evolução',
            ];
        }

        return [
            'action' => 'test_campaign',
            'total_budget' => 45,
            'daily_budget' => 9,
            'duration_days' => 5,
            'confidence_score' => $this->clamp(58 + (int) floor($ipsScore / 5) + ($views >= 50 ? 4 : 0), 58, 72),
            'expected_leads' => '2 a 5 leads',
            'title' => 'Testar campanha inicial',
            'summary' => 'Há sinais suficientes para avançar com um teste curto e recolher dados de mercado antes de escalar.',
            'reason' => 'Mesmo sem confirmação forte de performance, a melhor decisão nesta fase é gerar dados para orientar a próxima ação.',
            'next_step' => 'Arrancar com campanha inicial e medir resposta antes de escalar.',
            'creative_direction' => 'Criativo inicial focado em captar atenção e validar intenção.',
            'cta_primary_label' => 'Criar campanha',
            'cta_secondary_label' => 'Ver detalhe',
        ];
    }

    protected function estimateLeadRange(int $views, float $conversionRate, string $tier): string
    {
        $estimatedLeads = ($views * $conversionRate) / 100;

        if ($tier === 'high' || $estimatedLeads >= 6) {
            return '6 a 10 leads';
        }

        if ($tier === 'medium' || $estimatedLeads >= 3) {
            return '3 a 6 leads';
        }

        return '1 a 3 leads';
    }

    protected function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
