<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Public\CarPublicResource;
use App\Models\Car;
use App\Services\Cars\Presentation\CarPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Catálogo de equipamento (sessão do site da Quebom de 23/10): o extra "Direção Adaptativa" (viatura 140)
 * tem chave própria, rótulo e secção cabine; deixa de cair em "Outros", na apresentação e no items_detail
 * da API pública.
 */
class EquipmentAdaptiveSteeringTest extends TestCase
{
    use RefreshDatabase;

    public function test_adaptive_steering_has_its_own_key_label_and_the_cabin_section(): void
    {
        $this->assertSame(['text' => 'Direção Adaptativa', 'key' => 'x_adaptive_steering', 'label' => 'Direção adaptativa', 'section' => 'cabine',
            'section_title' => 'Cabine de condução e segurança'], CarPresentation::extraDetail('Direção Adaptativa', 'motorhome'));
        $this->assertNotSame(CarPresentation::extraDetail('Direcção Assistida', 'motorhome')['key'], CarPresentation::extraDetail('Direção Adaptativa', 'motorhome')['key']);
    }

    public function test_the_public_api_items_detail_and_the_presentation_put_it_in_the_cabin(): void
    {
        $car = new Car();
        $car->forceFill(['id' => 900140, 'vehicle_type' => 'motorhome', 'extras' => [
            ['group' => 'comfort_multimedia', 'items' => []],
            ['group' => 'exterior_equipment', 'items' => []],
            ['group' => 'interior_equipment', 'items' => []],
            ['group' => 'safety_performance', 'items' => ['Direção Adaptativa']],
        ]]);
        $data = (new CarPublicResource($car))->toArray(Request::create('/'));

        $this->assertSame(['Direção Adaptativa'], $data['extras'][3]['items'], 'items não muda');
        $this->assertSame(['x_adaptive_steering', 'Direção adaptativa', 'cabine'],
            [$data['extras'][3]['items_detail'][0]['key'], $data['extras'][3]['items_detail'][0]['label'], $data['extras'][3]['items_detail'][0]['section']]);
        $sections = array_column($data['presentation']['sections'], null, 'id');
        $this->assertContains('Direção adaptativa', $sections['cabine']['items']);
        $this->assertArrayNotHasKey('outros', $sections);
    }
}
