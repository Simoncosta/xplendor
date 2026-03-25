<?php

namespace App\Services;

use App\Repositories\Contracts\CarMarketingIdeaRepositoryInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarMarketingIdeaService extends BaseService
{
    private const CONTENT_TYPES = ['sale', 'authority', 'engagement'];

    public function __construct(
        protected CarMarketingIdeaRepositoryInterface $carMarketingRepository
    ) {
        parent::__construct($carMarketingRepository);
    }

    public function generateForCompany(int $companyId, ?int $carId = null): array
    {
        $cars = $carId
            ? $this->carMarketingRepository->getActiveCarForWeeklyIdeas($companyId, $carId)
            : $this->carMarketingRepository->getActiveCarsForWeeklyIdeas($companyId);

        $ideas = [];

        foreach ($cars as $car) {
            $payload = $this->buildPayload($car);

            foreach (self::CONTENT_TYPES as $contentType) {
                try {
                    $raw = $this->callOpenAi($payload, $contentType);
                    $decoded = $this->decodeResponse($raw);

                    $idea = $this->carMarketingRepository->upsertWeeklyIdea(
                        $companyId,
                        $car->id,
                        $contentType,
                        $this->buildIdeaData($decoded, $payload, $raw, $contentType)
                    );

                    $ideas[] = $idea;
                } catch (\Throwable $e) {
                    Log::error('[CarMarketingIdea] Falha ao gerar ideia', [
                        'company_id' => $companyId,
                        'car_id' => $car->id,
                        'content_type' => $contentType,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $ideas;
    }

    public function getWeeklyIdeas(int $companyId)
    {
        return $this->carMarketingRepository->getWeeklyIdeas($companyId);
    }

    private function buildPayload(object $car): array
    {
        return [
            'brand' => $car->brand?->name,
            'model' => $car->model?->name,
            'version' => $car->version,
            'segment' => $car->segment,
            'fuel_type' => $car->fuel_type,
            'price_gross' => $car->price_gross,
            'views_count' => $car->views_count,
            'leads_count' => $car->leads_count,
            'interactions_count' => $car->interactions_count,
            'days_in_stock' => $car->days_in_stock,
            'car_profile' => $this->detectCarProfile($car),
            'performance_profile' => $this->detectPerformanceProfile($car),
        ];
    }

    private function detectCarProfile(object $car): string
    {
        $segment = strtolower((string) ($car->segment ?? ''));
        $fuel = strtolower((string) ($car->fuel_type ?? ''));
        $price = (float) ($car->price_gross ?? 0);

        if ($price >= 35000 || str_contains(strtolower((string) $car->version), 'amg') || str_contains(strtolower((string) $car->version), 'm sport')) {
            return 'premium';
        }

        if (in_array($segment, ['suv_tt', 'suv'])) {
            return 'suv';
        }

        if (in_array($segment, ['station_wagon'])) {
            return 'family';
        }

        if ($fuel === 'electric') {
            return 'electric';
        }

        if ($price <= 15000) {
            return 'budget';
        }

        return 'balanced';
    }

    private function detectPerformanceProfile(object $car): string
    {
        $views = (int) ($car->views_count ?? 0);
        $leads = (int) ($car->leads_count ?? 0);
        $interactions = (int) ($car->interactions_count ?? 0);
        $days = (int) ($car->days_in_stock ?? 0);

        if ($views >= 200 && $leads === 0) {
            return 'high_interest_low_conversion';
        }

        if ($views < 20 && $days > 7) {
            return 'low_visibility';
        }

        if ($days > 60 && $leads < 3) {
            return 'stuck_stock';
        }

        if ($leads >= 3 || $interactions >= 8) {
            return 'high_traction';
        }

        return 'normal';
    }

    private function buildIdeaData(array $decoded, array $payload, string $raw, string $contentType): array
    {
        return [
            'status' => 'pending',
            'content_type' => $contentType,
            'title' => $decoded['title'] ?? null,
            'angle' => $decoded['angle'] ?? null,
            'goal' => $decoded['goal'] ?? null,
            'target_audience' => $decoded['target_audience'] ?? null,
            'formats' => $this->normalizeArray($decoded['formats'] ?? []),
            'caption' => $decoded['caption'] ?? null,
            'hooks' => $this->normalizeArray($decoded['hooks'] ?? []),
            'cta' => $decoded['cta'] ?? null,
            'content_pillars' => $this->normalizeArray($decoded['content_pillars'] ?? []),
            'why_now' => $decoded['why_now'] ?? null,
            'source_data' => $payload,
            'ai_raw' => $raw,
        ];
    }

    private function decodeResponse(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta inválida da OpenAI: JSON não decodificável.');
        }

        return $decoded;
    }

    private function normalizeArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            return is_string($item) ? trim($item) : null;
        }, $value)));
    }

    private function callOpenAi(array $payload, string $contentType): string
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'           => 'gpt-4o',
                'temperature'     => 0.6, // criativo mas estruturado
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    [
                        'role'    => 'system',
                        'content' => $this->buildSystemPrompt($contentType),
                    ],
                    [
                        'role'    => 'user',
                        'content' => $this->buildUserPrompt($payload, $contentType),
                    ],
                ],
            ]);

        $response->throw();

        return $response->json('choices.0.message.content');
    }

    private function buildSystemPrompt(string $contentType): string
    {
        $month = now()->locale('pt')->monthName;
        $year  = now()->year;

        $contentTypeName = match ($contentType) {
            'sale'        => 'VENDA DIRECTA',
            'authority'   => 'AUTORIDADE E POSICIONAMENTO',
            'engagement'  => 'ENGAGEMENT E ATENÇÃO',
            default       => strtoupper($contentType),
        };

        return <<<PROMPT
És o Director Criativo de uma agência de marketing automóvel premium especializada no mercado português. O teu trabalho é criar ideias de conteúdo que geram resultados comerciais reais — não conteúdo bonito que ninguém compra.

## MISSÃO DESTE PEDIDO
Tipo de conteúdo: {$contentTypeName}
Contexto temporal: {$month} de {$year}

## PROCESSO OBRIGATÓRIO DE RACIOCÍNIO (executa internamente antes de gerar o JSON)

PASSO 1 — LÊ O PERFIL DO CARRO E PERCEBE O QUE O TORNA DESEJÁVEL
Não trates o perfil como um label — pensa no que alguém deste segmento realmente quer sentir quando compra este carro. Qual é o desejo profundo por trás da compra? Status? Segurança? Inteligência financeira? Liberdade?

PASSO 2 — LÊ O PERFIL DE PERFORMANCE E PERCEBE O PROBLEMA REAL
- high_interest_low_conversion: pessoas viram mas não avançaram — há uma objecção não resolvida (preço? confiança? dúvida técnica?)
- low_visibility: o carro existe mas ninguém sabe — precisa de um ângulo que quebre o silêncio
- stuck_stock: está há demasiado tempo — a narrativa actual não está a funcionar, precisa de ser reinventada
- high_traction: há procura real — o conteúdo deve acelerar a decisão, não criar interesse do zero
- normal: construção sólida de valor percebido

PASSO 3 — DEFINE O ÂNGULO ANTES DE ESCREVER SEJA O QUE FOR
O ângulo é a ideia central que torna o conteúdo irresistível. Não é o tema — é a perspectiva inesperada sobre o tema.
Mau ângulo: "Conheça o nosso SUV familiar"
Bom ângulo: "O carro que os pais de família compram quando finalmente param de adiar"

PASSO 4 — CALIBRA PELA SAZONALIDADE ACTUAL ({$month})
Janeiro–Fevereiro: mercado lento, orçamentos apertados — racional, custo-benefício, inteligência na compra
Março, Setembro: pico de matrículas PT — urgência real, momento de decisão, comparativos directos
Abril–Agosto: famílias, viagens, lifestyle — conteúdo aspiracional e de estilo de vida
Outubro–Novembro: fim de ano, balanço, oferta como presente ou dedução fiscal
Dezembro: só urgência genuína ou conteúdo emocional de fim de ano funciona

PASSO 5 — ESCREVE HOOKS QUE PARAM O SCROLL
Um hook fraco começa com "Descubra" ou "Conheça". Um hook forte começa com tensão, pergunta incómoda, facto surpreendente ou afirmação polarizadora.
Proibido: "Descubra", "Conheça", "Não perca", "Oportunidade única", "Stock limitado", "Condições especiais"
Obrigatório: especificidade, tensão, ou curiosidade genuína

PASSO 6 — A LEGENDA DEVE ESTAR PRONTA A PUBLICAR
Não é um rascunho. Não tem placeholders. Contém marca e modelo. Termina com CTA claro. Está em português de Portugal.

PASSO 7 — VERIFICA ANTES DE ENTREGAR
✓ O ângulo é inesperado ou é previsível?
✓ Os hooks começam com tensão ou curiosidade real?
✓ A legenda está pronta a copiar-colar sem edição?
✓ O conteúdo serve o tipo pedido ({$contentTypeName})?
✓ Não há frases genéricas proibidas?
✓ O JSON respeita exactamente o schema fornecido?
Se alguma verificação falhar — reescreve antes de responder.

## REGRAS ABSOLUTAS
- Responder APENAS em JSON válido — sem texto fora, sem markdown
- Português de Portugal — não brasileiro
- Zero inventar contexto que não está nos dados (não inventar promoções, épocas, campanhas)
- Cada hook deve ser radicalmente diferente dos outros — ângulos distintos, não variações da mesma frase
- A legenda deve ter entre 3 e 6 linhas — nem tweet nem artigo
PROMPT;
    }

    private function buildUserPrompt(array $payload, string $contentType): string
    {
        $fmt = fn($v) => $v ?? 'N/D';

        // Contexto explicado — o modelo sabe porquê, não só o quê
        $carProfileExplain = match ($payload['car_profile']) {
            'premium'  => 'Premium (preço ≥ €35k ou versão AMG/M Sport) — o comprador compra status, experiência e exclusividade',
            'suv'      => 'SUV — o comprador quer presença, versatilidade e lifestyle sem abdicar de conforto',
            'family'   => 'Familiar/Station Wagon — o comprador prioriza espaço, segurança e praticidade para o dia-a-dia',
            'electric' => 'Eléctrico — o comprador é tech-savvy, pensa em TCO e quer modernidade e consciência ambiental',
            'budget'   => 'Económico (preço ≤ €15k) — o comprador quer inteligência financeira, custo-benefício e fiabilidade',
            default    => 'Equilibrado — o comprador quer valor percebido claro, estilo razoável e benefício prático',
        };

        $perfProfileExplain = match ($payload['performance_profile']) {
            'high_interest_low_conversion' => 'Alto interesse, baixa conversão — muita gente viu mas não contactou. Há uma objecção não resolvida. O conteúdo deve quebrar essa resistência.',
            'low_visibility'               => 'Baixa visibilidade — o carro não está a ser descoberto. O conteúdo deve criar um ângulo que gere alcance e atenção.',
            'stuck_stock'                  => 'Stock parado — está há demasiado tempo sem converter. A narrativa precisa de ser reinventada para torná-lo relevante.',
            'high_traction'                => 'Alta tracção — há interesse real. O conteúdo deve acelerar a decisão de quem já está a considerar.',
            default                        => 'Performance normal — construção sólida de valor percebido e interesse qualificado.',
        };

        $strategyBlock     = $this->getStrategyBlock($contentType);
        $carProfileBlock   = $this->getCarProfileBlock($payload['car_profile']);
        $performanceBlock  = $this->getPerformanceBlock($payload['performance_profile']);

        return "Cria uma ideia de conteúdo para o seguinte veículo.\n\n"
            . "## VEÍCULO\n"
            . "- Marca: {$fmt($payload['brand'])}\n"
            . "- Modelo: {$fmt($payload['model'])}\n"
            . "- Versão: {$fmt($payload['version'])}\n"
            . "- Segmento: {$fmt($payload['segment'])}\n"
            . "- Combustível: {$fmt($payload['fuel_type'])}\n"
            . "- Preço: €{$fmt($payload['price_gross'])}\n\n"
            . "## PERFORMANCE ACTUAL\n"
            . "- Views totais: {$fmt($payload['views_count'])}\n"
            . "- Leads (formulário): {$fmt($payload['leads_count'])}\n"
            . "- Interações directas (WhatsApp, chamadas): {$fmt($payload['interactions_count'])}\n"
            . "- Dias em stock: {$fmt($payload['days_in_stock'])}\n\n"
            . "## PERFIS CLASSIFICADOS\n"
            . "- Perfil do carro: {$carProfileExplain}\n"
            . "- Perfil de performance: {$perfProfileExplain}\n\n"
            . "## DIRECTIVAS ESTRATÉGICAS\n"
            . "Tipo de conteúdo — {$strategyBlock}\n"
            . "Perfil do carro — {$carProfileBlock}\n"
            . "Perfil de performance — {$performanceBlock}\n\n"
            . "## INSTRUÇÃO FINAL\n"
            . "Executa os 7 passos de raciocínio do system prompt.\n"
            . "Depois entrega APENAS o JSON com exactamente este schema:\n\n"
            . json_encode($this->outputSchema($contentType), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function outputSchema(string $contentType): array
    {
        // Exemplos reais por content type — o modelo entende o nível de qualidade esperado
        return match ($contentType) {
            'sale' => [
                'title'           => 'O BMW 318d que estava à tua espera',
                'angle'           => 'O comprador racional que finalmente encontrou a desculpa certa para avançar',
                'goal'            => 'Gerar contacto directo — chamada, WhatsApp ou pedido de test drive',
                'target_audience' => 'Homem 35–50 anos, quadro médio ou empresário, pesquisou online mas ainda não contactou',
                'formats'         => ['reels', 'anuncio_meta'],
                'hooks'           => [
                    'Há 3 meses que estás a adiar. Este BMW não vai esperar mais.',
                    'Diesel, automático, 147cv. €18.900. Quando é que tens tempo para o ver?',
                    'As revisões estão em dia. O histórico está limpo. O que te falta decidir?',
                ],
                'caption'         => "Este BMW 318d Touring não precisa de apresentações — os números falam por si.\n\n147cv | Automático | Diesel\nRevisões em dia | 1 dono | Histórico verificado\n\nSe estás à procura de um executive diesel que não te deixa na mão, este é.\n\nTest drive disponível esta semana → link na bio ou mensagem directa.",
                'cta'             => 'Agendar test drive via WhatsApp',
                'content_pillars' => ['conversão directa', 'confiança e transparência'],
                'why_now'         => 'Carro com perfil de decisão próxima — comprador já pesquisou, precisa de empurrão final',
            ],
            'authority' => [
                'title'           => 'Diesel vs Híbrido: o que os dados dizem para quem faz mais de 20.000km/ano',
                'angle'           => 'O stand que educa em vez de vender — e por isso vende mais',
                'goal'            => 'Posicionar o stand como referência de conhecimento no segmento e gerar seguidores qualificados',
                'target_audience' => 'Comprador informado, 30–55 anos, que pesquisa antes de decidir e desconfia de vendedores tradicionais',
                'formats'         => ['carrossel_instagram', 'artigo_blog'],
                'hooks'           => [
                    'Se fazes mais de 20.000km por ano, o híbrido pode estar a custar-te dinheiro.',
                    'A maioria escolhe o motor errado. Aqui está como não cometer o mesmo erro.',
                    'Diesel em 2025: morto ou melhor que nunca? Depende de quanto conduzes.',
                ],
                'caption'         => "A questão não é qual é o motor mais moderno.\nA questão é qual é o motor certo para o teu uso.\n\nSe percorres mais de 20.000km por ano, principalmente em estrada, o diesel continua imbatível em custo por km.\nSe conduzes menos de 15.000km em cidade, o híbrido começa a fazer sentido.\n\nNo slide seguinte: os números reais, sem marketing.\n\nDúvidas? Fala connosco — aconselhamento sem pressão de venda.",
                'cta'             => 'Guardar o carrossel e partilhar com quem está a decidir',
                'content_pillars' => ['educação', 'credibilidade técnica'],
                'why_now'         => 'Conteúdo evergreen que posiciona o stand como referência — activo a longo prazo',
            ],
            'engagement' => [
                'title'           => 'Qual escolhias? A pergunta que divide opiniões',
                'angle'           => 'Polarização controlada — dois carros, duas tribos, uma conversa',
                'goal'            => 'Maximizar comentários, partilhas e alcance orgânico com audiência qualificada',
                'target_audience' => 'Entusiastas automóvel e compradores em fase de consideração, 25–50 anos, activos nas redes',
                'formats'         => ['post_feed', 'stories_instagram'],
                'hooks'           => [
                    'SUV ou familiar? Uma pergunta que divide famílias inteiras.',
                    'Gastaste €25.000 em que carro? Lê os comentários — as respostas vão surpreender-te.',
                    'Automático ou manual em 2025: ainda há debate. Tu de que lado estás?',
                ],
                'caption'         => "SUV Compacto ou Familiar Clássico?\n\nDois perfis completamente diferentes. Dois tipos de comprador completamente diferentes.\n\nO SUV: presença, altura de condução, versatilidade.\nO familiar: espaço real, conforto de viagem, racionalidade.\n\nSe tivesses €28.000 para gastar hoje — qual escolhias?\n\nResponde nos comentários 👇 (e diz porquê — essa parte é a mais interessante)",
                'cta'             => 'Responder nos comentários com a escolha e justificação',
                'content_pillars' => ['comunidade', 'debate qualificado'],
                'why_now'         => 'Conteúdo de engagement gera alcance orgânico que alimenta audiências para campanhas pagas futuras',
            ],
            default => [],
        };
    }

    private function getStrategyBlock(string $contentType): string
    {
        return match ($contentType) {
            'sale' => 'O objectivo é aproximar o comprador da decisão final. Trabalha confiança, benefício concreto, prova social implícita e fricção zero no contacto. Nada de vagueza — preço, condição e CTA têm de estar presentes.',
            'authority' => 'O objectivo é fazer o stand ser percepcionado como o especialista de referência. Educa, compara, contextualiza. O stand não aparece a vender — aparece a saber mais do que toda a gente.',
            'engagement' => 'O objectivo é gerar conversa qualificada. Polariza, pergunta, provoca opinião. O engagement deve atrair exactamente o tipo de pessoa que compra este segmento — não audiência aleatória.',
            default => '',
        };
    }

    private function getCarProfileBlock(string $carProfile): string
    {
        return match ($carProfile) {
            'premium'  => 'Posicionamento, exclusividade, detalhe e experiência sensorial. O comprador não compra especificações — compra como o carro o vai fazer sentir. Evitar linguagem de desconto ou urgência de preço.',
            'suv'      => 'Versatilidade activa, presença visual, conforto familiar e lifestyle. O comprador quer um carro que funcione em tudo — cidade, viagem, família, imagem.',
            'family'   => 'Espaço real, segurança comprovada, praticidade diária. O comprador é pragmático — quer o melhor para a família sem drama. Argumentos racionais e emocionais em equilíbrio.',
            'electric' => 'Tecnologia, eficiência e modernidade. O comprador já pesquisou muito — não precisa de explicações básicas. Foca em TCO, autonomia real e experiência de condução.',
            'budget'   => 'Inteligência na compra, custo-benefício claro, fiabilidade. O comprador quer sentir que tomou a decisão certa — não que comprou o mais barato.',
            default    => 'Equilíbrio entre valor percebido, estilo e benefício prático. Argumenta com clareza e especificidade.',
        };
    }

    private function getPerformanceBlock(string $performanceProfile): string
    {
        return match ($performanceProfile) {
            'high_interest_low_conversion' => 'Há uma objecção não resolvida — preço, confiança, dúvida técnica ou processo de compra. O conteúdo deve identificar e neutralizar essa objecção sem a nomear directamente.',
            'low_visibility'               => 'O carro precisa de ser descoberto. Cria um ângulo que quebre o silêncio — facto surpreendente, comparação inesperada ou pergunta que a audiência nunca colocou sobre este segmento.',
            'stuck_stock'                  => 'A narrativa actual falhou. Reinventa o carro — novo ângulo, novo público ou novo contexto de uso que ainda não foi explorado.',
            'high_traction'                => 'Há interesse real em curso. Não cries interesse — acelera a decisão. Trabalha urgência genuína, prova de procura e facilidade de avançar.',
            default                        => 'Constrói valor percebido de forma sólida. Conteúdo comercial com substância — não filler.',
        };
    }
}
