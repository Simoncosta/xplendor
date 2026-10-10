<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Access\Permissions;
use App\Access\ProfileSuggestions;
use App\Mail\SiteChangeQuotedMail;
use App\Models\Company;
use App\Models\PermissionProfile;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Complemento ao pré-deploy, ponto 1: ver e aprovar a faturação da XPLENDOR (orçamentos e
 * cobranças) é só do perfil Administrador da empresa (e do root na própria empresa).
 */
class AdminOnlyBillingTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Company::create(['nipc' => '500600002', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'email' => 'admin@cliente.pt']);
        $this->user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user', 'email' => 'user@cliente.pt']);
        \App\Access\CompatibilityMigration::run();
    }

    private function url(string $suffix): string
    {
        return "/api/v1/companies/{$this->client->id}/{$suffix}";
    }

    public function test_no_profile_other_than_the_administrator_has_it(): void
    {
        $withBilling = DB::table('profile_permissions')->join('permission_profiles', 'permission_profiles.id', '=', 'profile_permissions.profile_id')
            ->whereIn('profile_permissions.area', Permissions::ADMIN_ONLY_AREAS)->distinct()->pluck('permission_profiles.system_key')->all();
        $this->assertSame([PermissionProfile::ADMIN], $withBilling);
        foreach (ProfileSuggestions::all() as [, , , , $permissions]) {
            $this->assertSame([], array_values(array_filter($permissions, fn ($p) => Permissions::isAdminOnly($p))));
        }
    }

    public function test_a_profile_that_includes_it_is_refused_and_the_list_shows_it_locked(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('permission-profiles'), [
            'name' => 'Contabilidade', 'side' => 'cliente', 'permissions' => ['financas.ver', 'faturacao_xplendor.ver'],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions');

        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('permission-profiles'))->assertOk();
        $this->assertSame(['faturacao_xplendor.ver', 'faturacao_xplendor.aprovar'], $res->json('data.admin_only'));
        $this->assertNotContains('faturacao_xplendor.ver', $res->json('data.allowed.cliente'));
        $finance = collect($res->json('data.profiles'))->firstWhere('name', 'Financeiro');
        $this->assertSame('Só o Administrador.', collect($finance['summary'])->firstWhere('area', 'faturacao_xplendor')['text']);
        $admin = collect($res->json('data.profiles'))->firstWhere('is_admin', true);
        $this->assertSame('Pode ver e aprovar.', collect($admin['summary'])->firstWhere('area', 'faturacao_xplendor')['text']);
    }

    public function test_the_user_opens_the_ticket_and_talks_but_only_the_administrator_sees_and_accepts_the_quote(): void
    {
        $ticket = SupportTicket::forceCreate(['company_id' => $this->client->id, 'user_id' => $this->user->id, 'type' => 'site_change', 'title' => 'Página nova',
            'description' => 'Texto', 'status' => 'open', 'quote_status' => 'awaiting_quote']);
        $ticket = app(SupportTicketService::class)->setQuote($ticket, 4);
        app(SupportTicketService::class)->addMessage($ticket, $this->admin->id, 'O orçamento é de 100,00 € (4 h).', true);
        Mail::assertQueued(SiteChangeQuotedMail::class, fn ($m) => $m->hasTo('admin@cliente.pt') && ! $m->hasTo('user@cliente.pt'));

        $show = $this->url("support-tickets/{$ticket->id}");
        $asUser = $this->actingAs($this->user, 'sanctum')->getJson($show)->assertOk();
        $this->assertSame(['quoted', false, null, null], [$asUser->json('data.quote_status'), $asUser->json('data.billing_visible'), $asUser->json('data.quoted_amount'), $asUser->json('data.estimated_hours')]);
        $this->assertStringNotContainsString('100,00', json_encode($asUser->json('data.messages')));
        $this->actingAs($this->user, 'sanctum')->postJson($show . '/messages', ['body' => 'Obrigado!'])->assertOk();
        $this->actingAs($this->user, 'sanctum')->patchJson($show . '/quote-decision', ['decision' => 'approve'])->assertForbidden()->assertJsonPath('reason', 'so_administrador');
        $this->actingAs($this->user, 'sanctum')->getJson($this->url('support-tickets/quotes'))->assertForbidden();

        $asAdmin = $this->actingAs($this->admin, 'sanctum')->getJson($show)->assertOk();
        $this->assertTrue($asAdmin->json('data.billing_visible'));
        $this->assertEquals(100, $asAdmin->json('data.quoted_amount'));
        $this->assertStringContainsString('100,00 €', json_encode($asAdmin->json('data.messages'), JSON_UNESCAPED_UNICODE));
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('support-tickets/quotes'))->assertOk();
        $this->actingAs($this->admin, 'sanctum')->patchJson($show . '/quote-decision', ['decision' => 'approve'])->assertOk();
    }

    public function test_root_sees_it_in_its_own_company_and_not_in_the_others(): void
    {
        $xplendor = Company::create(['nipc' => '500600003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $this->client->plan_id, 'subscription_status' => 'active']);
        $root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root']);
        $this->actingAs($root, 'sanctum')->getJson("/api/v1/companies/{$xplendor->id}/xplendor-charges")->assertOk();
        $this->actingAs($root, 'sanctum')->getJson($this->url('xplendor-charges'))->assertForbidden()->assertJsonPath('reason', 'so_administrador');
    }
}
