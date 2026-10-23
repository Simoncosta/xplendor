<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Access\Access;
use App\Access\CompatibilityMigration;
use App\Access\CompatibilityProfiles;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\PermissionProfile;
use App\Models\Property;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Imóveis: a agência não apaga imóveis. Nem o perfil "Administrador da agência" tem o
 * imoveis.apagar, mesmo quando o cliente dá à agência um teto com o apagar; só o cliente apaga.
 */
class AgencyNeverDeletesPropertiesTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private Company $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Company::create(['nipc' => '500300101', 'fiscal_name' => 'Consultor Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = Company::create(['nipc' => '500300102', 'fiscal_name' => 'Agência Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency->forceFill(['agency_enabled_at' => now()])->save();
        app(CompanyModuleService::class)->enable($this->client->id, 'imoveis');
    }

    public function test_no_agency_profile_has_the_delete_on_properties(): void
    {
        foreach ([CompatibilityProfiles::AGENCY_ADMIN, CompatibilityProfiles::AGENCY_MEMBER, CompatibilityProfiles::CEILING] as $profile) {
            $allowed = CompatibilityProfiles::allowed($profile);
            $this->assertArrayNotHasKey('imoveis.apagar', $allowed, $profile);
            foreach (['ver', 'criar', 'editar'] as $action) {
                $this->assertArrayHasKey("imoveis.{$action}", $allowed, "{$profile}: imoveis.{$action}");
            }
        }
        $this->assertArrayHasKey('imoveis.apagar', CompatibilityProfiles::allowed(CompatibilityProfiles::CLIENT_ADMIN), 'o administrador do cliente apaga');

        CompatibilityMigration::ensureSystemProfiles();
        $this->assertNotContains('imoveis.apagar', PermissionProfile::system(PermissionProfile::AGENCY_ADMIN_COMPAT)->permissionKeys());
        $this->assertContains('imoveis.editar', PermissionProfile::system(PermissionProfile::AGENCY_ADMIN_COMPAT)->permissionKeys());
    }

    public function test_the_agency_admin_gets_403_on_delete_even_with_a_ceiling_that_allows_it_and_the_client_admin_deletes(): void
    {
        $agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        CompatibilityMigration::run();
        $ceiling = PermissionProfile::create(['company_id' => $this->client->id, 'side' => 'teto', 'name' => 'Teto generoso']);
        $ceiling->syncPermissions(['imoveis.ver', 'imoveis.criar', 'imoveis.editar', 'imoveis.apagar']);
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now(), 'guest_profile_id' => $ceiling->id]);

        $access = app(Access::class);
        $this->assertTrue($access->can($agencyAdmin->fresh(), $this->client->id, 'imoveis.editar')->allowed);
        $this->assertTrue($access->can($agencyAdmin->fresh(), $this->client->id, 'imoveis.apagar')->denied());

        $property = Property::create(['company_id' => $this->client->id, 'reference' => 'REF-1', 'title' => 'Imóvel 1', 'kind' => 'apartamento', 'typology' => 'T2',
            'area_m2' => 100, 'listing' => 'venda', 'price' => 400000, 'status' => 'disponivel', 'listed_at' => now()->toDateString()]);
        $url = "/api/v1/companies/{$this->client->id}/properties/{$property->id}";
        $this->actingAs($agencyAdmin->fresh(), 'sanctum')->deleteJson($url)->assertStatus(403);
        $this->assertNotNull(Property::find($property->id));

        $this->actingAs($clientAdmin->fresh(), 'sanctum')->deleteJson($url)->assertSuccessful();
    }
}
