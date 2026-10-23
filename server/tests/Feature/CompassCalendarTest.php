<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContentSector;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Radar\Calendar\OfficialDates;
use App\Settings\Settings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bússola 5: o "Vem aí" sem o módulo radar (rotas marketing/calendar, no módulo linha_editorial), o
 * feriado municipal da empresa, as datas de Lisboa (o feriado de Santo António a 13 de junho e a noite de
 * 12 para a restauração, confirmados a 23/10/2026) e as datas oficiais na grelha da Linha Editorial.
 */
class CompassCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-23 10:00:00';

    private Company $company;
    private User $admin;
    private int $planId;
    private int $porto;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        Http::preventStrayRequests();
        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $district = DB::table('districts')->insertGetId(['name' => 'Porto', 'created_at' => now(), 'updated_at' => now()]);
        $this->porto = DB::table('municipalities')->insertGetId(['name' => 'Porto', 'district_id' => $district, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = $this->makeCompany('500019301', 'Tasca Exemplo');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $nipc, string $name, array $modules = ['linha_editorial']): Company
    {
        $c = Company::create(['nipc' => $nipc, 'fiscal_name' => "{$name} Lda", 'trade_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active',
            'content_sector_id' => ContentSector::where('slug', 'restauracao')->value('id'), 'municipality_id' => $this->porto]);
        foreach ($modules as $m) {
            app(CompanyModuleService::class)->enable($c->id, $m);
        }

        return $c;
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/marketing/calendar{$path}";
    }

    private function userWith(array $permissions): User
    {
        $profile = \App\Models\PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Perfil ' . count($permissions), 'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions($permissions);
        $u = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $u->forceFill(['profile_id' => $profile->id])->save();

        return $u;
    }

    public function test_the_calendar_shows_without_the_radar_module_with_the_company_holiday(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/marketing/radar")->assertStatus(403);
        $this->assertSame([], $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data.calendar'));

        Settings::setCompany($this->company->id, ['calendar.municipal_holiday' => '05/11'], $this->admin);
        $cal = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $this->assertTrue($cal['can_act']);
        $this->assertSame([[OfficialDates::COMPANY_HOLIDAY, '2026-11-05']], array_map(fn ($e) => [$e['key'], $e['occurrence']], $cal['calendar']));
        $this->assertSame('Configurações da empresa (Feriado municipal)', $cal['calendar'][0]['source']['name']);

        // Na grelha da Linha Editorial, só para ver.
        $official = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/editorial/calendar")->assertOk()->json('data.official');
        $this->assertSame(OfficialDates::COMPANY_HOLIDAY, $official[0]['key']);
        $this->assertSame('2026-11-05', $official[0]['starts_on']);
    }

    public function test_react_to_a_date_creates_one_idea_and_unconfirmed_dates_cannot_be_used(): void
    {
        Settings::setCompany($this->company->id, ['calendar.municipal_holiday' => '05/11'], $this->admin);
        $body = ['calendar_key' => OfficialDates::COMPANY_HOLIDAY, 'occurrence' => '2026-11-05', 'title' => 'O feriado do concelho', 'publish_date' => '2026-10-30',
            'networks' => ['instagram'], 'format' => 'Carrossel'];
        $id = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/react'), $body)->assertOk()->json('data.post.id');
        $post = EditorialPost::findOrFail($id);
        $this->assertSame(['calendario', OfficialDates::COMPANY_HOLIDAY . '@2026-11-05'], [$post->origin, $post->origin_ref]);
        $this->assertStringContainsString('Fonte: Configurações da empresa (Feriado municipal).', (string) $post->brief);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/react'), $body)->assertStatus(422)->assertJsonPath('errors.calendar_key.0', 'Já existe uma ideia para esta data.');
        $this->assertTrue($this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data.calendar.0.reacted'));

        // Uma data que não está no calendário não se pode usar.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/react'), ['calendar_key' => 'local.lisboa.inventada', 'occurrence' => '2027-06-12'] + $body)
            ->assertStatus(422)->assertJsonPath('errors.calendar_key.0', 'Esta data não está no calendário.');
    }

    public function test_lisbon_dates_and_the_company_holiday_does_not_repeat_a_local_date(): void
    {
        // Tudo confirmado a 23/10/2026; uma entrada sem data de confirmação continuaria por confirmar (fora das listas).
        $this->assertSame([], OfficialDates::pending());
        $this->assertSame(OfficialDates::PENDING, OfficialDates::status(['confirmed_on' => null]));
        $lisbon = OfficialDates::entries()['local.lisboa.santo_antonio'];
        $this->assertSame(['Câmara Municipal de Lisboa', '2026-10-23', OfficialDates::CONFIRMED], [$lisbon['source']['name'], $lisbon['confirmed_on'], OfficialDates::status($lisbon)]);

        $keys = fn (array $slugs, ?string $mun, ?array $holiday = null) => array_map(fn ($e) => "{$e['key']}@{$e['occurrence']}",
            OfficialDates::upcoming($slugs, $mun, CarbonImmutable::parse('2027-06-01'), 30, $holiday));
        // Restauração em Lisboa: a noite de 12 (marchas e arraiais, não é feriado) e o feriado municipal a 13.
        $restaurant = $keys(['universal', 'restauracao'], 'Lisboa');
        $this->assertContains('local.lisboa.noite_santo_antonio@2027-06-12', $restaurant);
        $this->assertContains('local.lisboa.santo_antonio@2027-06-13', $restaurant);
        // Outro ramo em Lisboa: só o feriado. Noutro concelho: nenhuma das duas.
        $stand = $keys(['universal', 'automovel', 'carros'], 'Lisboa');
        $this->assertContains('local.lisboa.santo_antonio@2027-06-13', $stand);
        $this->assertNotContains('local.lisboa.noite_santo_antonio@2027-06-12', $stand);
        $this->assertSame([], array_values(array_filter($keys(['universal', 'restauracao'], 'Porto'), fn ($k) => str_contains($k, 'lisboa'))));
        // O feriado municipal indicado pela empresa de Lisboa (13/06) não repete o Santo António.
        Settings::setCompany($this->company->id, ['calendar.municipal_holiday' => '13/06'], $this->admin);
        $this->assertNotContains(OfficialDates::COMPANY_HOLIDAY . '@2027-06-13', $keys(['universal', 'restauracao'], 'Lisboa', OfficialDates::companyHoliday($this->company->id)));

        Settings::setCompany($this->company->id, ['calendar.municipal_holiday' => '24/06'], $this->admin);
        $porto = $keys(['universal', 'restauracao'], 'Porto', OfficialDates::companyHoliday($this->company->id));
        $this->assertContains('local.porto.sao_joao@2027-06-23', $porto);
        $this->assertNotContains(OfficialDates::COMPANY_HOLIDAY . '@2027-06-24', $porto, 'o São João já é o feriado do Porto');
        $this->assertContains(OfficialDates::COMPANY_HOLIDAY . '@2027-06-24', $keys(['universal', 'restauracao'], 'Braga', OfficialDates::companyHoliday($this->company->id)));
    }

    public function test_permissions_module_and_tenancy(): void
    {
        $this->actingAs($this->userWith(['editorial.ver']), 'sanctum')->getJson($this->url())->assertStatus(403);
        $viewer = $this->userWith(['bussola.ver', 'editorial.ver']);
        $this->actingAs($viewer, 'sanctum')->getJson($this->url())->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/react'), ['calendar_key' => 'x', 'occurrence' => '2026-11-05'])->assertStatus(403);

        $other = $this->makeCompany('500019302', 'Outra Tasca');
        $otherAdmin = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $noModule = $this->makeCompany('500019303', 'Sem Linha', []);
        $noModuleAdmin = User::factory()->create(['company_id' => $noModule->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->actingAs($otherAdmin, 'sanctum')->getJson($this->url())->assertStatus(403);
        $this->actingAs($noModuleAdmin, 'sanctum')->getJson("/api/v1/companies/{$noModule->id}/marketing/calendar")->assertStatus(403);
    }
}
