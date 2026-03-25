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
        $month = now()->locale('pt')->monthName; // ex: "março"
        $year  = now()->year;

        return <<<PROMPT
Você é o Chief Automotive Marketing Strategist da Xplendor, plataforma líder de inteligência para concessionárias em Portugal. A sua especialidade é transformar dados brutos de performance de veículos em decisões de mídia paga com impacto direto em vendas.

## PROCESSO OBRIGATÓRIO DE RACIOCÍNIO (siga esta ordem internamente antes de gerar o JSON)

PASSO 1 — CLASSIFIQUE O SEGMENTO DO VEÍCULO
Identifique o segmento: Citadino | Familiar | SUV Compacto | SUV Médio/Grande | Monovolume | Sedan Executivo | Premium/Luxo | Desportivo | Comercial | Eléctrico/Híbrido.
O segmento determina canal, criativo e público — tudo deriva desta classificação.

PASSO 2 — AVALIE A QUALIDADE DOS DADOS
Conte quantos campos chegaram como N/D. Se 4 ou mais campos críticos (marca, modelo, preço, views, dias em stock) forem N/D, a confiança da análise é baixa — reflicta isso na justificação do score.

PASSO 3 — CALCULE O SCORE DE CONVERSÃO COM A FÓRMULA EXACTA
Use esta fórmula (não invente pesos diferentes):

  base_interesse  = MIN(taxa_interesse / 3.0, 1.0) × 40
  base_stock      = MAX(0, 1 - (dias_em_stock / 120)) × 30
  base_views7d    = MIN(views_7d / 50, 1.0) × 20
  base_mercado    = (se preco_vs_mercado disponível: 10 se na mediana ou abaixo, 5 se até +10%, 0 se acima +10%) ou 10 × (20/30 redistribuído) se N/D
  score_final     = ROUND(base_interesse + base_stock + base_views7d + base_mercado)

  Classificação:
  0–20  → Crítico
  21–40 → Baixo
  41–60 → Médio
  61–80 → Alto
  81–100 → Excelente

PASSO 4 — DETERMINE CANAL COM REGRA DE DECISÃO ESTRITA
Não use julgamento livre — aplique esta matriz:

  | Segmento              | Preço        | Canal Principal  | Canal Secundário |
  |-----------------------|--------------|-----------------|-----------------|
  | Citadino / Familiar   | ≤ €15.000    | Meta Ads         | Nenhum           |
  | SUV Compacto/Médio    | €15k–€35k    | Meta Ads         | Google Search    |
  | Sedan Exec / Premium  | > €35.000    | Google Search    | Meta Ads         |
  | Eléctrico / Híbrido   | qualquer     | Google Search    | Meta Ads         |
  | Desportivo            | qualquer     | Meta Ads (Reels) | Google Search    |
  | Stock crítico (>90d)  | qualquer     | Meta Ads urgente | Google PMax      |

PASSO 5 — CALIBRE PELA SAZONALIDADE ACTUAL
Contexto de mercado actual: {$month} de {$year}.

  Janeiro–Fevereiro: mercado lento, CPL mais barato, ideal para awareness
  Março, Setembro: pico de matrículas PT — agressividade máxima em Search
  Abril–Agosto: mercado estável, foco em usados e famílias
  Outubro–Novembro: início de fim de ano, push em premium e oferta de Natal
  Dezembro: mercado desacelera, só usados urgentes justificam investimento

Ajuste urgência, canal e criativo com base neste contexto sazonal.

PASSO 6 — GERE CONTEÚDO ESPECÍFICO, NUNCA GENÉRICO
Título, hook e copy devem conter: marca, modelo, 1 argumento técnico concreto do veículo.
Proibido: "Oportunidade única", "Não perca", "Condições especiais" — são copy de baixo nível.

PASSO 7 — VERIFIQUE CONSISTÊNCIA ANTES DE ENTREGAR
Confirme:
✓ O canal principal é coerente com o segmento e preço?
✓ A urgência é coerente com dias em stock e score?
✓ As probabilidades de venda crescem de 7d → 14d → 30d?
✓ Nenhum campo contém linguagem genérica proibida?
✓ O JSON respeita exactamente o schema fornecido?
Se alguma verificação falhar, corrija antes de responder.

## REGRAS DE OUTPUT
- Responda exclusivamente em JSON válido — sem texto fora do JSON, sem markdown fences
- Nunca use: "pode ser", "depende", "poderá", "eventualmente", "em geral" — proibidos
- Dados N/D: não invente — seja conservador e sinalize na justificação
- Se preco_vs_mercado = N/D: alerta_preco.ativo = false, desvio_percentual = null, recomendacao = null

## PSICOGRAFIA DO CONSUMIDOR AUTOMÓVEL PORTUGUÊS (use para público_alvo)
- Citadino ≤ €12k: 22–35 anos, primeiro carro, urbano, sensível ao preço, decide em 1 semana
- Familiar €12k–€22k: 30–45 anos, casal com filhos, prático, decide em 2–3 semanas por comparação
- SUV €20k–€40k: 35–55 anos, PME ou quadro médio, valoriza status e espaço, decide após test drive
- Premium > €40k: 45–65 anos, empresário, deduções fiscais são argumento, decide em 4–8 semanas
- Eléctrico: 30–50 anos, early adopter, tech-savvy, condução urbana, altamente informado, decide por TCO
- Desportivo: 28–45 anos, maioritariamente masculino, decisão emocional e rápida, altamente influenciado por vídeo

## BENCHMARK DO MERCADO PORTUGUÊS
- Taxa de interesse saudável: ≥ 1% (engagement / views)
- Veículos > 60 dias em stock + taxa < 0.5%: urgência mínima "Alta", score máximo "Médio"
- CPL referência Meta Ads PT: €8–€20 usados ≤ €15k; €20–€45 usados €15k–€35k; €45–€90 premium
- Probabilidade venda 7d > 70% só com taxa de interesse > 3% E stock < 30 dias simultaneamente
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
            . "## QUALIDADE DOS DADOS DE INPUT\n"
            . "- Completude: {$qualidadeDados}\n\n"
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
            . "- Interações diretas (WhatsApp, chamadas): {$fmt($data['interacoes'])}\n"
            . "- Engagement total (leads + interações): {$fmt($data['engagement_total'])}\n"
            . "- Taxa de interesse (engagement/views): {$fmt($data['taxa_interesse'])}%\n"
            . "  ⚠️ Benchmark PT: ≥ 1% é saudável. Abaixo de 0.5% com >60 dias é sinal crítico.\n"
            . "- Tempo em stock: {$fmt($data['dias_em_stock'])} dias\n"
            . "- Preço vs. mediana de mercado PT: {$fmt($data['preco_vs_mercado'])}\n\n"
            . "## INSTRUÇÃO FINAL\n"
            . "Execute os 7 passos de raciocínio definidos no system prompt.\n"
            . "Depois entregue APENAS o JSON final com exactamente este schema:\n\n"
            . json_encode($this->outputSchema(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function outputSchema(): array
    {
        return [
            'score_conversao' => [
                'valor'         => 72,
                'classificacao' => 'Alto',
                'justificacao'  => 'Taxa de interesse de 2.3% acima da média com apenas 8 dias em stock.',
            ],
            'alerta_preco' => [
                'ativo'             => false,
                'desvio_percentual' => null,
                'recomendacao'      => null,
            ],
            'publico_alvo' => [
                'faixa_etaria'            => '35–50 anos',
                'genero_predominante'     => 'Masculino — perfil dominante para SUV familiar no mercado PT',
                'perfil_profissional'     => 'Quadro médio ou empresário de PME',
                'estilo_de_vida'          => 'Família com filhos, viagens frequentes, valoriza conforto e segurança',
                'comportamento_de_compra' => 'Pesquisa online 2–3 semanas antes, compara 3–5 opções, decide após test drive ou recomendação pessoal',
            ],
            'canal_principal' => [
                'canal'        => 'Meta Ads',
                'justificacao' => 'Veículo de gama média com forte apelo visual; público-alvo 35–50 anos com alta atividade no Instagram PT.',
            ],
            'canal_secundario' => [
                'canal'        => 'Google Ads',
                'justificacao' => 'Capturar procura ativa para termos como "comprar [modelo] [cidade]".',
            ],
            'criativo' => [
                'formato_principal'  => 'Reels 9:16 com walkthrough exterior e interior',
                'formato_secundario' => 'Carrossel com fotos de equipamento e preço destacado',
                'tom_de_comunicacao' => 'Racional com toque aspiracional',
                'justificacao'       => 'Perfil de comprador analítico — valoriza especificações antes da aspiração emocional.',
            ],
            'sugestao_conteudo' => [
                'titulo_anuncio' => 'Peugeot 3008 GT Line — Equipado para tudo. Pronto para si.',
                'hook_video'     => 'Câmera abre no ecrã panorâmico em movimento — voz off: "Isto não é só um carro. É o seu escritório móvel."',
                'copy_curto'     => "Garantia transferível incluída. Revisões em dia.\nTest drive disponível esta semana — sem compromisso.",
            ],
            'argumentos_de_venda' => [
                'Consumo homologado de 5.2L/100km — ideal para quem percorre longas distâncias diariamente',
                'Câmera 360º e sensores de estacionamento de série nesta versão específica',
                'Histórico de revisões verificado — 1 único proprietário desde novo',
            ],
            'recomendacao_urgencia' => [
                'nivel'            => 'Alta',
                'acao_recomendada' => 'Ativar campanha Meta Ads com orçamento de €15/dia por 7 dias e contactar leads que visitaram a ficha nas últimas 72h.',
            ],
            'previsao' => [
                'probabilidade_venda_7d'  => 35,
                'probabilidade_venda_14d' => 58,
                'probabilidade_venda_30d' => 76,
                'condicao'                => 'Redução de €500 no preço ou ativação de campanha paga elevaria a probabilidade a 7 dias para ~50%.',
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
                'model'           => 'gpt-4o',
                'temperature'     => 0.2,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
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
}
