<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Public\CarPublicResource;
use App\Models\Car;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * API pública: cada grupo de extras mantém group e items como estão gravados e ganha items_detail,
 * com a chave, o rótulo e o bloco de cada item (os mesmos da apresentação). Mudança aditiva.
 */
class CarPublicResourceExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $attributes): array
    {
        $car = new Car();
        $car->forceFill(['id' => 900100, 'vehicle_type' => 'motorhome'] + $attributes);

        return (new CarPublicResource($car))->toArray(Request::create('/'));
    }

    public function test_items_stay_as_they_are_and_items_detail_is_added(): void
    {
        // O modelo devolve sempre os 4 grupos, por esta ordem.
        $extras = [
            ['group' => 'comfort_multimedia', 'items' => []],
            ['group' => 'exterior_equipment', 'items' => ['Gancho de Reboque', 'Texto livre']],
            ['group' => 'interior_equipment', 'items' => []],
            ['group' => 'safety_performance', 'items' => ['ASR', 'Travão de Mão Eléctrico']],
        ];
        $data = $this->payload(['extras' => $extras]);

        foreach ($extras as $i => $group) {
            $this->assertSame($group['group'], $data['extras'][$i]['group']);
            $this->assertSame($group['items'], $data['extras'][$i]['items'], 'items não muda');
        }
        $this->assertSame([
            ['text' => 'ASR', 'key' => 'x_asr', 'label' => 'ASR', 'section' => 'cabine', 'section_title' => 'Cabine de condução e segurança'],
            ['text' => 'Travão de Mão Eléctrico', 'key' => 'x_electric_handbrake', 'label' => 'Travão de mão elétrico', 'section' => 'cabine', 'section_title' => 'Cabine de condução e segurança'],
        ], $data['extras'][3]['items_detail']);
        $this->assertSame(['exterior', 'outros'], array_column($data['extras'][1]['items_detail'], 'section'));
        $this->assertSame([], $data['extras'][0]['items_detail']);

        // A apresentação continua a ler os mesmos itens.
        $cabin = array_column($data['presentation']['sections'], null, 'id')['cabine']['items'] ?? [];
        $this->assertContains('Travão de mão elétrico', $cabin);
    }

    public function test_without_extras_the_groups_come_empty(): void
    {
        $extras = $this->payload(['extras' => null])['extras'];
        $this->assertCount(4, $extras);
        foreach ($extras as $group) {
            $this->assertSame([[], []], [$group['items'], $group['items_detail']]);
        }
    }
}
