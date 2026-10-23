<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Models\Company;
use App\Models\ContentSector;
use App\Models\RadarItem;
use App\Models\RadarTopic;
use App\Services\CompanyModuleService;
use App\Services\Radar\Relevance\RadarRelevanceService;
use App\Services\Radar\Relevance\SectorBriefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Bússola 2: o pedido de relevância leva o texto próprio de cada ramo (o que interessa a um stand,
 * a um restaurante, a um consultor imobiliário, a uma agência ou a um SaaS), não só o nome. Todos os
 * ramos selecionáveis têm texto, incluindo o "Software (SaaS)". IA simulada.
 */
class RadarSectorBriefsTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;

    public function test_every_selectable_sector_has_its_own_text_including_saas(): void
    {
        $selectable = ContentSector::where('is_selectable', true)->pluck('name', 'slug')->all();
        $this->assertArrayHasKey('software-saas', $selectable);
        $this->assertSame('Software (SaaS)', $selectable['software-saas']);
        $this->assertSame([], SectorBriefs::missing($selectable));
        foreach (array_keys($selectable) as $slug) {
            $this->assertNotSame(SectorBriefs::GENERIC, SectorBriefs::for($slug));
            $this->assertStringNotContainsString('—', SectorBriefs::for($slug), 'sem travessões');
        }
        $this->assertSame(SectorBriefs::GENERIC, SectorBriefs::for('ramo-novo'));
        $this->assertSame(['ramo-novo'], SectorBriefs::missing(['restauracao' => 'Restauração', 'ramo-novo' => 'Ramo novo']));
    }

    public function test_the_relevance_request_carries_the_sector_text(): void
    {
        config(['radar.enabled' => true]);
        $this->configureAi();
        Http::preventStrayRequests();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['restauracao' => '500019401', 'software-saas' => '500019402'] as $slug => $nipc) {
            $c = Company::create(['nipc' => $nipc, 'fiscal_name' => "Empresa {$nipc} Lda", 'plan_id' => $plan, 'subscription_status' => 'active',
                'content_sector_id' => ContentSector::where('slug', $slug)->value('id')]);
            app(CompanyModuleService::class)->enable($c->id, 'radar');
        }
        RadarTopic::create(['kind' => 'trend', 'title' => 'Feira do queijo', 'normalized_key' => 'trend:feira do queijo', 'first_seen_at' => now(), 'last_seen_at' => now(),
            'filter_verdict' => RadarItem::VERDICT_PASSED, 'filter_reasons' => [], 'filter_flags' => [], 'sources' => [], 'window_until' => now()->addDay(), 'expires_at' => now()->addDays(30)]);
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse(['avaliacoes' => []])]);

        $this->assertSame(2, app(RadarRelevanceService::class)->evaluate()['pedidos'], 'um pedido por ramo');
        $bodies = [];
        Http::assertSent(function ($req) use (&$bodies) {
            $bodies[] = json_decode((string) $req->body(), true)['messages'][0]['content'] ?? null;

            return true;
        });
        $text = json_encode($bodies, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(mb_substr(SectorBriefs::for('restauracao'), 0, 60), $text);
        $this->assertStringContainsString(mb_substr(SectorBriefs::for('software-saas'), 0, 60), $text);
    }
}
