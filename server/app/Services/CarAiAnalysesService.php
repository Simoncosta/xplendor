<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAiAnalysis;
use App\Repositories\Contracts\CarAiAnalysesRepositoryInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarAiAnalysesService extends BaseService
{
    public function __construct(
        protected CarAiAnalysesRepositoryInterface $carAiAnalysesRepository
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
        $rawJson    = $this->callOpenAi($inputData);
        $parsed     = $this->parseResponse($rawJson);

        return $this->persist($car, $inputData, $rawJson, $parsed);
    }

    // -------------------------------------------------------------------------
    // Construção dos dados de input
    // -------------------------------------------------------------------------

    /**
     * Mapeia os dados do carro (técnicos + performance) para os placeholders do prompt.
     */
    private function buildInputData(Car $car): array
    {
        // Performance — carregada via withCount nas relations do CarService
        $viewsTotal        = $car->views_count        ?? $car->views()->count();
        $leadsTotal        = $car->leads_count        ?? $car->leads()->count();
        $interactionsTotal = $car->interactions_count ?? $car->interactions()->count();

        $views7d  = $car->views()->where('created_at', '>=', now()->subDays(7))->count();
        $views24h = $car->views()->where('created_at', '>=', now()->subDay())->count();

        $daysInStock = $car->created_at
            ? (int) $car->created_at->diffInDays(now())
            : null;

        // Taxa de interesse = (leads + interações) / views
        // Interações incluem cliques WhatsApp, chamadas, etc.
        // É um sinal de intenção mais completo do que só leads de formulário
        $engagementTotal  = $leadsTotal + $interactionsTotal;
        $taxaInteresse    = $viewsTotal > 0
            ? round(($engagementTotal / $viewsTotal) * 100, 2)
            : 0;

        // Extras — flatten para string legível
        $extrasFlat = collect($car->extras ?? [])
            ->flatMap(fn($group) => $group['items'] ?? [])
            ->filter()
            ->values()
            ->implode(', ') ?: null;

        return [
            // Dados técnicos
            'marca'         => $car->brand->name ?? null,
            'modelo'        => $car->model->name ?? null,
            'versao'        => $car->version ?? null,
            'ano'           => $car->registration_year,
            'combustivel'   => $car->fuel_type,
            'cambio'        => $car->transmission,
            'quilometragem' => $car->mileage_km,
            'cor'           => $car->exterior_color,
            'preco'         => $car->price_gross ? (float) $car->price_gross : null,
            'extras'        => $extrasFlat,

            // Dados de performance
            'views_total'        => $viewsTotal,
            'views_24h'          => $views24h,
            'views_7d'           => $views7d,
            'leads'              => $leadsTotal,
            'interacoes'         => $interactionsTotal,   // ← novo campo
            'engagement_total'   => $engagementTotal,     // ← leads + interacções
            'taxa_interesse'     => $taxaInteresse,        // ← agora inclui interacções
            'dias_em_stock'      => $daysInStock,

            // Tráfego — placeholder; preencher via integração futura
            'trafego_pago'     => null,
            'trafego_direto'   => null,
            'trafego_organico' => null,

            // Benchmark de mercado
            'preco_vs_mercado' => $this->getPriceVsMarket($car),
            'benchmark_leads'  => null,
        ];
    }

    /**
     * Calcula desvio do preço do carro vs mediana de mercado (se existir snapshot).
     * Retorna string como '+8%', '-3%', 'na mediana' ou null.
     */
    private function getPriceVsMarket(Car $car): ?string
    {
        return null;
        // todo: implementar snapshot standvirtual
        // try {
        //     $snapshot = \App\Models\CarMarketSnapshot::query()
        //         ->whereRaw('LOWER(brand) = ?', [strtolower($car->brand->name ?? '')])
        //         ->whereRaw('LOWER(model) LIKE ?', ['%' . strtolower($car->model->name ?? '') . '%'])
        //         ->whereBetween('year', [
        //             ($car->registration_year ?? 2000) - 2,
        //             ($car->registration_year ?? 2030) + 2,
        //         ])
        //         ->whereNotNull('price_cents')
        //         ->where('price_cents', '>', 0)
        //         ->where('scraped_at', '>=', now()->subDays(30))
        //         ->pluck('price_cents')
        //         ->sort()
        //         ->values();

        //     if ($snapshot->isEmpty() || ! $car->price_gross) {
        //         return null;
        //     }

        //     $count  = $snapshot->count();
        //     $median = $snapshot[(int) floor($count / 2)] / 100;
        //     $price  = (float) $car->price_gross;
        //     $diff   = round((($price - $median) / $median) * 100, 1);

        //     if (abs($diff) < 2) return 'na mediana';
        //     return ($diff > 0 ? '+' : '') . $diff . '%';
        // } catch (\Throwable $e) {
        //     return null;
        // }
    }

    // -------------------------------------------------------------------------
    // Prompt
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(): string
    {
        return "Você é um estrategista sénior de marketing automotivo e publicidade digital especializado no mercado português, com foco em performance, conversão e otimização de investimento em mídia paga.\n\nO seu papel é analisar dados técnicos e de performance de um veículo e entregar recomendações estratégicas precisas, assertivas e acionáveis — como se estivesse a orientar diretamente uma equipa de mídia e conteúdo digital.\n\nRegras obrigatórias:\n- Nunca use linguagem genérica ou neutra\n- Seja assertivo nas escolhas — evite 'pode ser' ou 'depende'\n- Considere sempre o contexto do mercado português (comportamento do consumidor, plataformas dominantes, sazonalidade)\n- Responda exclusivamente em JSON válido, sem texto fora do JSON\n- Respeite rigorosamente o schema de output definido";
    }

    private function buildUserPrompt(array $data): string
    {
        $fmt = fn($v) => $v ?? 'N/D';

        return "Analise o seguinte veículo e gere as recomendações estratégicas de marketing.\n\n"
            . "## DADOS DO VEÍCULO\n"
            . "- Marca: {$fmt($data['marca'])}\n"
            . "- Modelo: {$fmt($data['modelo'])}\n"
            . "- Versão: {$fmt($data['versao'])}\n"
            . "- Ano: {$fmt($data['ano'])}\n"
            . "- Combustível: {$fmt($data['combustivel'])}\n"
            . "- Câmbio: {$fmt($data['cambio'])}\n"
            . "- Quilometragem: {$fmt($data['quilometragem'])} km\n"
            . "- Cor: {$fmt($data['cor'])}\n"
            . "- Preço: €{$fmt($data['preco'])}\n"
            . "- Extras/Equipamentos: {$fmt($data['extras'])}\n\n"
            . "## DADOS DE PERFORMANCE\n"
            . "- Views totais: {$fmt($data['views_total'])}\n"
            . "- Views últimas 24h: {$fmt($data['views_24h'])}\n"
            . "- Views últimos 7 dias: {$fmt($data['views_7d'])}\n"
            . "- Leads gerados (formulário): {$fmt($data['leads'])}\n"
            . "- Interações diretas (WhatsApp, chamadas, etc.): {$fmt($data['interacoes'])}\n"
            . "- Engagement total (leads + interações): {$fmt($data['engagement_total'])}\n"
            . "- Taxa de interesse (engagement/views): {$fmt($data['taxa_interesse'])}%\n"
            . "  ⚠️ Nota: a taxa de interesse inclui leads de formulário E interações diretas (WhatsApp, chamadas).\n"
            . "     Um valor de 1% ou superior já é considerado saudável no mercado automóvel português.\n"
            . "- Tempo em stock (dias): {$fmt($data['dias_em_stock'])}\n"
            . "- Preço vs. mediana de mercado PT: {$fmt($data['preco_vs_mercado'])}\n\n"
            . "## INSTRUÇÃO\n"
            . "Com base nos dados acima, entregue a análise estratégica completa respeitando exatamente o seguinte schema JSON:\n\n"
            . json_encode($this->outputSchema(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function outputSchema(): array
    {
        return [
            'veiculo_id' => 'string — identificador único do veículo',
            'score_conversao' => [
                'valor'         => 'número de 0 a 100',
                'classificacao' => 'Crítico | Baixo | Médio | Alto | Excelente',
                'justificacao'  => 'string — 1 frase explicando o score',
            ],
            'alerta_preco' => [
                'ativo'               => 'boolean',
                'desvio_percentual'   => 'número — positivo se acima do mercado, negativo se abaixo',
                'recomendacao'        => 'string — ação concreta de preço ou null se não aplicável',
            ],
            'publico_alvo' => [
                'faixa_etaria'            => 'string — ex: 35–50 anos',
                'genero_predominante'     => 'string — Masculino | Feminino | Neutro + justificação breve',
                'perfil_profissional'     => 'string',
                'estilo_de_vida'          => 'string',
                'comportamento_de_compra' => 'string — como este perfil toma decisão de compra em PT',
            ],
            'canal_principal' => [
                'canal'       => 'Google Ads | Meta Ads',
                'justificacao' => 'string — baseada em intenção, comportamento e tipo de veículo no mercado PT',
            ],
            'canal_secundario' => [
                'canal'       => 'Google Ads | Meta Ads | Nenhum',
                'justificacao' => 'string ou null',
            ],
            'criativo' => [
                'formato_principal'   => 'string — ex: Reels, Carrossel, Imagem Estática, Search, Performance Max',
                'formato_secundario'  => 'string ou null',
                'tom_de_comunicacao'  => 'string — ex: Aspiracional, Racional, Urgência, Lifestyle',
                'justificacao'        => 'string',
            ],
            'sugestao_conteudo' => [
                'titulo_anuncio' => 'string — título pronto a usar no anúncio',
                'hook_video'     => 'string — primeira frase/cena do vídeo para parar o scroll',
                'copy_curto'     => 'string — 2-3 linhas para legenda ou descrição do anúncio',
            ],
            'argumentos_de_venda' => [
                'string — argumento 1',
                'string — argumento 2',
                'string — argumento 3',
            ],
            'recomendacao_urgencia' => [
                'nivel'            => 'Imediata | Alta | Normal | Baixa',
                'acao_recomendada' => 'string — próximo passo concreto para a loja',
            ],
            'previsao' => [
                'probabilidade_venda_7d'  => 'número 0–100',
                'probabilidade_venda_14d' => 'número 0–100',
                'probabilidade_venda_30d' => 'número 0–100',
                'condicao'                => 'string — o que pode mudar esta previsão',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Chamada à API
    // -------------------------------------------------------------------------

    private function callOpenAi(array $inputData): string
    {
        $apiKey = config('services.openai.key');

        $response = Http::withToken($apiKey)
            ->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => 'gpt-4o',
                'temperature' => 0.4, // Mais determinístico para análises estratégicas
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => $this->buildSystemPrompt(),
                    ],
                    [
                        'role'    => 'user',
                        'content' => $this->buildUserPrompt($inputData),
                    ],
                ],
            ]);

        $response->throw();

        return $response->json('choices.0.message.content');
    }

    // -------------------------------------------------------------------------
    // Parse e validação
    // -------------------------------------------------------------------------

    private function parseResponse(string $rawJson): array
    {
        // Remove possíveis markdown fences (```json ... ```)
        $clean = preg_replace('/^```json\s*/i', '', trim($rawJson));
        $clean = preg_replace('/\s*```$/i', '', $clean);

        $parsed = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('CarAiAnalysesService: JSON inválido da OpenAI', [
                'error' => json_last_error_msg(),
                'raw'   => substr($rawJson, 0, 500),
            ]);
            throw new \RuntimeException('A IA devolveu uma resposta inválida. Tente novamente.');
        }

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // Persistência
    // -------------------------------------------------------------------------

    private function persist(Car $car, array $inputData, string $rawJson, array $parsed): CarAiAnalysis
    {
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
}
