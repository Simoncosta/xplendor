<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Company;
use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\EditorialAnchorResolver;
use App\Services\Radar\Calendar\IneReleaseCalendar;
use App\Services\Radar\Calendar\OfficialDates;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bússola 6: o leitor do calendário do INE (amostra simulada; a leitura real só com o endereço e as
 * chamadas externas ligadas), o aviso ao root de "rever a data" e as âncoras do imobiliário (IMI e
 * IRS, com o dia útil seguinte).
 */
class CompassCalendarPartTwoTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-23 10:00:00';
    private const FIXTURE = 'tests/Fixtures/ine/calendario-difusao.ics';

    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        Http::preventStrayRequests();
        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function company(string $nipc, string $slug): Company
    {
        return Company::create(['nipc' => $nipc, 'fiscal_name' => "Empresa {$nipc} Lda", 'trade_name' => "Empresa {$nipc}", 'plan_id' => $this->planId,
            'subscription_status' => 'active', 'content_sector_id' => ContentSector::where('slug', $slug)->value('id')]);
    }

    // ── O calendário do INE ──────────────────────────────────────────────────

    public function test_the_ics_reader_keeps_only_the_followed_publications(): void
    {
        $rows = IneReleaseCalendar::parse((string) file_get_contents(base_path(self::FIXTURE)));
        $this->assertSame([
            ['ine.juro_credito_habitacao', '2026-11-19'],
            ['ine.avaliacao_bancaria', '2026-11-25'],
            ['ine.precos_habitacao_local', '2099-01-15'],
        ], array_map(fn ($r) => [$r['source_key'], $r['starts_on']], $rows));
        $this->assertSame('Inquérito à Avaliação Bancária na Habitação, Outubro de 2026', $rows[1]['title']);
        $this->assertStringEndsWith('3.º Trimestre (data fictícia da amostra)', $rows[2]['title'], 'a linha dobrada junta-se');
    }

    public function test_the_command_simulates_saves_from_the_sample_and_feeds_the_official_dates(): void
    {
        $this->artisan('ine:release-calendar', ['--fixture' => self::FIXTURE])->assertSuccessful();
        $this->assertSame(0, DB::table('official_calendar_dates')->count(), 'sem --execute não grava');

        $this->artisan('ine:release-calendar', ['--fixture' => self::FIXTURE, '--execute' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('official_calendar_dates')->count());
        $this->artisan('ine:release-calendar', ['--fixture' => self::FIXTURE, '--execute' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('official_calendar_dates')->count(), 'ler outra vez não duplica');

        $e = OfficialDates::entries();
        $this->assertSame([['2026-10-23'], ['2099-01-15']], $e['ine.precos_habitacao_local']['dates']);
        $this->assertSame('2026-10-23', $e['ine.precos_habitacao_local']['confirmed_on']);
        $this->assertSame([['2026-10-21'], ['2026-11-19']], $e['ine.juro_credito_habitacao']['dates'], 'a data já no código não se repete');
        $this->assertSame('2026-10-12', $e['bce.monetary_policy_decision']['confirmed_on'], 'as outras entradas não mudam');
        Http::assertNothingSent();
    }

    public function test_the_real_read_needs_the_address_and_the_external_switch(): void
    {
        config(['radar.ine_calendar.url' => '']);
        $this->artisan('ine:release-calendar', ['--execute' => true])->assertSuccessful();

        config(['radar.ine_calendar.url' => 'https://calendario.exemplo.invalid/ine.ics', 'scheduler.external_jobs' => false]);
        $this->artisan('ine:release-calendar', ['--execute' => true])->assertFailed();
        $this->artisan('ine:release-calendar')->assertSuccessful();
        Http::assertNothingSent();

        config(['scheduler.external_jobs' => true]);
        Http::fake(['calendario.exemplo.invalid/*' => Http::response((string) file_get_contents(base_path(self::FIXTURE)))]);
        $this->artisan('ine:release-calendar', ['--execute' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('official_calendar_dates')->count());
        $this->assertContains('ine-release-calendar-weekly', config('scheduler.external_job_names'));
    }

    // ── "Rever a data" ───────────────────────────────────────────────────────

    public function test_the_root_gets_one_review_reminder_per_month(): void
    {
        $team = $this->company('500019401', 'universal');
        config(['quotes.team_company_id' => $team->id]);

        $this->artisan('calendar:review-reminders')->assertSuccessful();
        $this->artisan('calendar:review-reminders')->assertSuccessful();
        $alerts = Alert::where('company_id', $team->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('Rever as datas do calendário (outubro de 2026)', $alerts[0]->title);
        $this->assertStringContainsString('INE: Taxas de Juro Implícitas no Crédito à Habitação (confirmada a 14/10/2026', $alerts[0]->message);
        // Confirmados a 23/10/2026: os feriados nacionais e o Santo António já não pedem revisão; não há datas por confirmar.
        $this->assertStringNotContainsString('por confirmar', $alerts[0]->message);
        $this->assertStringNotContainsString('Feriados nacionais', $alerts[0]->message);
        $this->assertStringNotContainsString('Santo António', $alerts[0]->message, 'o Santo António revê-se em maio');
        $this->assertStringNotContainsString('BCE', $alerts[0]->message, 'o BCE revê-se em junho e dezembro');

        $this->assertArrayHasKey('bce.monetary_policy_decision', OfficialDates::dueForReview(12));
        $this->assertArrayHasKey('fiscal.irs.entrega', OfficialDates::dueForReview(3));
        $this->assertArrayNotHasKey('fiscal.irs.entrega', OfficialDates::dueForReview(4));

        // No mês seguinte, um aviso novo.
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 10:00:00'));
        $this->artisan('calendar:review-reminders')->assertSuccessful();
        $this->assertSame(2, Alert::where('company_id', $team->id)->count());
    }

    // ── Âncoras do imobiliário ───────────────────────────────────────────────

    public function test_the_real_estate_anchors_move_to_the_next_business_day(): void
    {
        $sector = ContentSector::where('slug', 'imobiliario')->value('id');
        $anchors = ContentAnchor::where('sector_id', $sector)->where('origin', 'fiavel')->get()->keyBy('title');
        $this->assertSame(['IMI: 2.ª prestação', 'IMI: pagamento único ou 1.ª prestação', 'IMI: última prestação', 'IRS: entrega da declaração', 'IRS: pagamento ou reembolso'],
            $anchors->keys()->sort()->values()->all());
        $this->assertTrue($anchors->every(fn ($a) => $a->next_business_day && $a->country === 'PT'));
        $this->assertTrue($anchors->every(fn ($a) => str_contains((string) $a->notes, 'Portal das Finanças (Autoridade Tributária), confirmada a 23/10/2026')), 'confirmadas a 23/10/2026');

        $r = app(EditorialAnchorResolver::class);
        $this->assertSame('2026-06-01', $r->resolve($anchors['IMI: pagamento único ou 1.ª prestação'], 2026)['date'], '31/05/2026 é domingo');
        $this->assertSame('2027-05-31', $r->resolve($anchors['IMI: pagamento único ou 1.ª prestação'], 2027)['date']);
        $this->assertSame('2025-09-01', $r->resolve($anchors['IRS: pagamento ou reembolso'], 2025)['date'], '31/08/2025 é domingo');
        $irs = $r->resolve($anchors['IRS: entrega da declaração'], 2029);
        $this->assertSame(['2029-04-01', '2029-07-02'], [$irs['start'], $irs['end']], '30/06/2029 é sábado: só o fim muda');
        $this->assertSame('2026-12-28', EditorialAnchorResolver::nextBusinessDay('2026-12-25'), 'o Natal é feriado');
        $this->assertSame('2026-12-08', $r->resolve(ContentAnchor::where('title', 'Imaculada Conceição')->firstOrFail(), 2026)['date'], 'as outras âncoras não mudam');

        // Na grelha de uma empresa do imobiliário.
        $c = $this->company('500019402', 'imobiliario');
        app(CompanyModuleService::class)->enable($c->id, 'linha_editorial');
        $admin = User::factory()->create(['company_id' => $c->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $items = collect($this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$c->id}/editorial/calendar")->assertOk()->json('data.items'));
        $this->assertSame('2026-06-01', $items->first(fn ($i) => $i['title'] === 'IMI: pagamento único ou 1.ª prestação' && $i['occ_year'] === 2026)['date']);
        $this->assertTrue($items->contains(fn ($i) => $i['title'] === 'IMI: última prestação' && $i['date'] === '2026-11-30'));
    }
}
