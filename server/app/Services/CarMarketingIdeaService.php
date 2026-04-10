<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarMarketingIdeaRepositoryInterface;
use App\Services\PromptBuilders\VehicleMarketingPromptBuilder;
use App\Services\VehiclePersonaClassifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarMarketingIdeaService extends BaseService
{
    private const CONTENT_TYPES = ['sale', 'authority', 'engagement'];

    public function __construct(
        protected CarMarketingIdeaRepositoryInterface $carMarketingRepository,
        protected VehicleMarketingPromptBuilder $vehicleMarketingPromptBuilder,
        protected VehiclePersonaClassifier $vehiclePersonaClassifier,
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
                    $raw = $this->callOpenAi($car, $payload, $contentType);
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
            'persona' => $car instanceof Car ? $this->vehiclePersonaClassifier->classify($car) : 'practical_buyer',
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
            'primary_texts' => $this->normalizeArray($decoded['primary_texts'] ?? []),
            'headlines' => $this->normalizeArray($decoded['headlines'] ?? []),
            'descriptions' => $this->normalizeArray($decoded['descriptions'] ?? []),
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

    private function callOpenAi(Car $car, array $payload, string $contentType): string
    {
        $prompts = $this->vehicleMarketingPromptBuilder->build($car, $contentType, [
            'payload' => $payload,
            'output_schema' => $this->outputSchema($contentType),
        ]);

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'           => 'gpt-4o',
                'temperature'     => 0.6, // criativo mas estruturado
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    [
                        'role'    => 'system',
                        'content' => $prompts['system_prompt'] ?? '',
                    ],
                    [
                        'role'    => 'user',
                        'content' => $prompts['user_prompt'] ?? '',
                    ],
                ],
            ]);

        $response->throw();

        return $response->json('choices.0.message.content');
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
                'primary_texts'   => [
                    'Este BMW 318d Touring junta o que mais importa: motor certo, historico verificado e confianca para avancar sem duvidas.',
                    'Se procuras um diesel executivo que ainda faz sentido em Portugal, este BMW 318d merece entrar na tua shortlist hoje.',
                    'Ha carros que parecem bons no anuncio. E ha carros que continuam a fazer sentido quando olhas para historico, estado e utilizacao real.',
                ],
                'headlines'       => [
                    'BMW 318d Touring pronto a decidir',
                    'Executivo diesel com historico verificado',
                    'O BMW certo para avancar esta semana',
                ],
                'descriptions'    => [
                    'Mais confianca. Menos friccao na decisao.',
                    'Ideal para test drive e contacto imediato.',
                ],
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
                'primary_texts'   => [
                    'Antes de escolheres entre diesel e hibrido, convem perceberes quantos quilometros fazes e em que contexto conduzes.',
                    'Nem sempre o motor mais moderno e o motor certo. O criterio certo continua a ser o uso real.',
                ],
                'headlines'       => [
                    'Diesel ou hibrido: decide com dados',
                    'O motor certo depende do teu uso',
                ],
                'descriptions'    => [
                    'Educacao automovel sem ruido comercial.',
                ],
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
                'primary_texts'   => [
                    'SUV ou familiar? A resposta parece simples ate chegares ao momento de decidir com dinheiro real.',
                    'Dois carros, duas prioridades e uma discussao que divide condutores em segundos.',
                    'Se tivesses de escolher hoje, ias pela imagem e versatilidade ou pela racionalidade e espaco real?',
                ],
                'headlines'       => [
                    'SUV ou familiar: qual escolhias?',
                    'O debate que divide compradores',
                    'Que carro levavas para casa hoje?',
                ],
                'descriptions'    => [
                    'Conteudo para gerar comentarios qualificados.',
                ],
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

}
