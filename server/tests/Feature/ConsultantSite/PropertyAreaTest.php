<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Models\Property;
use App\Services\Compass\Imoveis\ImoveisFacts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsConsultantSite;
use Tests\TestCase;

/**
 * As áreas com decimais (decimal(8,2)): o formulário aceita "92,1" e "92.1", a API do site dá o
 * número e a Bússola continua a calcular o preço por m² com ele.
 */
class PropertyAreaTest extends TestCase
{
    use BuildsConsultantSite;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildConsultantSite();
    }

    private function payload(array $over = []): array
    {
        return array_merge(['reference' => 'AREA-1', 'title' => 'T2 exemplo fictício', 'kind' => 'apartamento', 'typology' => 'T2', 'area_m2' => '92,1',
            'parish_id' => $this->parish, 'listing' => 'venda', 'price' => 276300, 'energy_class' => 'B', 'status' => 'disponivel', 'listed_at' => now()->subDay()->toDateString()], $over);
    }

    public function test_the_useful_area_takes_a_comma_or_a_dot_and_keeps_two_decimals(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/properties";
        $id = $this->actingAs($this->admin, 'sanctum')->postJson($url, $this->payload())->assertCreated()->json('data.id');
        $this->assertSame(92.1, Property::find($id)->area_m2);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", $this->payload(['area_m2' => '1 250,55']))->assertOk();
        $this->assertSame(1250.55, Property::find($id)->area_m2);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", $this->payload(['area_m2' => '92.1']))->assertOk();
        $this->assertSame(92.1, Property::find($id)->area_m2);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", $this->payload(['area_m2' => 85]))->assertOk();
        $this->assertSame(85.0, Property::find($id)->area_m2);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", $this->payload(['area_m2' => 'noventa']))->assertStatus(422)
            ->assertJsonPath('errors.area_m2.0', 'A área é um número (por exemplo, 92,1).');
        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", $this->payload(['area_m2' => '92,125']))->assertStatus(422)
            ->assertJsonPath('errors.area_m2.0', 'A área leva no máximo duas casas decimais.');
        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", $this->payload(['area_m2' => '']))->assertOk();
        $this->assertNull(Property::find($id)->area_m2);
    }

    public function test_the_gross_area_on_the_site_card_takes_decimals_and_the_site_api_gives_the_number(): void
    {
        $p = $this->publishedProperty(['area_m2' => 92.1]);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/companies/{$this->company->id}/properties/{$p->id}/site", ['gross_area_m2' => '110,5'])->assertOk();
        $this->assertSame(110.5, $p->fresh()->gross_area_m2);

        $card = $this->siteApi('GET', "properties/{$p->slug}")->assertOk()->json('data');
        $this->assertEquals(92.1, $card['area_m2']);
        $this->assertEquals(110.5, $card['gross_area_m2']);
    }

    public function test_the_area_label_and_the_compass_price_per_square_metre(): void
    {
        $this->assertSame('92,1 m²', Property::areaLabel(92.1));
        $this->assertSame('92 m²', Property::areaLabel(92.0));
        $this->assertSame('1 250,55 m²', Property::areaLabel(1250.55));
        $this->assertNull(Property::areaLabel(null));

        $p = $this->makeProperty(['area_m2' => 92.1, 'price' => 276300]);
        $row = ImoveisFacts::forCompany($this->company->id, CarbonImmutable::now('Europe/Lisbon'))[$p->id];
        $this->assertSame(3000, $row['price_m2']);
        $this->assertSame(92.1, $row['area_m2']);
    }
}
