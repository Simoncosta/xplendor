<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Services\Cars\Presentation\CarPresentation;
use PHPUnit\Framework\TestCase;

/**
 * A apresentação da viatura (ficha A4 e API pública): as mesmas entradas dão os mesmos blocos e
 * itens que o site da Quebom. tests/Fixtures/presentation/site-parity.json foi gravado correndo a
 * lógica do próprio site (utils/vehicle-presentation.js, cópia de trabalho de 2026-10-19) sobre as
 * 33 autocaravanas e caravanas de dev e 4 casos sintéticos (completa, caravana completa com o preço
 * escondido, mínima, nome reescrito com rótulos compostos e duplicados).
 * Única alteração ao gravado (decisão de 22/10): o título do bloco "WC" passou a "Duche e WC".
 */
class CarPresentationTest extends TestCase
{
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/presentation/site-parity.json'), true);
    }

    private static function project(array $p): array
    {
        return [
            'display_name' => $p['display_name'],
            'meta' => $p['meta'],
            'key_figures' => $p['key_figures'],
            'sections' => array_map(fn ($s) => array_intersect_key($s, array_flip(['id', 'title', 'details', 'items', 'beds'])), $p['sections']),
        ];
    }

    public function test_the_same_inputs_give_the_same_blocks_and_items_as_the_site(): void
    {
        $cases = self::cases();
        $this->assertCount(37, $cases);
        foreach ($cases as $i => $case) {
            $this->assertSame($case['expected'], self::project(CarPresentation::fromPublic($case['input'])), "caso {$i} (viatura {$case['input']['id']})");
        }
    }

    public function test_complete_motorhome(): void
    {
        $p = CarPresentation::fromPublic(self::cases()[0]['input']);
        $ids = array_column($p['sections'], 'id');
        $this->assertSame(['motor', 'dimensoes', 'camas', 'energia', 'sala', 'wc', 'cabine', 'exterior', 'condicao', 'outros'], $ids);
        $this->assertSame(['km', 'seats', 'sleeps', 'warranty'], array_column($p['key_figures'], 'key'));
        $beds = $p['sections'][2]['beds'];
        $this->assertSame(['label' => 'Cama central', 'description' => 'Cama de casal com acesso pelos dois lados'], $beds[0]);
        $this->assertSame('Cama pop-up panorâmica', $beds[2]['label']);
        $all = array_merge(...array_column($p['sections'], 'items'));
        $this->assertSame(count($all), count(array_unique($all)), 'nenhum item repetido');
        $this->assertContains('TV e suporte TV', $all);
        $this->assertSame(['Texto que o site não conhece'], $p['sections'][9]['items'], 'o que não está mapeado vai para Outros');
        $this->assertSame(['promo' => true, 'regular' => 89900.0, 'current' => 84900.0], array_intersect_key($p['price'], array_flip(['promo', 'regular', 'current'])));
        $this->assertSame('Sobre esta autocaravana', $p['description_title']);
        $this->assertSame(['Integral', 'Usado', 'Ref. 900001'], $p['eyebrow']);
    }

    public function test_motorhome_with_few_fields(): void
    {
        $p = CarPresentation::fromPublic(self::cases()[2]['input']);
        $this->assertSame([], $p['sections']);
        $this->assertSame([['key' => 'seats', 'value' => '2 lugares', 'caption' => 'Para viajar']], $p['key_figures']);
        $this->assertNull($p['description']);
        $this->assertSame('Pössl RoadCar', $p['display_name']);
    }

    public function test_caravan_has_the_security_block_and_no_engine_nor_km(): void
    {
        $p = CarPresentation::fromPublic(self::cases()[1]['input']);
        $titles = array_column($p['sections'], 'title', 'id');
        $this->assertSame('Segurança', $titles['cabine']);
        $this->assertArrayNotHasKey('motor', $titles);
        $this->assertNotContains('km', array_column($p['key_figures'], 'key'));
        $this->assertSame('Sobre esta caravana', $p['description_title']);
    }

    public function test_hidden_price_is_on_request(): void
    {
        $p = CarPresentation::fromPublic(self::cases()[1]['input']);
        $this->assertSame(['hidden' => true, 'promo' => false, 'regular' => null, 'current' => null, 'label' => 'Sob consulta'], $p['price']);
    }

    /** Os 17 itens da lista única da cabine (textos gravados de hoje) e as variantes. */
    private const CABIN_17 = [
        'ASR' => 'ASR', 'EBD' => 'EBD', 'Travão de Mão Eléctrico' => 'Travão de mão elétrico', 'Retrovisores Aquecidos' => 'Retrovisores aquecidos',
        'Hill Descent Control' => 'Controlo de descida (Hill Descent Control)', 'Sensores de Chuva' => 'Sensores de chuva', 'Traction+' => 'Traction+',
        'Perfil De Condutores' => 'Perfis de condutor', 'Deteção Cansaço e Fadiga' => 'Deteção de cansaço e fadiga', 'Camara de Frente' => 'Câmara frontal',
        'Aviso de velocidade' => 'Aviso de velocidade', 'Bancos Dianteiros c/ Memória' => 'Bancos com memória',
        'Bancos Dianteiros c/ Regulação Eléctrica' => 'Bancos com regulação elétrica', 'Bancos Dianteiros Aquecidos' => 'Bancos aquecidos',
        'Bancos Desportivos' => 'Bancos desportivos', 'Patilhas de Velocidade no Volante' => 'Patilhas de velocidade no volante',
        'Retrovisor Anti-Encadeamento' => 'Retrovisor antiencandeamento',
    ];

    private static function motorhomeWithExtras(array $items): array
    {
        $case = self::cases()[0]['input'];
        $case['extras'] = [['group' => 'safety_performance', 'items' => $items]];

        return $case;
    }

    public function test_the_17_cabin_items_leave_others_with_the_platform_labels(): void
    {
        $this->assertCount(17, self::CABIN_17);
        $p = CarPresentation::fromPublic(self::motorhomeWithExtras(array_keys(self::CABIN_17)));
        $byId = array_column($p['sections'], null, 'id');

        $this->assertSame('Cabine de condução e segurança', $byId['cabine']['title']);
        foreach (self::CABIN_17 as $label) {
            $this->assertContains($label, $byId['cabine']['items']);
        }
        $this->assertArrayNotHasKey('outros', $byId, 'nenhum dos 17 fica em Outros');
    }

    public function test_the_anti_glare_variants_show_once_and_the_wireless_charger_has_its_own_key(): void
    {
        $p = CarPresentation::fromPublic(self::motorhomeWithExtras([
            'Retrovisor Anti-Encadeamento', 'Retrovisores c/ Anti Encadeamento', 'Carregador de smartphone', 'Carregador de smartphone wireless', 'Carregamento Wireless',
        ]));
        $cabin = array_column($p['sections'], null, 'id')['cabine']['items'];

        $this->assertSame(1, count(array_keys($cabin, 'Retrovisor antiencandeamento', true)));
        $this->assertContains('Carregador de smartphone', $cabin);
        $this->assertSame(1, count(array_keys($cabin, 'Carregador de smartphone sem fios', true)));
        $this->assertSame('x_phone_charger_wireless', CarPresentation::extraDetail('Carregamento Wireless', 'motorhome')['key']);
        $this->assertNotSame(CarPresentation::extraDetail('Carregador de smartphone', 'motorhome')['key'], CarPresentation::extraDetail('Carregador de smartphone wireless', 'motorhome')['key']);
    }

    public function test_the_wc_block_is_called_duche_e_wc_and_keeps_its_id(): void
    {
        $byId = array_column(CarPresentation::fromPublic(self::cases()[0]['input'])['sections'], null, 'id');
        $this->assertSame('Duche e WC', $byId['wc']['title']);
    }

    public function test_lights_and_tow_hook_are_exterior(): void
    {
        foreach (['Faróis Diurnos', 'Faróis Diurnos Em Led', 'Faróis de Nevoeiro', 'Faróis Reguláveis em Altura', 'Gancho de Reboque'] as $text) {
            $this->assertSame('exterior', CarPresentation::extraDetail($text, 'motorhome')['section'], $text);
        }
    }

    public function test_extra_detail_follows_the_presentation_rules(): void
    {
        $this->assertSame(['text' => 'ASR', 'key' => 'x_asr', 'label' => 'ASR', 'section' => 'cabine', 'section_title' => 'Cabine de condução e segurança'],
            CarPresentation::extraDetail('ASR', 'motorhome'));
        $this->assertSame('Segurança', CarPresentation::extraDetail('ASR', 'caravan')['section_title']);
        $this->assertSame('Condução e segurança', CarPresentation::extraDetail('ASR', 'car')['section_title']);
        // Equipamento de habitação num carro e textos fora do catálogo: Outros, com o texto tal como vem.
        $this->assertSame(['outros', 'Outros'], array_values(array_intersect_key(CarPresentation::extraDetail('Mesa Rebativel', 'car'), array_flip(['section', 'section_title']))));
        $this->assertSame(['text' => 'Texto livre', 'key' => 'x:Texto livre', 'label' => 'Texto livre', 'section' => 'outros', 'section_title' => 'Outros'],
            CarPresentation::extraDetail(' Texto livre ', 'motorhome'));
    }

    public function test_a_car_has_engine_and_km_but_never_the_habitation_blocks(): void
    {
        $car = [
            'id' => 12, 'vehicle_type' => 'car', 'title' => 'Golf 1.6 TDI', 'brand' => ['name' => 'Volkswagen'], 'model' => ['name' => 'Golf'], 'category' => null,
            'condition' => 'used', 'year' => 2019, 'mileage_km' => 98000, 'fuel_type' => 'hybrid', 'transmission' => 'manual', 'gear_count' => 6, 'power_hp' => 115,
            'engine_capacity_cc' => 1598, 'price_gross' => 18900.0, 'hide_price_online' => false, 'has_promo_price' => false, 'specs' => ['seats' => 5],
            'habitation' => null, 'features' => null, 'beds' => null,
            'extras' => [['group' => 'x', 'items' => ['ABS', 'Mesa Rebativel', 'Jantes de Liga Leve']]],
        ];
        $p = CarPresentation::fromPublic($car);
        $byId = array_column($p['sections'], null, 'id');
        $this->assertSame(['motor', 'cabine', 'exterior', 'outros'], array_keys($byId));
        $this->assertSame([['label' => 'Motor', 'value' => 'Híbrido'], ['label' => 'Caixa', 'value' => 'Manual · 6 velocidades'], ['label' => 'Potência', 'value' => '115 cv'],
            ['label' => 'Cilindrada', 'value' => "1\u{00a0}598 cc"]], $byId['motor']['details']);
        $this->assertSame('Condução e segurança', $byId['cabine']['title']);
        $this->assertSame(['Mesa rebatível'], $byId['outros']['items'], 'o equipamento de habitação de um carro vai para Outros');
        $this->assertSame(['km', 'seats'], array_column($p['key_figures'], 'key'));
        $this->assertSame(['Automóvel', 'Usado', 'Ref. 12'], $p['eyebrow']);
    }
}
