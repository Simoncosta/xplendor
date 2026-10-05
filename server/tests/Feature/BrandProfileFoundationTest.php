<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\SocialFollowerSnapshot;
use App\Models\User;
use App\Services\Ai\AiRequestQuota;
use App\Services\Social\FollowerSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perfil da Marca, Parte A: segurança do token antigo da Meta, campos da F1 no perfil,
 * limite de IA por modo e seguidores (registo manual, leitura automática que prevalece,
 * série com falhas como null, permissões, impersonation e tenancy).
 */
class BrandProfileFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $adminA;
    private User $userA;
    private User $adminB;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Lisbon'));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500030001', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500030002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $x = Company::create(['nipc' => '500030003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $x->id, 'role' => 'root']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function impersonationHeaders(User $target): array
    {
        $this->app['auth']->forgetGuards();
        $nt = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);

        return ['Authorization' => 'Bearer ' . $nt->plainTextToken];
    }

    // ── 0. Segurança: token antigo da Meta ─────────────────────────────────────

    public function test_legacy_facebook_token_is_never_writable_nor_returned(): void
    {
        DB::table('companies')->where('id', $this->a->id)->update(['facebook_access_token' => 'EAAG-segredo']);

        $this->as($this->adminA)->getJson("/api/v1/companies/{$this->a->id}")
            ->assertOk()->assertJsonMissingPath('data.facebook_access_token');
        $this->assertStringNotContainsString('EAAG-segredo', $this->as($this->adminA)->getJson("/api/v1/companies/{$this->a->id}")->getContent());

        $this->as($this->adminA)->putJson("/api/v1/companies/{$this->a->id}", ['fiscal_name' => 'Quebom Lda', 'facebook_access_token' => 'EAAG-novo'])->assertOk();
        $this->assertSame('EAAG-segredo', DB::table('companies')->where('id', $this->a->id)->value('facebook_access_token'));

        $this->assertNotContains('facebook_access_token', (new Company())->getFillable());
    }

    // ── 1. Perfil da Marca: campos da F1 ──────────────────────────────────────

    public function test_brand_profile_saves_the_f1_fields_normalized(): void
    {
        $this->as($this->adminA)->putJson($this->url($this->a, '/brand-profile'), [
            'tone_of_voice'    => 'Próximo',
            'pillars'          => [['name' => ' Bastidores ', 'description' => 'A cozinha por dentro'], ['name' => 'Sazonalidade']],
            'hashtags_default' => ['#Porto', 'comida boa', 'porto', '##Porto', ' '],
            'cta_default'      => '<b>Reserve já</b>',
            'emoji_policy'     => 'light',
            'notes'            => 'Nunca falar de preços.',
        ])->assertOk()
            ->assertJsonPath('data.pillars.0', ['name' => 'Bastidores', 'description' => 'A cozinha por dentro'])
            ->assertJsonPath('data.pillars.1', ['name' => 'Sazonalidade', 'description' => null])
            ->assertJsonPath('data.hashtags_default', ['#Porto', '#comidaboa', '#porto'])
            ->assertJsonPath('data.cta_default', 'Reserve já')
            ->assertJsonPath('data.emoji_policy', 'light')
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.can_edit', true);

        $this->as($this->adminA)->putJson($this->url($this->a, '/brand-profile'), ['emoji_policy' => 'muitos'])->assertStatus(422);
        $this->as($this->adminA)->putJson($this->url($this->a, '/brand-profile'), ['pillars' => [['description' => 'sem nome']]])->assertStatus(422);
    }

    public function test_brand_profile_reports_who_can_edit_and_keeps_tenancy(): void
    {
        $this->as($this->userA)->getJson($this->url($this->a, '/brand-profile'))->assertOk()
            ->assertJsonPath('data.can_edit', false)->assertJsonPath('data.is_empty', true);
        $this->as($this->userA)->putJson($this->url($this->a, '/brand-profile'), ['notes' => 'x'])->assertStatus(403);
        $this->as($this->adminB)->getJson($this->url($this->a, '/brand-profile'))->assertStatus(403);
        $this->as($this->root)->getJson($this->url($this->a, '/brand-profile'))->assertOk()->assertJsonPath('data.can_edit', true);
    }

    // ── 2. IA: limite POR MODO ─────────────────────────────────────────────────

    public function test_ai_monthly_cap_is_counted_per_mode(): void
    {
        config(['services.openai.ai_monthly_caps' => ['blog' => 2, 'brand_profile' => 1, 'creative' => 3]]);
        $row = fn (string $mode, string $status = 'done', $when = null) => AiRequest::create([
            'company_id' => $this->a->id, 'mode' => $mode, 'variant' => $mode === 'blog' ? 'topic' : null, 'status' => $status,
            'input' => [], 'model' => 'gpt-4o', 'prompt_version' => 'v1',
        ])->forceFill(['created_at' => $when ?? now()])->save();

        $row('blog');
        $row('blog');
        $row('blog', 'error');                                  // falhas não contam
        $row('creative', 'done', now()->subMonthNoOverflow());  // mês anterior não conta

        $this->assertSame(2, AiRequestQuota::used($this->a->id, 'blog'));
        $this->assertTrue(AiRequestQuota::exhausted($this->a->id, 'blog'));
        $this->assertSame(0, AiRequestQuota::used($this->a->id, 'brand_profile'));   // o blog não gasta o limite do perfil
        $this->assertFalse(AiRequestQuota::exhausted($this->a->id, 'brand_profile'));
        $this->assertSame(0, AiRequestQuota::used($this->a->id, 'creative'));
        $this->assertSame(0, AiRequestQuota::used($this->b->id, 'blog'));           // por empresa
        $this->assertSame([2, 1, 3], [AiRequestQuota::cap('blog'), AiRequestQuota::cap('brand_profile'), AiRequestQuota::cap('creative')]);
    }

    // ── 3. Seguidores ─────────────────────────────────────────────────────────

    public function test_admin_records_followers_once_per_platform_per_day(): void
    {
        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'instagram', 'followers_count' => 1200])
            ->assertOk()->assertJsonPath('data.platforms.instagram.current', ['count' => 1200, 'date' => '2026-10-05', 'source' => 'manual']);
        // Mesmo dia: corrige, não duplica.
        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'instagram', 'followers_count' => 1250])->assertOk();
        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'facebook', 'followers_count' => 800])->assertOk();

        $this->assertSame(1, SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'instagram')->count());
        $this->assertSame(1250, SocialFollowerSnapshot::where('platform', 'instagram')->value('followers_count'));
        $this->assertSame($this->adminA->id, SocialFollowerSnapshot::where('platform', 'instagram')->value('recorded_by_user_id'));

        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'tiktok', 'followers_count' => 5])->assertStatus(422);
        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'instagram', 'followers_count' => -1])->assertStatus(422);
    }

    public function test_who_can_record_followers(): void
    {
        $payload = ['platform' => 'instagram', 'followers_count' => 10];

        $this->as($this->userA)->getJson($this->url($this->a, '/followers'))->assertOk()->assertJsonPath('data.can_record', false);
        $this->as($this->userA)->postJson($this->url($this->a, '/followers'), $payload)->assertStatus(403);
        $this->as($this->adminB)->getJson($this->url($this->a, '/followers'))->assertStatus(403);
        $this->as($this->adminB)->postJson($this->url($this->a, '/followers'), $payload)->assertStatus(403);
        $this->as($this->root)->postJson($this->url($this->a, '/followers'), $payload)->assertOk();

        // A equipa XPLENDOR em impersonation de um utilizador normal também regista.
        $this->withHeaders($this->impersonationHeaders($this->userA))
            ->postJson($this->url($this->a, '/followers'), ['platform' => 'facebook', 'followers_count' => 33])->assertOk();
        $this->assertSame(33, SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'facebook')->value('followers_count'));
        $this->assertSame(0, SocialFollowerSnapshot::where('company_id', $this->b->id)->count());
    }

    public function test_automatic_reading_wins_over_manual(): void
    {
        $svc = app(FollowerSnapshotService::class);

        // Manual primeiro, depois a leitura automática do mesmo dia: substitui.
        $svc->recordManual($this->a->id, 'instagram', 1000, $this->adminA);
        $svc->recordAutomatic($this->a->id, 'instagram', 1010, 300, 95);
        $row = SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'instagram')->sole();
        $this->assertSame([1010, 'api', 300, 95, null], [$row->followers_count, $row->source, $row->follows_count, $row->media_count, $row->recorded_by_user_id]);

        // Depois de uma leitura automática, o manual do mesmo dia é recusado e nada muda.
        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'instagram', 'followers_count' => 5])->assertStatus(409);
        $this->assertSame(1010, $row->fresh()->followers_count);
    }

    public function test_growth_series_keeps_gaps_as_gaps_and_marks_the_source(): void
    {
        $svc = app(FollowerSnapshotService::class);
        $svc->recordAutomatic($this->a->id, 'instagram', 900, null, null, 'api', '2026-10-01');
        SocialFollowerSnapshot::create(['company_id' => $this->a->id, 'platform' => 'instagram', 'snapshot_date' => '2026-10-03',
            'followers_count' => 950, 'source' => 'manual', 'recorded_by_user_id' => $this->adminA->id]);
        SocialFollowerSnapshot::create(['company_id' => $this->b->id, 'platform' => 'instagram', 'snapshot_date' => '2026-10-02',
            'followers_count' => 999999, 'source' => 'manual']);

        $data = $this->as($this->userA)->getJson($this->url($this->a, '/followers?days=7'))->assertOk()->json('data');

        $this->assertSame(['2026-09-29', '2026-10-05'], [$data['from'], $data['to']]);
        $ig = collect($data['platforms']['instagram']['series'])->keyBy('date');
        $this->assertCount(7, $ig);
        $this->assertSame([null, null, 900, null, 950, null, null], $ig->pluck('count')->all());   // dias sem registo = null
        $this->assertSame('api', $ig['2026-10-01']['source']);
        $this->assertSame('manual', $ig['2026-10-03']['source']);
        $this->assertSame(['count' => 950, 'date' => '2026-10-03', 'source' => 'manual'], $data['platforms']['instagram']['current']);
        $this->assertNull($data['platforms']['facebook']['current']);
        $this->assertSame(array_fill(0, 7, null), array_column($data['platforms']['facebook']['series'], 'count'));
    }
}
