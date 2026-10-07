<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Car;
use App\Models\EditorialPost;
use App\Services\Blog\BlogAiService;
use App\Services\Brand\BrandProfileAiService;
use App\Services\Brand\BrandProfileTemplates;
use App\Services\Brand\CreativeAiService;
use App\Services\Brand\CreativeFormatAdvisor;
use App\Services\CarAiAnalysesService;
use App\Services\CarDescriptionService;
use App\Services\Editorial\CaptionAiService;
use App\Services\Editorial\EditorialIdeasAiService;
use App\Services\PromptBuilders\VehiclePromptBuilder;

/**
 * Os 10 casos fixos por função do teste às cegas: empresas e viaturas fictícias, montados com os
 * MESMOS construtores de pedido das funções reais (o que muda é só o modelo). Na legenda não há
 * imagens nos casos fixos (o teste compara a escrita); a leitura das imagens tem teste próprio.
 */
final class AiBlindTestCases
{
    public const COUNT = 10;

    private const BUSINESSES = [
        ['name' => 'Auto Ribeiro', 'sector' => 'Comércio automóvel', 'location' => 'Braga', 'theme' => 'Revisão antes das férias de verão', 'keyword' => 'revisão automóvel', 'anchor' => 'Início das férias escolares',
            'profile' => ['tone_of_voice' => 'Próximo, honesto e técnico sem ser complicado.', 'audience' => 'Famílias da região que procuram um carro usado de confiança.', 'hashtags_default' => ['#autoribeiro', '#braga'], 'cta_default' => 'Marque já a sua visita.', 'emoji_policy' => 'light']],
        ['name' => 'Padaria Flor do Trigo', 'sector' => 'Restauração', 'location' => 'Coimbra', 'theme' => 'Pão de massa-mãe feito todas as manhãs', 'keyword' => 'pão de massa-mãe', 'anchor' => 'Dia Mundial do Pão',
            'profile' => ['tone_of_voice' => 'Caloroso e simples.', 'audience' => 'Vizinhos do bairro e quem passa a caminho do trabalho.', 'hashtags_default' => ['#flordotrigo', '#padaria'], 'cta_default' => 'Passe por cá amanhã cedo.', 'emoji_policy' => 'free']],
        ['name' => 'Clínica Sorriso Atlântico', 'sector' => 'Saúde', 'location' => 'Matosinhos', 'theme' => 'Como escovar os dentes das crianças', 'keyword' => 'saúde oral infantil', 'anchor' => 'Regresso às aulas',
            'profile' => ['tone_of_voice' => 'Profissional, tranquilizador e didático.', 'audience' => 'Pais com filhos em idade escolar.', 'hashtags_default' => ['#saudeoral'], 'cta_default' => 'Marque a consulta de avaliação.', 'emoji_policy' => 'none']],
        ['name' => 'Ginásio Movimento', 'sector' => 'Desporto', 'location' => 'Lisboa', 'theme' => 'Treino de força depois dos 50 anos', 'keyword' => 'treino de força', 'anchor' => 'Dia Mundial do Coração',
            'profile' => ['tone_of_voice' => 'Motivador e respeitador do ritmo de cada pessoa.', 'audience' => 'Adultos que querem começar a treinar com acompanhamento.', 'hashtags_default' => ['#movimento', '#treino'], 'cta_default' => 'Experimente uma aula gratuita.', 'emoji_policy' => 'light']],
        ['name' => 'Casa Alentejana Imobiliária', 'sector' => 'Imobiliário', 'location' => 'Évora', 'theme' => 'O que verificar antes de comprar casa', 'keyword' => 'comprar casa', 'anchor' => 'Feira de habitação',
            'profile' => ['tone_of_voice' => 'Sereno, claro e de confiança.', 'audience' => 'Casais a comprar a primeira casa.', 'hashtags_default' => ['#evora', '#imobiliaria'], 'cta_default' => 'Fale connosco sem compromisso.', 'emoji_policy' => 'none']],
        ['name' => 'Tasca do Largo', 'sector' => 'Restauração', 'location' => 'Porto', 'theme' => 'Menu de São Martinho com castanhas', 'keyword' => 'São Martinho', 'anchor' => 'São Martinho',
            'profile' => ['tone_of_voice' => 'Descontraído e tradicional.', 'audience' => 'Grupos de amigos e famílias ao fim de semana.', 'hashtags_default' => ['#tascadolargo', '#porto'], 'cta_default' => 'Reserve a sua mesa.', 'emoji_policy' => 'free']],
        ['name' => 'Bicicletas Ria', 'sector' => 'Comércio', 'location' => 'Aveiro', 'theme' => 'Como escolher a primeira bicicleta elétrica', 'keyword' => 'bicicleta elétrica', 'anchor' => 'Semana Europeia da Mobilidade',
            'profile' => ['tone_of_voice' => 'Entusiasta e prático.', 'audience' => 'Pessoas que querem deixar o carro nas deslocações curtas.', 'hashtags_default' => ['#bicicletasria', '#aveiro'], 'cta_default' => 'Venha fazer um teste.', 'emoji_policy' => 'light']],
        ['name' => 'Oficina Mecânica Serra', 'sector' => 'Serviços automóveis', 'location' => 'Viseu', 'theme' => 'Sinais de que os travões precisam de atenção', 'keyword' => 'travões', 'anchor' => 'Início do inverno',
            'profile' => ['tone_of_voice' => 'Direto e técnico.', 'audience' => 'Condutores da região com carros com mais de cinco anos.', 'hashtags_default' => ['#oficinaserra'], 'cta_default' => 'Peça um orçamento.', 'emoji_policy' => 'none']],
        ['name' => 'Quinta das Oliveiras Turismo Rural', 'sector' => 'Turismo', 'location' => 'Castelo Branco', 'theme' => 'Fim de semana de apanha da azeitona', 'keyword' => 'turismo rural', 'anchor' => 'Época da azeitona',
            'profile' => ['tone_of_voice' => 'Acolhedor e evocativo, sem exageros.', 'audience' => 'Casais e famílias da cidade que procuram descanso.', 'hashtags_default' => ['#turismorural', '#beirabaixa'], 'cta_default' => 'Reserve a sua estadia.', 'emoji_policy' => 'light']],
        ['name' => 'Florista Jardim de Inverno', 'sector' => 'Comércio', 'location' => 'Faro', 'theme' => 'Flores da época para o Dia dos Namorados', 'keyword' => 'ramo de flores', 'anchor' => 'Dia dos Namorados',
            'profile' => ['tone_of_voice' => 'Delicado e alegre.', 'audience' => 'Quem quer oferecer flores com entrega no próprio dia.', 'hashtags_default' => ['#florista', '#faro'], 'cta_default' => 'Encomende até às 11h.', 'emoji_policy' => 'free']],
    ];

    private const VEHICLES = [
        ['marca' => 'Peugeot', 'modelo' => '208', 'versao' => '1.2 PureTech Allure', 'ano' => 2021, 'combustivel' => 'Gasolina', 'cambio' => 'Manual', 'km' => 42000, 'cor' => 'Cinzento', 'preco' => 16900, 'segmento' => 'Utilitário', 'extras' => 'Apple CarPlay, câmara de marcha-atrás, cruise control', 'views' => 820, 'leads' => 6, 'dias' => 18],
        ['marca' => 'Renault', 'modelo' => 'Clio', 'versao' => '1.0 TCe Intens', 'ano' => 2020, 'combustivel' => 'Gasolina', 'cambio' => 'Manual', 'km' => 61000, 'cor' => 'Vermelho', 'preco' => 13500, 'segmento' => 'Utilitário', 'extras' => 'Sensores de estacionamento, ecrã tátil', 'views' => 410, 'leads' => 1, 'dias' => 64],
        ['marca' => 'BMW', 'modelo' => 'Série 3', 'versao' => '320d Pack M', 'ano' => 2019, 'combustivel' => 'Gasóleo', 'cambio' => 'Automática', 'km' => 98000, 'cor' => 'Preto', 'preco' => 28900, 'segmento' => 'Familiar', 'extras' => 'Pack M, navegação, bancos aquecidos', 'views' => 1500, 'leads' => 12, 'dias' => 9],
        ['marca' => 'Tesla', 'modelo' => 'Model 3', 'versao' => 'Long Range', 'ano' => 2022, 'combustivel' => 'Elétrico', 'cambio' => 'Automática', 'km' => 35000, 'cor' => 'Branco', 'preco' => 34900, 'segmento' => 'Familiar', 'extras' => 'Autopilot, teto panorâmico', 'views' => 2300, 'leads' => 4, 'dias' => 41],
        ['marca' => 'Toyota', 'modelo' => 'Yaris', 'versao' => '1.5 Hybrid Comfort', 'ano' => 2020, 'combustivel' => 'Híbrido', 'cambio' => 'Automática', 'km' => 52000, 'cor' => 'Azul', 'preco' => 17900, 'segmento' => 'Utilitário', 'extras' => 'Câmara traseira, ar condicionado automático', 'views' => 970, 'leads' => 9, 'dias' => 12],
        ['marca' => 'Volkswagen', 'modelo' => 'Golf', 'versao' => '2.0 TDI Life', 'ano' => 2021, 'combustivel' => 'Gasóleo', 'cambio' => 'Manual', 'km' => 74000, 'cor' => 'Cinzento', 'preco' => 22500, 'segmento' => 'Compacto', 'extras' => 'Cockpit digital, faróis LED', 'views' => 640, 'leads' => 2, 'dias' => 77],
        ['marca' => 'Mercedes-Benz', 'modelo' => 'Classe A', 'versao' => 'A 180 d Style', 'ano' => 2018, 'combustivel' => 'Gasóleo', 'cambio' => 'Automática', 'km' => 112000, 'cor' => 'Prata', 'preco' => 21900, 'segmento' => 'Compacto', 'extras' => 'MBUX, ambiente interior', 'views' => 530, 'leads' => 3, 'dias' => 35],
        ['marca' => 'Dacia', 'modelo' => 'Duster', 'versao' => '1.5 dCi Comfort 4x4', 'ano' => 2019, 'combustivel' => 'Gasóleo', 'cambio' => 'Manual', 'km' => 88000, 'cor' => 'Laranja', 'preco' => 15900, 'segmento' => 'SUV', 'extras' => 'Tração integral, barras de tejadilho', 'views' => 1200, 'leads' => 11, 'dias' => 6],
        ['marca' => 'Fiat', 'modelo' => '500', 'versao' => '1.0 Hybrid Dolcevita', 'ano' => 2022, 'combustivel' => 'Híbrido', 'cambio' => 'Manual', 'km' => 18000, 'cor' => 'Branco', 'preco' => 14900, 'segmento' => 'Citadino', 'extras' => 'Teto de abrir, jantes de liga leve', 'views' => 300, 'leads' => 0, 'dias' => 92],
        ['marca' => 'Kia', 'modelo' => 'Sportage', 'versao' => '1.6 CRDi Drive', 'ano' => 2021, 'combustivel' => 'Gasóleo', 'cambio' => 'Automática', 'km' => 47000, 'cor' => 'Verde', 'preco' => 27900, 'segmento' => 'SUV', 'extras' => 'Garantia de fábrica até 2028, câmara 360', 'views' => 1100, 'leads' => 7, 'dias' => 21],
    ];

    /** @return array{input: array, prompt: AiPrompt} */
    public static function make(string $function, int $index): array
    {
        $b = self::BUSINESSES[$index % self::COUNT];
        $v = self::VEHICLES[$index % self::COUNT];
        $date = now()->addDays(10 + $index)->toDateString();
        $profile = $b['profile'] + ['words_to_use' => [], 'words_to_avoid' => [], 'topics_to_avoid' => []];

        return match ($function) {
            'caption' => [
                'input' => ['empresa' => $b['name'], 'tema' => $b['theme'], 'redes' => 'Instagram e Facebook'],
                'prompt' => app(CaptionAiService::class)->prompt([
                    'post' => ['id' => 0, 'date' => $date, 'theme' => $b['theme'], 'keyword' => $b['keyword'], 'content_type' => 'Dica', 'formats' => []],
                    'networks' => ['instagram', 'facebook'],
                    'anchor' => ['title' => $b['anchor'], 'notes' => null],
                    'company' => ['name' => $b['name'], 'sector' => $b['sector']],
                    'profile' => $profile,
                ], []),
            ],
            'creative' => [
                'input' => ['empresa' => $b['name'], 'tema' => $b['theme'], 'rede' => 'Instagram'],
                'prompt' => AiPrompt::fromMessages(app(CreativeAiService::class)->messages([
                    'post' => ['id' => 0, 'date' => $date, 'theme' => $b['theme'], 'channel' => 'instagram', 'content_type' => 'Dica', 'media_format' => null, 'keyword' => $b['keyword']],
                    'anchor' => ['title' => $b['anchor'], 'notes' => null],
                    'company' => ['name' => $b['name'], 'sector' => $b['sector']],
                    'profile' => $profile,
                    'format' => ['source' => CreativeFormatAdvisor::SOURCE_NONE, 'reason' => 'no_followers', 'ranked' => [], 'followers' => null, 'band' => null, 'source_label' => null, 'source_url' => null],
                ])),
            ],
            'ideas' => [
                'input' => ['empresa' => $b['name'], 'âncora' => $b['anchor']],
                'prompt' => AiPrompt::fromMessages(app(EditorialIdeasAiService::class)->messages(self::ideasContext($b))),
            ],
            'blog' => [
                'input' => ['empresa' => $b['name'], 'tema' => $b['theme'], 'palavra-chave' => $b['keyword']],
                'prompt' => AiPrompt::fromMessages(app(BlogAiService::class)->messages('topic', ['topic' => $b['theme'], 'keyword' => $b['keyword']], [
                    'company' => $b['name'], 'sector' => $b['sector'], 'location' => $b['location'],
                    'profile' => ['tone_of_voice' => $profile['tone_of_voice'], 'audience' => $profile['audience'], 'words_to_use' => [], 'words_to_avoid' => [], 'topics_to_avoid' => []],
                    'audience' => ['has_data' => false],
                ])),
            ],
            'brand_profile' => [
                'input' => ['empresa' => $b['name'], 'ramo' => $b['sector'], 'zona' => $b['location']],
                'prompt' => AiPrompt::fromMessages(app(BrandProfileAiService::class)->messages([
                    'template' => BrandProfileTemplates::for(null),
                    'company' => ['name' => $b['name'], 'sector' => $b['sector'], 'location' => $b['location'], 'website' => null, 'instagram' => null, 'facebook' => null],
                    'current' => [],
                    'audience' => ['has_data' => false],
                ])),
            ],
            'car_description' => (function () use ($v) {
                $p = app(CarDescriptionService::class)->buildPrompts([
                    'vehicle_type' => 'car', 'brand_name' => $v['marca'], 'model_name' => $v['modelo'], 'registration_year' => $v['ano'], 'version' => $v['versao'],
                    'fuel_type' => $v['combustivel'], 'transmission' => $v['cambio'], 'mileage_km' => $v['km'], 'exterior_color' => $v['cor'],
                    'price_gross' => $v['preco'], 'segment' => $v['segmento'], 'extras' => [['items' => explode(', ', $v['extras'])]],
                ]);

                return ['input' => ['viatura' => "{$v['marca']} {$v['modelo']} {$v['versao']} ({$v['ano']})"], 'prompt' => new AiPrompt($p['system'], $p['user'], json: false)];
            })(),
            'car_analysis' => (function () use ($v) {
                $p = app(VehiclePromptBuilder::class)->build(new Car(['vehicle_type' => 'car']), [
                    'input_data' => self::analysisData($v),
                    'output_schema' => app(CarAiAnalysesService::class)->outputSchema(),
                ]);

                return ['input' => ['viatura' => "{$v['marca']} {$v['modelo']} ({$v['ano']})", 'dias em stock' => $v['dias'], 'visualizações' => $v['views'], 'contactos' => $v['leads']],
                    'prompt' => new AiPrompt((string) $p['system_prompt'], (string) $p['user_prompt'])];
            })(),
            'family_categories' => (function () use ($index) {
                $sets = [
                    ['Família \\ Bebidas \\ Sangria', 'Família \\ Comidas \\ Pao', 'Família \\ Comidas \\ Sandwiches'],
                    ['Família \\ Bebidas \\ Kombucha', 'Família \\ Comidas \\ Brunch', 'Família \\ Diversos \\ Refeições pessoal'],
                    ['Família \\ Comidas \\ Sushi', 'Família \\ Bebidas \\ Sake', 'Família \\ Glovo \\ Menus'],
                ];
                $paths = $sets[$index % count($sets)];

                return ['input' => ['famílias' => implode('; ', $paths)], 'prompt' => app(\App\Services\Restaurant\FamilyCategoryAiSuggester::class)->prompt($paths)];
            })(),
            default => throw new \InvalidArgumentException("Função sem casos de teste: {$function}."),
        };
    }

    private static function ideasContext(array $b): array
    {
        $start = now()->addMonthNoOverflow()->startOfMonth();
        $formats = [];
        foreach (array_keys(EditorialPost::MEDIA_FORMATS) as $channel) {
            $formats[$channel] = ['source' => CreativeFormatAdvisor::SOURCE_NONE, 'top' => []];
        }

        return [
            'year' => $start->year, 'month' => $start->month, 'month_key' => $start->format('Y-m'),
            'first_day' => $start->toDateString(), 'last_day' => $start->copy()->endOfMonth()->toDateString(),
            'company' => ['name' => $b['name'], 'sector' => $b['sector']],
            'anchors' => [['id' => null, 'owned' => false, 'title' => $b['anchor'], 'date' => $start->copy()->addDays(9)->toDateString(), 'start' => null, 'end' => null, 'suggestion' => null]],
            'existing' => [],
            'profile' => array_intersect_key($b['profile'], array_flip(['tone_of_voice', 'audience', 'emoji_policy'])) + ['pillars' => []],
            'has_profile' => true,
            'formats' => $formats,
        ];
    }

    private static function analysisData(array $v): array
    {
        return [
            'car' => ['marca' => $v['marca'], 'modelo' => $v['modelo'], 'versao' => $v['versao'], 'ano' => $v['ano'], 'combustivel' => $v['combustivel'],
                'cambio' => $v['cambio'], 'quilometragem' => $v['km'], 'cor' => $v['cor'], 'preco' => (float) $v['preco'], 'extras' => $v['extras'], 'segmento' => $v['segmento']],
            'performance' => ['views_total' => $v['views'], 'views_7d' => (int) round($v['views'] / 4), 'views_30d' => $v['views'], 'leads_total' => $v['leads'], 'leads_7d' => (int) floor($v['leads'] / 3),
                'leads_30d' => $v['leads'], 'interacoes_total' => $v['leads'] * 3, 'engagement_total' => $v['leads'] * 4,
                'taxa_interesse' => $v['views'] > 0 ? round($v['leads'] * 4 / $v['views'] * 100, 2) : 0, 'dias_em_stock' => $v['dias']],
            'market_intelligence' => ['market_position' => $v['dias'] > 60 ? 'acima_do_mercado' : 'alinhado'],
            'campaign_targeting' => ['location' => [], 'age_min' => null, 'age_max' => null, 'genders' => [], 'interests' => [], 'audience_mode' => null],
            'campaign_performance' => [],
        ];
    }
}
