<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Services\EditorialAnchorResolver;
use App\Services\EditorialLineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Linha Editorial: seeders de âncoras VARIÁVEIS por ramo (Parte 2+3). Confirma
 * que cada ramo tem as suas específicas (com gancho), que a herança funciona
 * (Autocaravanas = Universal + Automóvel + Autocaravanas), Carros vazio, e idempotência.
 */
class EditorialRamoAnchorsTest extends TestCase
{
    use RefreshDatabase;

    private EditorialLineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EditorialLineService(new EditorialAnchorResolver());
    }

    private function anchorsOf(string $slug): \Illuminate\Support\Collection
    {
        $sector = ContentSector::where('slug', $slug)->firstOrFail();
        return ContentAnchor::where('sector_id', $sector->id)->where('origin', 'variavel')->get();
    }

    public function test_cada_ramo_tem_as_suas_especificas_com_gancho(): void
    {
        $this->assertTrue($this->anchorsOf('automovel')->contains('title', 'Dia do 4x4'));
        $this->assertTrue($this->anchorsOf('autocaravanas')->contains('title', 'O Ano das Pontes'));
        $this->assertTrue($this->anchorsOf('domotica')->contains('title', 'Dia da Privacidade de Dados'));
        $this->assertTrue($this->anchorsOf('restauracao')->contains('title', 'Início da Primavera (nova carta)'));

        // Gancho (suggestion) presente.
        $dia4x4 = $this->anchorsOf('automovel')->firstWhere('title', 'Dia do 4x4');
        $this->assertNotEmpty($dia4x4->suggestion);
    }

    public function test_carros_nao_tem_ancoras_proprias_mas_herda_de_automovel(): void
    {
        $this->assertCount(0, $this->anchorsOf('carros'));

        // Carros herda de Automóvel: o "Dia do 4x4" (4/4) deve aparecer numa empresa de Carros.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 12:00:00', 'Europe/Lisbon'));
        $carrosCo = $this->makeCompany('carros');
        $titles = array_column($this->service->calendar($carrosCo)['items'], 'title');
        $this->assertContains('Dia do 4x4', $titles);
        CarbonImmutable::setTestNow();
    }

    public function test_autocaravanas_herda_universal_automovel_e_proprias(): void
    {
        // Janela Jun/2026..Mai/2027 cobre: Automóvel "Dia do 4x4" (4/4), Autocaravanas
        // "Reservas Antecipadas" (1/6), Universal (ex.: Natal 25/12 ou Ano Novo 1/1).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 12:00:00', 'Europe/Lisbon'));
        $co = $this->makeCompany('autocaravanas');
        $titles = array_column($this->service->calendar($co)['items'], 'title');

        $this->assertContains('Reservas Antecipadas', $titles);            // própria do ramo
        $this->assertContains('Dia do 4x4', $titles);                      // herdada de Automóvel
        $this->assertContains('Ano Novo', $titles);                        // herdada de Universal
        CarbonImmutable::setTestNow();
    }

    public function test_reseed_nao_duplica(): void
    {
        $before = $this->anchorsOf('restauracao')->count();

        // Re-correr o updateOrCreate do seeder (mesma chave sector_id+title) não deve duplicar.
        $sector = ContentSector::where('slug', 'restauracao')->firstOrFail();
        ContentAnchor::updateOrCreate(
            ['sector_id' => $sector->id, 'title' => 'Passagem de Ano'],
            ['country' => 'PT', 'origin' => 'variavel', 'rule_type' => 'fixa', 'month' => 12, 'day' => 15, 'suggestion' => 'x'],
        );

        $this->assertSame($before, $this->anchorsOf('restauracao')->count());
        $this->assertSame(1, ContentAnchor::where('sector_id', $sector->id)->where('title', 'Passagem de Ano')->count());
    }

    private function makeCompany(string $slug): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::create([
            'nipc' => (string) random_int(500000000, 599999999),
            'fiscal_name' => 'C Lda', 'plan_id' => $planId,
            'content_sector_id' => ContentSector::where('slug', $slug)->firstOrFail()->id,
        ]);
    }
}
