<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Company;
use App\Models\TrackingEventMap;
use App\Services\Tracking\TrackingHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fatia 2 (polimentos) — tracking:map: a correspondência GA4 → XPLENDOR de um site sem SQL à mão,
 * com validação. Um tipo com linhas próprias usa só essas; os outros continuam com os nomes iguais.
 */
class TrackingMapCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $quebom;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->quebom = Company::create(['nipc' => '500000901', 'fiscal_name' => 'Quebom Liberdade Sobre Rodas Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->quebom->forceFill(['slug' => 'quebom-liberdade-sobre-rodas-lda'])->save();
    }

    public function test_the_quebom_whatsapp_mapping_by_slug_and_by_id(): void
    {
        $this->artisan('tracking:map', ['empresa' => 'quebom-liberdade-sobre-rodas-lda', '--ga4' => 'whatsapp_click', '--type' => 'whatsapp_click',
            '--exclude-page-context' => 'floating_button'])
            ->expectsOutputToContain('Criada: whatsapp_click → whatsapp_click (sem os page_context floating_button).')
            ->assertSuccessful();
        $map = TrackingEventMap::where('company_id', $this->quebom->id)->sole();
        $this->assertSame(['exclude_page_context' => ['floating_button']], $map->filters);

        // De novo pelo id: atualiza (não duplica).
        $this->artisan('tracking:map', ['empresa' => (string) $this->quebom->id, '--ga4' => 'whatsapp_click', '--type' => 'whatsapp_click',
            '--exclude-page-context' => 'floating_button,global_widget'])->expectsOutputToContain('Atualizada')->assertSuccessful();
        $this->assertSame(1, TrackingEventMap::where('company_id', $this->quebom->id)->count());
        $this->assertSame(['floating_button', 'global_widget'], TrackingEventMap::where('company_id', $this->quebom->id)->value('filters')['exclude_page_context']);

        // A auditoria usa a própria no whatsapp_click e os nomes iguais nos outros tipos.
        $maps = collect(app(TrackingHealthService::class)->maps($this->quebom->id));
        $this->assertSame(['floating_button', 'global_widget'], $maps->firstWhere('xplendor_type', 'whatsapp_click')['filters']['exclude_page_context']);
        $this->assertSame('call_click', $maps->firstWhere('xplendor_type', 'call_click')['ga4_event']);
        $this->assertCount(count(TrackingHealthService::TYPES), $maps);

        $this->artisan('tracking:map', ['empresa' => (string) $this->quebom->id, '--list' => true])->expectsOutputToContain('Correspondência GA4 → XPLENDOR')->assertSuccessful();
        $this->artisan('tracking:map', ['empresa' => (string) $this->quebom->id, '--ga4' => 'whatsapp_click', '--type' => 'whatsapp_click', '--remove' => true])
            ->expectsOutputToContain('Apagada')->assertSuccessful();
        $this->assertSame(0, TrackingEventMap::count());
    }

    public function test_validation(): void
    {
        $this->artisan('tracking:map', ['empresa' => 'nao-existe', '--list' => true])->expectsOutputToContain('Empresa não encontrada')->assertFailed();
        $this->artisan('tracking:map', ['empresa' => (string) $this->quebom->id, '--type' => 'whatsapp_click'])->expectsOutputToContain('Indique --ga4')->assertFailed();
        $this->artisan('tracking:map', ['empresa' => (string) $this->quebom->id, '--ga4' => 'wa', '--type' => 'whatsapp'])->expectsOutputToContain('Indique --type')->assertFailed();
        $this->artisan('tracking:map', ['empresa' => (string) $this->quebom->id, '--ga4' => 'wa click', '--type' => 'whatsapp_click'])->assertFailed();
        $this->artisan('tracking:map', ['empresa' => (string) $this->quebom->id, '--ga4' => 'wa', '--type' => 'whatsapp_click', '--exclude-page-context' => 'x; drop'])
            ->expectsOutputToContain('page_context inválido')->assertFailed();
        $this->assertSame(0, TrackingEventMap::count());
    }
}
