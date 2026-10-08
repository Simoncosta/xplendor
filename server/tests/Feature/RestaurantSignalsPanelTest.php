<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\PingwinLocation;
use App\Models\RestaurantDataQuality;
use App\Models\RestaurantSignal;
use App\Models\RestaurantSignalAction;
use App\Models\User;
use App\Services\Brand\CreativeAiService;
use App\Services\Brand\CreativeFormatAdvisor;
use App\Services\CompanyModuleService;
use App\Services\Editorial\EditorialIdeasAiService;
use App\Services\Restaurant\RestaurantSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — F3: o painel "O que publicar e quando" (API), ignorar com registo, criar a
 * ideia na Linha Editorial, permissões e os sinais na IA.
 */
class RestaurantSignalsPanelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $loc;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009910', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        app(CompanyModuleService::class)->enable($this->company->id, 'linha_editorial');
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->loc = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '1', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        // Sinais "acabados de calcular" (o painel não recalcula).
        RestaurantDataQuality::create(['company_id' => $this->company->id, 'signals_computed_at' => now(), 'signals_availability' => []]);
        $this->signal('weak_period:1:2:dia', 'weak_period', 'suggestion', 'alta', 1310, 'Terça na loja Baixa', '2026-10-11', ['weekday' => 2, 'target_date' => '2026-10-13']);
        $this->signal('item_up:1:A', 'item_up', 'suggestion', 'media', 230, 'Copo Sangria em destaque', '2026-10-09');
        $this->signal('stale_item:1:B', 'stale_item', 'suggestion', 'media', 150, 'Voltar a mostrar: Piu-Piu', '2026-10-09');
        $this->signal('top_items:1', 'top_items', 'info', 'alta', 0);
    }

    private function signal(string $key, string $type, string $kind, string $confidence, int $priority, ?string $theme = null, ?string $date = null, array $numbers = []): void
    {
        RestaurantSignal::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'type' => $type, 'signal_key' => $key, 'kind' => $kind,
            'confidence' => $confidence, 'title' => "Título {$key}", 'sentence' => "Frase de {$key}.", 'numbers' => $numbers, 'sample' => [],
            'theme' => $theme, 'suggested_date' => $date, 'priority' => $priority, 'computed_at' => now()]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/integrations/pingwin/signals{$suffix}";
    }

    public function test_panel_lists_suggestions_by_priority_and_information_apart(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $this->assertSame(['weak_period:1:2:dia', 'item_up:1:A', 'stale_item:1:B'], array_column($data['suggestions'], 'key'));
        $this->assertSame(['item_up:1:A'], array_column($data['changes'], 'key'));
        $this->assertSame(['top_items:1'], array_column($data['top_items'], 'key'));
        $this->assertSame('Baixa', $data['suggestions'][0]['location']);
        $this->assertTrue($data['can_act']);
        $this->assertContains('Imagem única', $data['formats']);
        // Dias especiais dos próximos 90 dias, para o modal sugerir "Sazonal" nessas datas.
        $this->assertContains('Sazonal', $data['formats']);
        $this->assertSame('Imaculada Conceição', $data['special_days']['2026-12-08'] ?? null);
        $this->assertSame('Natal', $data['special_days']['2026-12-25'] ?? null);
        $this->assertArrayNotHasKey('2026-10-05', $data['special_days']);
        $this->assertArrayNotHasKey('2027-04-25', $data['special_days']);
    }

    public function test_ignore_hides_for_four_weeks_with_a_record_and_restore_brings_it_back(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/ignore'), ['key' => 'item_up:1:A'])
            ->assertOk()->assertJsonPath('data.hidden_until', '2026-11-05');

        $data = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data');
        $this->assertNotContains('item_up:1:A', array_column($data['suggestions'], 'key'));
        $this->assertSame(1, $data['hidden_count']);
        $shown = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('?show_ignored=1'))->json('data');
        $hidden = collect($shown['suggestions'])->firstWhere('key', 'item_up:1:A');
        $this->assertTrue($hidden['hidden']);
        $this->assertSame('2026-11-05', $hidden['hidden_until']);

        // Passadas as 4 semanas, volta a aparecer sozinha.
        $this->travelTo('2026-11-06 10:00:00');
        RestaurantDataQuality::query()->update(['signals_computed_at' => now()]);
        $this->assertContains('item_up:1:A', array_column($this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data.suggestions'), 'key'));

        $this->travelTo('2026-10-08 12:00:00');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/restore'), ['key' => 'item_up:1:A'])->assertOk();
        $this->assertContains('item_up:1:A', array_column($this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data.suggestions'), 'key'));
        $this->assertSame(['ignored', 'restored'], RestaurantSignalAction::orderBy('id')->pluck('action')->all());
        $this->assertSame([$this->admin->id], RestaurantSignalAction::distinct()->pluck('user_id')->all());
    }

    public function test_create_post_makes_an_idea_only_in_an_open_month(): void
    {
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
        $body = ['key' => 'weak_period:1:2:dia', 'title' => 'Terça na loja Baixa', 'publish_date' => '2026-10-11', 'networks' => ['instagram'], 'format' => 'Imagem única'];

        $res = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/post'), $body)->assertOk();

        $post = EditorialPost::findOrFail($res->json('data.post.id'));
        $this->assertSame(EditorialPost::STAGE_IDEA, $post->stage);
        $this->assertSame('Terça na loja Baixa', $post->title);
        $this->assertSame('2026-10-11', $post->publish_date->toDateString());
        $this->assertSame(['instagram'], $post->networks()->pluck('network')->all());
        $this->assertDatabaseHas('restaurant_signal_actions', ['signal_key' => 'weak_period:1:2:dia', 'action' => 'post_created', 'editorial_post_id' => $post->id]);
        $shown = collect($this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data.suggestions'))->firstWhere('key', 'weak_period:1:2:dia');
        $this->assertSame($post->id, $shown['post']['id']);

        // Repetir o título no mesmo mês: recusado.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/post'), $body)->assertStatus(422)->assertJsonPath('errors.title.0', 'Já existe uma publicação com este título nesse mês.');
        // Mês fechado: nunca se abre sozinho.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/post'), ['publish_date' => '2026-11-03', 'title' => 'Outra'] + $body)
            ->assertStatus(422)->assertJsonPath('errors.publish_date.0', 'O mês de novembro de 2026 não está aberto na Linha Editorial. Abra-o lá primeiro.');
        // Sugestão que já não existe.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/post'), ['key' => 'nao:existe', 'title' => 'X'] + $body)->assertStatus(422);
        $this->assertSame(1, EditorialPost::count());
    }

    public function test_who_can_act_and_tenancy(): void
    {
        // Sem a Linha Editorial: vê, mas não cria nem ignora.
        app(CompanyModuleService::class)->disable($this->company->id, 'linha_editorial');
        $data = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $this->assertFalse($data['can_act']);
        $this->assertSame('A Linha Editorial não está ativa nesta empresa.', $data['can_act_reason']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/ignore'), ['key' => 'item_up:1:A'])->assertStatus(403);

        // Produção pela equipa: o cliente não produz.
        app(CompanyModuleService::class)->enable($this->company->id, 'linha_editorial');
        $this->company->forceFill(['content_production_mode' => 'team'])->save();
        $this->assertFalse($this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data.can_act'));
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/post'), ['key' => 'item_up:1:A'])->assertStatus(403);

        // Outra empresa: nada.
        $other = Company::create(['nipc' => '500009911', 'fiscal_name' => 'Outro', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->getJson($this->url())->assertStatus(403);
        $this->assertSame(0, RestaurantSignalAction::count());
    }

    public function test_switch_off_shows_nothing(): void
    {
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $data = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $this->assertFalse($data['enabled']);
        $this->assertSame([], $data['suggestions']);
    }

    // ── A IA ───────────────────────────────────────────────────────────────────

    public function test_ai_lines_only_confident_visible_and_relevant_signals(): void
    {
        $service = app(RestaurantSignalService::class);
        RestaurantSignalAction::create(['company_id' => $this->company->id, 'signal_key' => 'stale_item:1:B', 'action' => 'ignored', 'hidden_until' => '2026-11-05']);

        $october = $service->aiLines($this->company->id, '2026-10-01', '2026-10-31', 8);
        $this->assertSame([
            '- Frase de weak_period:1:2:dia. (confiança alta)',
            '- Frase de item_up:1:A. (confiança média)',
            '- Frase de top_items:1. (confiança alta)',
        ], $october);
        // Noutro mês, o período fraco só entra pelo dia da semana.
        $this->assertNotContains('- Frase de weak_period:1:2:dia. (confiança alta)', $service->aiLines($this->company->id, '2026-12-01', '2026-12-31'));
        $this->assertContains('- Frase de weak_period:1:2:dia. (confiança alta)', $service->aiLines($this->company->id, '2026-12-01', '2026-12-01', 5, 2));
        // Sem o interruptor ou sem o PingWin: nada.
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $this->assertSame([], $service->aiLines($this->company->id));
    }

    public function test_ideas_and_creative_prompts_carry_the_restaurant_block_only_with_signals(): void
    {
        $formats = [];
        foreach (array_keys(EditorialPost::MEDIA_FORMATS) as $channel) {
            $formats[$channel] = ['source' => CreativeFormatAdvisor::SOURCE_NONE, 'top' => []];
        }
        $ideas = ['year' => 2026, 'month' => 10, 'month_key' => '2026-10', 'first_day' => '2026-10-08', 'last_day' => '2026-10-31',
            'company' => ['name' => 'Yuko', 'sector' => 'Restauração'], 'anchors' => [], 'existing' => [], 'profile' => null, 'has_profile' => false, 'formats' => $formats];
        $creative = ['post' => ['id' => 1, 'date' => '2026-10-13', 'theme' => 'Terça', 'channel' => 'instagram', 'content_type' => 'Imagem única', 'media_format' => null, 'keyword' => null],
            'anchor' => null, 'company' => ['name' => 'Yuko', 'sector' => 'Restauração'], 'profile' => null,
            'format' => ['source' => CreativeFormatAdvisor::SOURCE_NONE, 'reason' => 'no_followers', 'ranked' => [], 'followers' => null, 'band' => null, 'source_label' => null, 'source_url' => null]];
        $lines = ['- As terças na loja Baixa ficaram 26% abaixo da média. (confiança alta)'];

        $withIdeas = app(EditorialIdeasAiService::class)->messages($ideas + ['restaurant' => $lines]);
        $this->assertStringContainsString('DADOS DO RESTAURANTE', $withIdeas[1]['content']);
        $this->assertStringContainsString('As terças na loja Baixa ficaram 26% abaixo da média.', $withIdeas[1]['content']);
        $this->assertStringContainsString('não inventes números nem afirmes causas', $withIdeas[0]['content']);
        $this->assertStringNotContainsString('DADOS DO RESTAURANTE', app(EditorialIdeasAiService::class)->messages($ideas)[1]['content']);

        $withCreative = app(CreativeAiService::class)->messages($creative + ['restaurant' => $lines]);
        $this->assertStringContainsString('DADOS DO RESTAURANTE', $withCreative[1]['content']);
        $this->assertStringNotContainsString('DADOS DO RESTAURANTE', app(CreativeAiService::class)->messages($creative + ['restaurant' => []])[1]['content']);
    }
}
