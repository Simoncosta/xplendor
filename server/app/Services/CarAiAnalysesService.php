<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAiAnalysis;
use App\Models\MetaAudienceInsight;
use App\Repositories\Contracts\CarAiAnalysesRepositoryInterface;
use App\Services\PromptBuilders\VehiclePromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CarAiAnalysesService extends BaseService
{
    private const OPENAI_TIMEOUT_SECONDS = 45;
    private const OPENAI_CONNECT_TIMEOUT_SECONDS = 10;
    private const OPENAI_MAX_ATTEMPTS = 3;
    private const OPENAI_BACKOFF_MS = [800, 1800];

    public function __construct(
        protected CarAiAnalysesRepositoryInterface $carAiAnalysesRepository,
        protected CarMarketIntelligenceService $carMarketIntelligenceService,
        protected VehiclePromptBuilder $vehiclePromptBuilder,
    ) {
        parent::__construct($carAiAnalysesRepository);
    }

    // -------------------------------------------------------------------------
    // Ponto de entrada público
    // -------------------------------------------------------------------------

    /**
     * Gera e persiste a análise IA para um carro.
     * Chamado pelo CarService — recebe o modelo Car com brand e model carregados.
     */
    public function generate(Car $car): CarAiAnalysis
    {
        $inputData  = $this->buildInputData($car);
        $rawJson    = null;

        try {
            $rawJson = $this->callOpenAi($car, $inputData);
            $parsed = $this->parseResponse($rawJson, $car);

            return $this->persist($car, $inputData, $rawJson, $parsed);
        } catch (\Throwable $exception) {
            $this->persistFailure($car, $inputData, $rawJson, $exception);
            throw new \RuntimeException(
                'Nao foi possivel gerar a analise IA desta viatura neste momento. Tenta novamente dentro de instantes.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Construção dos dados de input
    // -------------------------------------------------------------------------

    /**
     * Mapeia os dados do carro (técnicos + performance) para os placeholders do prompt.
     */
    private function buildInputData(Car $car): array
    {
        $viewsTotal        = $car->views_count        ?? $car->views()->count();
        $leadsTotal        = $car->leads_count        ?? $car->leads()->count();
        $interactionsTotal = $car->interactions_count ?? $car->interactions()->count();
        $views7d           = $car->views()->where('created_at', '>=', now()->subDays(7))->count();
        $views14d          = $car->views()->where('created_at', '>=', now()->subDays(14))->count();
        $views30d          = $car->views()->where('created_at', '>=', now()->subDays(30))->count();
        $views24h          = $car->views()->where('created_at', '>=', now()->subDay())->count();
        $leads7d           = $car->leads()->where('created_at', '>=', now()->subDays(7))->count();
        $leads14d          = $car->leads()->where('created_at', '>=', now()->subDays(14))->count();
        $leads30d          = $car->leads()->where('created_at', '>=', now()->subDays(30))->count();
        $interactions7d    = $car->interactions()->where('created_at', '>=', now()->subDays(7))->count();
        $interactions14d   = $car->interactions()->where('created_at', '>=', now()->subDays(14))->count();
        $interactions30d   = $car->interactions()->where('created_at', '>=', now()->subDays(30))->count();
        $daysInStock       = $car->created_at ? (int) $car->created_at->diffInDays(now()) : null;
        $engagementTotal   = $leadsTotal + $interactionsTotal;
        $taxaInteresse     = $viewsTotal > 0 ? round(($engagementTotal / $viewsTotal) * 100, 2) : 0;

        $extrasFlat = collect($car->extras ?? [])
            ->flatMap(fn($group) => $group['items'] ?? [])
            ->filter()
            ->values()
            ->implode(', ') ?: null;

        $marketIntelligence = $this->carMarketIntelligenceService->analyze($car);
        $campaignTargeting = $this->getCampaignTargeting($car);
        $campaignPerformance = $this->getCampaignPerformance($car);
        $campaignDiagnostics = $this->buildCampaignDiagnostics($car, $campaignPerformance, $campaignTargeting, $marketIntelligence);
        $roiInsights = $this->buildRoiInsights($car, $campaignPerformance);

        return [
            'car' => [
                'marca' => $car->brand->name ?? null,
                'modelo' => $car->model->name ?? null,
                'versao' => $car->version ?? null,
                'ano' => $car->registration_year,
                'combustivel' => $car->fuel_type,
                'cambio' => $car->transmission,
                'quilometragem' => $car->mileage_km,
                'cor' => $car->exterior_color,
                'preco' => $car->price_gross ? (float) $car->price_gross : null,
                'extras' => $extrasFlat,
                'segmento' => $car->segment,
            ],
            'performance' => [
                'views_total' => $viewsTotal,
                'views_24h' => $views24h,
                'views_7d' => $views7d,
                'views_14d' => $views14d,
                'views_30d' => $views30d,
                'leads_total' => $leadsTotal,
                'leads_7d' => $leads7d,
                'leads_14d' => $leads14d,
                'leads_30d' => $leads30d,
                'interacoes_total' => $interactionsTotal,
                'interacoes_7d' => $interactions7d,
                'interacoes_14d' => $interactions14d,
                'interacoes_30d' => $interactions30d,
                'engagement_total' => $engagementTotal,
                'taxa_interesse' => $taxaInteresse,
                'dias_em_stock' => $daysInStock,
            ],
            'market_intelligence' => $marketIntelligence,
            'campaign_targeting' => $campaignTargeting,
            'campaign_targeting_context' => $this->buildTargetingContext($campaignTargeting),
            'suggested_targeting_fixes' => $this->buildTargetingFixes($campaignTargeting, $car),
            'campaign_performance' => $campaignPerformance,
            'campaign_diagnostics' => $campaignDiagnostics,
            'roi_insights' => $roiInsights,
        ];
    }

    private function buildTargetingContext(array $targeting): array
    {
        $interests = $targeting['interests'] ?? [];
        $genders = $targeting['genders'] ?? [];
        $ageMin = $targeting['age_min'] ?? null;
        $ageMax = $targeting['age_max'] ?? null;

        return [
            'is_broad' => empty($interests),
            'is_gender_restricted' => count($genders) === 1,
            'interest_count' => count($interests),
            'age_span' => ($ageMin !== null && $ageMax !== null)
                ? ((int)$ageMax - (int)$ageMin)
                : null,
        ];
    }

    private function getCampaignTargeting(Car $car): array
    {
        $latest = MetaAudienceInsight::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereNotNull('campaign_targeting_json')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        return $latest?->campaign_targeting_json ?? [
            'location' => [],
            'age_min' => null,
            'age_max' => null,
            'genders' => [],
            'interests' => [],
            'audience_mode' => null,
        ];
    }

    private function getCampaignPerformance(Car $car): array
    {
        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        $metrics = DB::table('car_performance_metrics')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where('channel', 'paid')
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->selectRaw('
                COALESCE(SUM(impressions), 0) as impressions,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(sessions), 0) as sessions,
                COALESCE(SUM(leads_count), 0) as leads,
                COALESCE(SUM(interactions_count), 0) as interactions,
                COALESCE(SUM(spend_amount), 0) as spend
            ')
            ->first();

        $audience = DB::table('meta_audience_insights')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->selectRaw('
                COALESCE(SUM(reach), 0) as reach,
                COALESCE(SUM(clicks), 0) as audience_clicks,
                COALESCE(SUM(impressions), 0) as audience_impressions,
                COALESCE(SUM(spend), 0) as audience_spend
            ')
            ->first();

        $impressions = (int) ($metrics->impressions ?? 0);
        $clicks = (int) ($metrics->clicks ?? 0);
        $reach = (int) ($audience->reach ?? 0);
        $spend = round((float) ($metrics->spend ?? 0), 2);
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;
        $cpc = $clicks > 0 ? round($spend / $clicks, 2) : null;
        $frequency = $reach > 0 ? round($impressions / $reach, 2) : null;

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'reach' => $reach,
            'spend' => $spend,
            'ctr' => $ctr,
            'cpc' => $cpc,
            'frequency' => $frequency,
            'sessions' => (int) ($metrics->sessions ?? 0),
            'leads' => (int) ($metrics->leads ?? 0),
            'interactions' => max(
                (int) ($metrics->interactions ?? 0),
                (int) ($car->interactions_count ?? $car->interactions()->count())
            ),
        ];
    }

    private function buildCampaignDiagnostics(
        Car $car,
        array $campaignPerformance,
        array $campaignTargeting,
        array $marketIntelligence
    ): array {
        $impressions = (int) ($campaignPerformance['impressions'] ?? 0);
        $clicks = (int) ($campaignPerformance['clicks'] ?? 0);
        $reach = (int) ($campaignPerformance['reach'] ?? 0);
        $spend = (float) ($campaignPerformance['spend'] ?? 0);
        $ctr = (float) ($campaignPerformance['ctr'] ?? 0);
        $sessions = (int) ($campaignPerformance['sessions'] ?? 0);
        $leads = (int) ($campaignPerformance['leads'] ?? 0);
        $interactions = (int) ($campaignPerformance['interactions'] ?? 0);

        $genderRestricted = count($campaignTargeting['genders'] ?? []) === 1;
        $ageSpan = (
            isset($campaignTargeting['age_min'], $campaignTargeting['age_max'])
            && $campaignTargeting['age_min'] !== null
            && $campaignTargeting['age_max'] !== null
        )
            ? ((int) $campaignTargeting['age_max'] - (int) $campaignTargeting['age_min'])
            : null;
        $interestCount = count($campaignTargeting['interests'] ?? []);
        $generalistCar = !in_array((string) ($car->segment ?? ''), ['premium', 'executive', 'luxury', 'sports'], true)
            && (float) ($car->price_gross ?? 0) <= 30000;

        $diagnostics = [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'reach' => $reach,
            'spend' => $spend,
            'ctr' => $ctr,
            'cpc' => $campaignPerformance['cpc'],
            'frequency' => $campaignPerformance['frequency'],
            'engagement_signals' => [
                'high_impressions_low_clicks' => $impressions >= 5000 && $ctr < 1.0,
                'low_visibility' => $reach > 0 ? $reach < 1500 : $impressions < 1500,
                'high_spend_low_result' => $spend >= 10 && $interactions === 0,
                'targeting_may_be_too_narrow' => (
                    ($generalistCar && $genderRestricted)
                    || ($generalistCar && $interestCount >= 2)
                    || ($ageSpan !== null && $ageSpan <= 10)
                ),
            ],
        ];

        $mainProblem = 'mixed';

        // REGRA CRÍTICA: boa entrega (CTR >= 2 e clicks >= 100) — targeting nunca pode ser o problema
        if ($ctr >= 2 && $clicks >= 100) {
            if ($sessions >= 40 && $interactions === 0) {
                // Sessões altas sem conversão → problema de oferta
                $mainProblem = 'offer';
            } elseif ($interactions >= 1) {
                // Já tem interações reais → não forçar offer, deixar em mixed
                $mainProblem = 'mixed';
            } else {
                // Boa entrega sem conversão → problema de oferta
                $mainProblem = 'offer';
            }
        } else {
            $mainProblem = 'mixed';

            if ($diagnostics['engagement_signals']['targeting_may_be_too_narrow'] && $ctr < 1.0) {
                $mainProblem = 'targeting';
            } elseif ($clicks >= 100 && $sessions >= 40 && $leads === 0 && $interactions === 0) {
                $mainProblem = 'offer';
            } elseif ($sessions >= 40 && $interactions === 0 && ($marketIntelligence['market_position'] ?? null) === 'above_market') {
                $mainProblem = 'offer';
            } elseif ($impressions >= 3000 && $clicks <= 20) {
                $mainProblem = 'copy';
            } elseif ($impressions < 1000 && $reach < 800) {
                $mainProblem = 'low_signal';
            }
        }

        $diagnostics['main_problem'] = $mainProblem;

        return $diagnostics;
    }

    private function buildTargetingFixes(array $targeting, Car $car): array
    {
        $actions = [];

        $interests = $targeting['interests'] ?? [];
        $genders = $targeting['genders'] ?? [];
        $ageMin = $targeting['age_min'] ?? null;
        $ageMax = $targeting['age_max'] ?? null;

        $isGeneralist = (float) ($car->price_gross ?? 0) <= 30000;

        if ($isGeneralist && count($genders) === 1) {
            $actions[] = 'Remover restrição de género';
        }

        if ($isGeneralist && count($interests) >= 2) {
            $names = collect($interests)
                ->map(fn($i) => is_array($i) ? ($i['name'] ?? null) : $i)
                ->filter()
                ->take(3)
                ->map(fn($name) => "'{$name}'")
                ->implode(', ');

            $actions[] = $names
                ? "Remover interesses {$names} e testar público broad"
                : "Remover interesses e testar público broad";
        }

        if ($ageMin !== null && $ageMax !== null && ($ageMax - $ageMin) <= 10) {
            $actions[] = 'Alargar intervalo de idades';
        }

        return $actions;
    }

    private function buildRoiInsights(Car $car, array $campaignPerformance): array
    {
        $leads = (int) ($campaignPerformance['leads'] ?? 0);
        $spend = (float) ($campaignPerformance['spend'] ?? 0);
        $sessions = (int) ($campaignPerformance['sessions'] ?? 0);
        $costPerLead = $leads > 0 ? round($spend / $leads, 2) : null;
        $conversionRate = $sessions > 0 ? round(($leads / $sessions) * 100, 2) : null;

        return [
            'cost_per_lead' => $costPerLead,
            'conversion_rate' => $conversionRate,
            'paid_sessions' => $sessions,
            'paid_leads' => $leads,
            'paid_spend' => round($spend, 2),
            'signal_strength' => match (true) {
                $sessions >= 60 || $leads >= 3 => 'strong',
                $sessions >= 20 || $leads >= 1 => 'moderate',
                default => 'weak',
            },
        ];
    }

    private function outputSchema(): array
    {
        return [
            'score_conversao' => [
                'valor'         => 72,
                'classificacao' => 'Alto',
                'justificacao'  => 'Interesse acima da média com pouco tempo em stock.',
            ],
            'alerta_preco' => [
                'ativo'             => false,
                'desvio_percentual' => null,
                'recomendacao'      => null,
            ],
            'publico_alvo' => [
                'faixa_etaria'            => '35-50 anos',
                'genero_predominante'     => 'Masculino',
                'perfil_profissional'     => 'Quadro medio ou empresario',
                'estilo_de_vida'          => 'Familia e deslocacoes frequentes',
                'comportamento_de_compra' => 'Pesquisa, compara e decide apos test drive',
            ],
            'canal_principal' => [
                'canal'        => 'Meta Ads',
                'justificacao' => 'Boa combinacao entre apelo visual e publico-alvo.',
            ],
            'canal_secundario' => [
                'canal'        => 'Google Ads',
                'justificacao' => 'Capta procura ativa de quem ja esta a comparar.',
            ],
            'criativo' => [
                'formato_principal'  => 'Reels 9:16',
                'formato_secundario' => 'Carrossel',
                'tom_de_comunicacao' => 'Racional com aspiracional',
                'justificacao'       => 'Adequado ao perfil comprador e ao estado atual da viatura.',
            ],
            'sugestao_conteudo' => [
                'titulo_anuncio' => 'Peugeot 3008 GT Line pronto a usar',
                'hook_video'     => 'Abrir no detalhe mais forte da viatura nos primeiros segundos.',
                'copy_curto'     => "Revisoes em dia.\nTest drive disponivel esta semana.",
            ],
            'argumentos_de_venda' => [
                'Consumo competitivo no segmento',
                'Equipamento valorizado pelo comprador',
                'Historico e estado ajudam a reduzir objecoes',
            ],
            'recomendacao_urgencia' => [
                'nivel'            => 'Alta',
                'acao_recomendada' => 'Ativar campanha paga e acelerar follow-up.',
            ],
            'previsao' => [
                'probabilidade_venda_7d'  => 35,
                'probabilidade_venda_14d' => 58,
                'probabilidade_venda_30d' => 76,
                'condicao'                => 'Ajustes de preco ou distribuicao podem acelerar a venda.',
            ],
            'audience_analysis' => [
                'status' => 'too_narrow',
                'issues' => [
                    'Segmentacao por genero limita alcance.',
                    'Interesses demasiado restritivos para este tipo de viatura.',
                ],
                'recommendation' => 'Alargar publico, remover interesses e manter Advantage+ para ganhar distribuicao.',
            ],
            'campaign_diagnosis' => [
                'main_problem' => 'targeting',
                'message' => 'Segmentação demasiado restrita está a limitar a entrega da campanha.',
                'action' => 'Remover interesses, eliminar restrição por género e testar público mais aberto com Advantage+.'
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Chamada à API
    // -------------------------------------------------------------------------

    private function callOpenAi(Car $car, array $inputData): string
    {
        $apiKey = config('services.openai.key');
        $prompts = $this->vehiclePromptBuilder->build($car, [
            'input_data' => $inputData,
            'output_schema' => $this->outputSchema(),
        ]);
        $systemPrompt = $prompts['system_prompt'] ?? '';
        $userPrompt = $prompts['user_prompt'] ?? '';
        $promptSize = mb_strlen($systemPrompt) + mb_strlen($userPrompt);
        $lastException = null;

        for ($attempt = 1; $attempt <= self::OPENAI_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->connectTimeout(self::OPENAI_CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::OPENAI_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model'       => 'gpt-4o',
                        'temperature' => 0.2,
                        'max_tokens'  => 1800,
                        'messages'    => [
                            [
                                'role'    => 'system',
                                'content' => $systemPrompt,
                            ],
                            [
                                'role'    => 'user',
                                'content' => $userPrompt,
                            ],
                        ],
                    ]);

                if ($response->failed()) {
                    $status = $response->status();
                    $body = $this->truncateForLog($response->body());

                    Log::warning('CarAiAnalysesService: OpenAI request failed', [
                        'car_id' => $car->id,
                        'company_id' => $car->company_id,
                        'attempt' => $attempt,
                        'prompt_size' => $promptSize,
                        'status_code' => $status,
                        'response_body' => $body,
                    ]);

                    if ($this->shouldRetryStatus($status) && $attempt < self::OPENAI_MAX_ATTEMPTS) {
                        usleep(self::OPENAI_BACKOFF_MS[$attempt - 1] * 1000);
                        continue;
                    }

                    $response->throw();
                }

                $content = $response->json('choices.0.message.content');
                if (!is_string($content) || trim($content) === '') {
                    throw new \RuntimeException('OpenAI devolveu conteúdo vazio.');
                }

                return $content;
            } catch (ConnectionException | RequestException | \RuntimeException $exception) {
                $lastException = $exception;

                Log::warning('CarAiAnalysesService: OpenAI attempt exception', [
                    'car_id' => $car->id,
                    'company_id' => $car->company_id,
                    'attempt' => $attempt,
                    'prompt_size' => $promptSize,
                    'exception' => $exception->getMessage(),
                ]);

                if (!$this->shouldRetryException($exception) || $attempt === self::OPENAI_MAX_ATTEMPTS) {
                    break;
                }

                usleep(self::OPENAI_BACKOFF_MS[$attempt - 1] * 1000);
            }
        }

        throw new \RuntimeException(
            'OpenAI indisponivel ou instavel ao gerar a analise.',
            previous: $lastException
        );
    }

    // -------------------------------------------------------------------------
    // Parse e validação
    // -------------------------------------------------------------------------

    private function parseResponse(string $rawJson, Car $car): array
    {
        $clean = trim($rawJson);
        if ($clean === '') {
            Log::error('CarAiAnalysesService: resposta vazia da OpenAI', [
                'car_id' => $car->id,
                'company_id' => $car->company_id,
            ]);
            throw new \RuntimeException('A IA devolveu uma resposta vazia.');
        }

        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/i', '', $clean) ?? $clean;
        $clean = trim($clean);

        $parsed = json_decode($clean, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        $jsonChunk = $this->extractJsonObject($clean);
        if ($jsonChunk !== null) {
            $parsed = json_decode($jsonChunk, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }
        }

        Log::error('CarAiAnalysesService: JSON invalido da OpenAI', [
            'car_id' => $car->id,
            'company_id' => $car->company_id,
            'error' => json_last_error_msg(),
            'raw' => $this->truncateForLog($rawJson, 1000),
        ]);

        throw new \RuntimeException('A IA devolveu uma resposta invalida e nao foi possivel interpreta-la.');
    }

    // -------------------------------------------------------------------------
    // Persistência
    // -------------------------------------------------------------------------

    /**
     * Applies deterministic business rules to the AI-parsed output before persistence.
     * Prevents the AI from returning "targeting" as main_problem when campaign delivery
     * metrics already prove the audience is working.
     */
    private function enforceCampaignDiagnosisRules(array &$parsed, array $inputData): void
    {
        $ctr          = (float) ($inputData['campaign_performance']['ctr']          ?? 0);
        $clicks       = (int)   ($inputData['campaign_performance']['clicks']       ?? 0);
        $sessions     = (int)   ($inputData['campaign_performance']['sessions']     ?? 0);
        $interactions = (int)   ($inputData['campaign_performance']['interactions'] ?? 0);

        $goodDelivery = $ctr >= 2.0 && $clicks >= 100;

        if (!$goodDelivery) {
            return;
        }

        $mainProblem = $parsed['campaign_diagnosis']['main_problem'] ?? null;

        // Rule A: high sessions, zero interactions → force "offer" regardless of AI output
        if ($sessions >= 40 && $interactions === 0) {
            if ($mainProblem !== 'offer') {
                $parsed['campaign_diagnosis']['main_problem'] = 'offer';
                $parsed['campaign_diagnosis']['message']      = 'A campanha tem boa entrega e sessoes relevantes, mas nao esta a gerar contactos. O problema esta na oferta, preco ou friccao pos-clique.';
                $parsed['campaign_diagnosis']['action']       = 'Rever preco, melhorar proposta de valor e reduzir friccao no processo de contacto.';
            }
            return;
        }

        // Rule B: good delivery — "targeting" is never the main problem
        if ($mainProblem === 'targeting') {
            if ($interactions >= 1) {
                $parsed['campaign_diagnosis']['main_problem'] = 'mixed';
                $parsed['campaign_diagnosis']['message']      = 'A campanha tem boa entrega e ja gerou interacoes reais. Ha margem para optimizar, mas o targeting nao e o problema principal.';
                $parsed['campaign_diagnosis']['action']       = 'Manter campanha ativa e focar na qualidade da proposta e do anuncio.';
            } else {
                $parsed['campaign_diagnosis']['main_problem'] = 'offer';
                $parsed['campaign_diagnosis']['message']      = 'A campanha tem boa entrega e cliques relevantes, mas nao esta a converter. O problema esta na oferta ou friccao pos-clique.';
                $parsed['campaign_diagnosis']['action']       = 'Rever preco, melhorar proposta de valor e analisar a experiencia pos-clique.';
            }
        }
    }

    private function persist(Car $car, array $inputData, string $rawJson, array $parsed): CarAiAnalysis
    {
        // Garantir que veiculo_id é sempre o ID real — nunca deixar a IA definir isto
        $parsed['veiculo_id'] = (string) $car->id;

        // Enforce deterministic campaign diagnosis rules — override AI output when necessary
        $this->enforceCampaignDiagnosisRules($parsed, $inputData);

        $score          = $parsed['score_conversao']['valor'] ?? null;
        $classificacao  = $parsed['score_conversao']['classificacao'] ?? null;
        $urgency        = $parsed['recomendacao_urgencia']['nivel'] ?? null;
        $priceAlert     = $parsed['alerta_preco']['ativo'] ?? false;

        // Se já existe análise para este carro, actualiza — senão cria
        $analysis = CarAiAnalysis::updateOrCreate(
            ['car_id' => $car->id],
            [
                'input_data'          => $inputData,
                'analysis_raw'        => $rawJson,
                'analysis'            => $parsed,
                'score_conversao'     => $score !== null ? (int) $score : null,
                'score_classificacao' => $classificacao,
                'urgency_level'       => $urgency,
                'price_alert'         => (bool) $priceAlert,
                'status'              => 'completed',
                'feedback'            => null,
                'company_id'          => $car->company_id,
            ]
        );

        return $analysis->fresh();
    }

    private function persistFailure(Car $car, array $inputData, ?string $rawJson, \Throwable $exception): void
    {
        try {
            CarAiAnalysis::updateOrCreate(
                ['car_id' => $car->id],
                [
                    'input_data' => $inputData,
                    'analysis_raw' => $rawJson,
                    'analysis' => [
                        'error' => true,
                        'message' => $exception->getMessage(),
                    ],
                    'score_conversao' => null,
                    'score_classificacao' => null,
                    'urgency_level' => null,
                    'price_alert' => false,
                    'status' => 'failed',
                    'feedback' => null,
                    'company_id' => $car->company_id,
                ]
            );
        } catch (\Throwable $persistException) {
            Log::warning('CarAiAnalysesService: falha ao persistir estado failed', [
                'car_id' => $car->id,
                'company_id' => $car->company_id,
                'error' => $persistException->getMessage(),
            ]);
        }

        Log::error('CarAiAnalysesService: analise falhou', [
            'car_id' => $car->id,
            'company_id' => $car->company_id,
            'error' => $exception->getMessage(),
            'raw' => $this->truncateForLog($rawJson),
        ]);
    }

    private function shouldRetryStatus(int $status): bool
    {
        return in_array($status, [408, 409, 429, 500, 502, 503, 504], true);
    }

    private function shouldRetryException(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response?->status();
            return $status !== null && $this->shouldRetryStatus($status);
        }

        return str_contains(strtolower($exception->getMessage()), 'conteúdo vazio');
    }

    private function extractJsonObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    private function truncateForLog(?string $value, int $limit = 600): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit) . '...'
            : $value;
    }
}
