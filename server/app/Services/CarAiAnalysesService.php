<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAiAnalysis;
use App\Repositories\Contracts\CarAiAnalysesRepositoryInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarAiAnalysesService extends BaseService
{
    private const OPENAI_TIMEOUT_SECONDS = 45;
    private const OPENAI_CONNECT_TIMEOUT_SECONDS = 10;
    private const OPENAI_MAX_ATTEMPTS = 3;
    private const OPENAI_BACKOFF_MS = [800, 1800];

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
        $month = now()->locale('pt')->monthName; // ex: "março"
        $year  = now()->year;

        return <<<PROMPT
És o estratega de marketing automóvel da Xplendor para o mercado português. A tua função é transformar dados de viatura e performance em uma análise comercial clara, accionável e consistente, com foco em venda mais rápida e investimento inteligente.

## MISSÃO
Gerar uma análise JSON que ajude um stand a decidir:
- quão forte é o potencial comercial da viatura
- se existe alerta de preço
- quem é o público mais provável
- onde investir (canal principal e secundário)
- que direção criativa usar
- qual o nível de urgência
- qual a previsão de venda

## REGRAS DE RACIOCÍNIO (executa internamente nesta ordem)

1. CLASSIFICA O VEÍCULO
Enquadra mentalmente a viatura num perfil comercial plausível:
- citadino / económico
- familiar / station wagon
- SUV
- executivo / premium
- eléctrico / híbrido
- desportivo
- comercial

2. AVALIA QUALIDADE DOS DADOS
Se faltarem dados críticos, sê conservador. Nunca inventes contexto, mercado, equipamento ou performance.

3. CALCULA O SCORE COMERCIAL DE FORMA COERENTE
Baseia a avaliação em 4 sinais principais:
- taxa de interesse
- dias em stock
- views recentes
- preço vs mercado (se existir)

Heurística obrigatória:
- taxa de interesse >= 1% é saudável
- >60 dias em stock com taxa <0.5% implica urgência alta
- views recentes fortes + stock curto aumentam score
- preço alinhado ou abaixo do mercado melhora score
- se preço_vs_mercado não existir, não penalizar em excesso

Classificação do score:
- 0–20: Crítico
- 21–40: Baixo
- 41–60: Médio
- 61–80: Alto
- 81–100: Excelente

4. ESCOLHE CANAL COM LÓGICA COMERCIAL
Usa estas orientações:
- citadino / familiar até ~15k → Meta Ads
- gama média visual / SUV → Meta Ads + Google Search
- premium / executivo / eléctrico → Google Search + Meta Ads
- stock crítico ou baixa conversão → Meta Ads para acelerar atenção
- procura ativa e intenção alta → Google Search ganha força

Nunca escolhas canais sem justificar com:
- segmento
- preço
- tipo de comprador
- estado atual da performance

5. DEFINE O PÚBLICO-ALVO COM BASE NO CONTEXTO
Usa psicografia plausível para Portugal:
- comprador económico → sensível a preço, decisão rápida
- comprador familiar → compara mais, valoriza segurança e espaço
- comprador SUV → quer presença, conforto e test drive
- premium → decisão mais lenta, maior peso racional/fiscal
- eléctrico → comprador informado, tecnológico, atento a TCO

6. GERA CRIATIVO ESPECÍFICO
Título, hook e copy devem ser concretos e ligados à viatura.
Evitar linguagem fraca ou genérica:
- "oportunidade única"
- "não perca"
- "condições especiais"
- "stock limitado"

Quero especificidade, não cliché.

7. GARANTE CONSISTÊNCIA FINAL
Antes de responder, confirma:
- canal principal coerente com segmento e preço
- urgência coerente com stock e score
- probabilidade 7d <= 14d <= 30d
- nenhum campo contradiz outro
- JSON final respeita exatamente o schema fornecido

## REGRAS DE OUTPUT
- responder apenas em JSON válido
- sem markdown
- sem texto antes ou depois
- português de Portugal
- sem “depende”, “pode ser”, “eventualmente”, “em geral”
- se `preco_vs_mercado` for N/D:
  - `alerta_preco.ativo = false`
  - `desvio_percentual = null`
  - `recomendacao = null`

## BENCHMARKS ÚTEIS
- taxa de interesse saudável: >= 1%
- abaixo de 0.5% com muito tempo em stock = sinal crítico
- premium tende a precisar de mais confiança e procura ativa
- Meta Ads funciona bem em viaturas com apelo visual
- Google Search funciona bem em viaturas com procura intencional
- previsão de venda a 7 dias só deve ser alta se houver interesse forte e contexto favorável

Mantém a análise estratégica, objetiva e utilizável por um gestor comercial.
PROMPT;
    }

    private function buildUserPrompt(array $data): string
    {
        $fmt = fn($v) => $v ?? 'N/D';

        // Injectar contexto de qualidade dos dados para o modelo avaliar
        $camposCriticos = ['marca', 'modelo', 'preco', 'views_total', 'dias_em_stock'];
        $ndCount = collect($camposCriticos)->filter(fn($k) => is_null($data[$k]))->count();
        $qualidadeDados = match (true) {
            $ndCount === 0 => 'Completa — todos os campos críticos disponíveis',
            $ndCount <= 2  => "Parcial — {$ndCount} campo(s) crítico(s) em falta",
            default        => "Limitada — {$ndCount} campos críticos em falta; análise conservadora obrigatória",
        };

        return "Analise o seguinte veículo e entregue as recomendações estratégicas de marketing.\n\n"
            . "Qualidade dos dados: {$qualidadeDados}\n\n"
            . "Veículo:\n"
            . "- Marca: {$fmt($data['marca'])}\n"
            . "- Modelo: {$fmt($data['modelo'])}\n"
            . "- Versão: {$fmt($data['versao'])}\n"
            . "- Ano: {$fmt($data['ano'])}\n"
            . "- Combustível: {$fmt($data['combustivel'])}\n"
            . "- Câmbio: {$fmt($data['cambio'])}\n"
            . "- Quilometragem: {$fmt($data['quilometragem'])} km\n"
            . "- Cor: {$fmt($data['cor'])}\n"
            . "- Preço: €{$fmt($data['preco'])}\n"
            . "- Extras: {$fmt($data['extras'])}\n\n"
            . "Performance:\n"
            . "- Views totais: {$fmt($data['views_total'])}\n"
            . "- Views últimas 24h: {$fmt($data['views_24h'])}\n"
            . "- Views últimos 7 dias: {$fmt($data['views_7d'])}\n"
            . "- Leads gerados (formulário): {$fmt($data['leads'])}\n"
            . "- Interações diretas (WhatsApp, chamadas): {$fmt($data['interacoes'])}\n"
            . "- Engagement total (leads + interações): {$fmt($data['engagement_total'])}\n"
            . "- Taxa de interesse (engagement/views): {$fmt($data['taxa_interesse'])}%\n"
            . "- Tempo em stock: {$fmt($data['dias_em_stock'])} dias\n"
            . "- Preço vs. mediana de mercado PT: {$fmt($data['preco_vs_mercado'])}\n\n"
            . "Entregue apenas o JSON final com exactamente este schema:\n\n"
            . json_encode($this->outputSchema(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
        ];
    }

    // -------------------------------------------------------------------------
    // Chamada à API
    // -------------------------------------------------------------------------

    private function callOpenAi(Car $car, array $inputData): string
    {
        $apiKey = config('services.openai.key');
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($inputData);
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

    private function persist(Car $car, array $inputData, string $rawJson, array $parsed): CarAiAnalysis
    {
        // Garantir que veiculo_id é sempre o ID real — nunca deixar a IA definir isto
        $parsed['veiculo_id'] = (string) $car->id;

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
