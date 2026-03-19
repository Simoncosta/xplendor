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
        $strategyBlock = $this->getStrategyBlock($contentType);
        $carProfileBlock = $this->getCarProfileBlock($payload['car_profile']);
        $performanceBlock = $this->getPerformanceBlock($payload['performance_profile']);

        $userPrompt = "
            Com base nos dados abaixo, gera uma ideia de conteúdo automóvel premium.

            TIPO DE CONTEÚDO:
            {$contentType}

            PERFIL DO CARRO:
            {$payload['car_profile']}

            PERFIL DE PERFORMANCE:
            {$payload['performance_profile']}

            DADOS:
            " . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

            ESTRATÉGIA DO TIPO:
            {$strategyBlock}

            ESTRATÉGIA DO PERFIL DO CARRO:
            {$carProfileBlock}

            ESTRATÉGIA DA PERFORMANCE:
            {$performanceBlock}

            Entrega a resposta respeitando exatamente este schema JSON:

            {
            \"title\": \"string\",
            \"angle\": \"string\",
            \"goal\": \"string\",
            \"target_audience\": \"string\",
            \"formats\": [\"string\", \"string\"],
            \"hooks\": [\"string\", \"string\", \"string\"],
            \"caption\": \"string\",
            \"cta\": \"string\",
            \"content_pillars\": [\"string\", \"string\"],
            \"why_now\": \"string\"
            }

            Regras obrigatórias:
            - responder apenas JSON válido
            - usar português de Portugal
            - conteúdo premium, concreto e executável
            - zero frases genéricas
            - escrever como alguém que sabe vender carros e posicionar stands
            - a legenda deve estar pronta a copiar e colar
            - não inventar contexto temporal, sazonal ou promocional não presente nos dados
            - não mencionar férias, campanhas, stock limitado ou urgência artificial sem evidência

            Formats permitidos:
            - reels
            - carrossel_instagram
            - stories_instagram
            - video_review
            - comparativo_video
            - post_feed
            - anuncio_meta
            - artigo_blog
            ";

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'temperature' => 0.85,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => '
                        És um estratega de marketing automóvel premium especializado no mercado português.

                        A tua missão é transformar dados reais de performance em ideias de conteúdo com valor comercial claro.

                        Princípios:
                        - escrever para vender, posicionar e gerar atenção
                        - evitar clichés, banalidades e copy previsível
                        - destacar desejo, contexto de compra, diferenciação e valor percebido
                        - adaptar a ideia ao tipo de carro e ao momento do carro no stock
                        - responder sempre em JSON válido
                        ',
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
            ]);

        $response->throw();

        return $response->json('choices.0.message.content');
    }

    private function getStrategyBlock(string $contentType): string
    {
        return match ($contentType) {
            'sale' => '
            Foco em venda.
            A ideia deve aproximar o potencial comprador da decisão.
            Trabalhar urgência, benefício real, confiança e contacto.
            ',
            'authority' => '
            Foco em autoridade.
            A ideia deve posicionar o stand como especialista e referência no segmento.
            Trabalhar conhecimento, comparação, contexto e credibilidade.
            ',
            'engagement' => '
            Foco em engagement.
            A ideia deve gerar atenção, comentários, partilhas ou curiosidade sem perder relevância comercial.
            Trabalhar opinião, comparação, pergunta ou gancho forte.
            ',
            default => '',
        };
    }

    private function getCarProfileBlock(string $carProfile): string
    {
        return match ($carProfile) {
            'premium' => '
                Trabalhar posicionamento, exclusividade, status, detalhe e experiência.
            ',
            'suv' => '
                Trabalhar versatilidade, presença, espaço, conforto e lifestyle.
            ',
            'station_wagon' => '
                Trabalhar espaço, praticidade, conforto, segurança e uso familiar.
            ',
            'family' => '
                Trabalhar espaço, praticidade, conforto, segurança e uso familiar.
            ',
            'electric' => '
                Trabalhar modernidade, eficiência, tecnologia e mobilidade urbana.
            ',
            'budget' => '
                Trabalhar racionalidade, custo-benefício, acessibilidade e inteligência na compra.
            ',
            default => '
                Trabalhar equilíbrio entre benefício prático, estilo e valor percebido.
            ',
        };
    }

    private function getPerformanceBlock(string $performanceProfile): string
    {
        return match ($performanceProfile) {
            'high_interest_low_conversion' => '
                Resolver objeções. Reforçar confiança, adequação de preço, clareza e desejo.
            ',
            'low_visibility' => '
                Gerar descoberta. Criar ângulo que aumente atenção e alcance.
            ',
            'stuck_stock' => '
                Mudar a narrativa do carro. Torná-lo mais interessante, atual e relevante.
            ',
            'high_traction' => '
                Acelerar decisão. Reforçar procura, urgência e oportunidade.
            ',
            default => '
                Criar conteúdo sólido, comercial e com valor percebido.
            ',
        };
    }
}
