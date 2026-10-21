<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AgencyNoticeMail;
use App\Models\Alert;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Pedir proposta" (o lado da plataforma): o pedido do site vira lead na hora, do tipo
 * "Pedido de proposta", com a origem (QR do stand) e os consentimentos (data e texto); o campo
 * invisível; a validação; o limite de pedidos (também no car-lead); o aviso ao vendedor (sem
 * vendedor, aos administradores) sem dados pessoais; nada sobre quem enviou (sem IP); a lista.
 */
class ProposalRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $admin;
    private User $seller;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019311', 'fiscal_name' => 'Autocaravanas Exemplo Lda', 'trade_name' => 'Exemplo', 'plan_id' => $plan,
            'subscription_status' => 'active', 'email' => 'geral@exemplo.pt', 'public_api_token' => (string) Str::uuid()]);
        $this->other = Company::create(['nipc' => '500019312', 'fiscal_name' => 'Outro Stand Lda', 'plan_id' => $plan, 'subscription_status' => 'active',
            'public_api_token' => (string) Str::uuid()]);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'stock');
            app(CompanyModuleService::class)->enable($c->id, 'commercial_crm');
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'email' => 'admin@exemplo.pt']);
        $this->seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'email' => 'vendedor@exemplo.pt']);
        \App\Access\CompatibilityMigration::run();
        $this->car = Car::factory()->create(['company_id' => $this->company->id, 'vehicle_type' => 'motorhome', 'status' => 'active', 'seller_user_id' => $this->seller->id]);
    }

    private function payload(array $over = []): array
    {
        return array_replace([
            'car_id' => $this->car->id, 'name' => 'Cliente Fictício', 'phone' => '910 000 000', 'email' => 'Cliente@Exemplo.Invalid', 'message' => 'Gostava de uma proposta.',
            'wants_financing' => true, 'has_trade_in' => true, 'trade_in' => ['brand' => 'Peugeot', 'model' => '308', 'year' => 2015, 'km' => 120000],
            'consent_contact' => true, 'consent_contact_text' => 'Autorizo o contacto sobre este pedido.',
            'consent_marketing' => true, 'consent_marketing_text' => 'Quero receber novidades.',
            'policy_version' => '2026-10', 'event_id' => (string) Str::uuid(), 'website' => '',
            'origem' => 'qr', 'utm_source' => 'qr', 'utm_medium' => 'offline', 'utm_campaign' => 'ficha_stand', 'channel' => 'utm',
        ], $over);
    }

    private function send(array $payload, ?Company $company = null, string $ip = '10.0.0.1')
    {
        $token = ($company ?? $this->company)->public_api_token;

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson("/api/public/car-proposal?token={$token}", $payload);
    }

    public function test_the_request_becomes_a_proposal_lead_with_the_origin_and_the_consents(): void
    {
        $this->send($this->payload())->assertStatus(201)->assertJsonPath('success', true);

        $lead = CarLead::sole();
        $this->assertSame('proposta', $lead->type);
        $this->assertSame('Pedido de proposta', $lead->typeLabel());
        $this->assertSame($this->company->id, $lead->company_id);
        $this->assertSame($this->car->id, $lead->car_id);
        $this->assertSame('new', $lead->status);
        $this->assertSame('cliente@exemplo.invalid', $lead->email);
        $this->assertTrue($lead->wants_financing);
        $this->assertSame(['brand' => 'Peugeot', 'model' => '308', 'year' => 2015, 'km' => 120000], $lead->trade_in);
        $this->assertSame('qr', $lead->origin);
        $this->assertSame('QR do stand', $lead->originLabel());
        $this->assertNotNull($lead->consent_contact_at);
        $this->assertSame('Autorizo o contacto sobre este pedido.', $lead->consent_contact_text);
        $this->assertTrue($lead->consent_marketing);
        $this->assertNotNull($lead->consent_marketing_at);
        $this->assertSame('Quero receber novidades.', $lead->consent_marketing_text);
        $this->assertSame('2026-10', $lead->policy_version);
    }

    public function test_marketing_consent_is_optional_and_separate(): void
    {
        $this->send($this->payload(['consent_marketing' => false, 'consent_marketing_text' => 'Quero receber novidades.', 'has_trade_in' => false, 'trade_in' => null]))->assertStatus(201);
        $lead = CarLead::sole();
        $this->assertFalse($lead->consent_marketing);
        $this->assertNull($lead->consent_marketing_at);
        $this->assertNull($lead->consent_marketing_text, 'só se guarda o texto do que foi aceite');
        $this->assertNull($lead->trade_in);
        $this->assertNotNull($lead->consent_contact_at);
    }

    public function test_validation_contact_consent_phone_email_and_trade_in(): void
    {
        $this->send($this->payload(['consent_contact' => false]))->assertStatus(422)->assertJsonValidationErrors('consent_contact');
        $this->send($this->payload(['phone' => null]))->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->send($this->payload(['email' => 'nao-e-email']))->assertStatus(422)->assertJsonValidationErrors('email');
        $this->send($this->payload(['trade_in' => ['brand' => '', 'model' => '']]))->assertStatus(422)->assertJsonValidationErrors(['trade_in.brand', 'trade_in.model']);
        $this->assertSame(0, CarLead::count());
    }

    public function test_the_hidden_field_stores_nothing(): void
    {
        $this->send($this->payload(['website' => 'http://spam.invalid']))->assertStatus(201);
        $this->assertSame(0, CarLead::count());
        Mail::assertNothingQueued();
    }

    public function test_a_repeated_event_id_creates_one_lead(): void
    {
        $p = $this->payload();
        $this->send($p)->assertStatus(201);
        $this->send($p)->assertStatus(201);
        $this->assertSame(1, CarLead::count());
    }

    public function test_a_car_of_another_company_is_refused(): void
    {
        $car = Car::factory()->create(['company_id' => $this->other->id]);
        $this->send($this->payload(['car_id' => $car->id]))->assertStatus(422)->assertJsonValidationErrors('car_id');
        $this->send($this->payload(['car_id' => $this->car->id]), $this->other)->assertStatus(422);
        $this->assertSame(0, CarLead::count());
    }

    public function test_rate_limit_on_car_proposal_and_on_car_lead(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->send($this->payload(['event_id' => (string) Str::uuid()]))->assertStatus(201);
        }
        $this->send($this->payload(['event_id' => (string) Str::uuid()]))->assertStatus(429);
        $this->send($this->payload(['event_id' => (string) Str::uuid()]), null, '10.0.0.2')->assertStatus(201);

        $lead = ['name' => 'Cliente', 'email' => 'c@exemplo.invalid', 'car_id' => $this->car->id];
        $url = "/api/public/car-lead?token={$this->company->public_api_token}";
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->postJson($url, $lead)->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->postJson($url, $lead)->assertStatus(429);
    }

    public function test_nothing_about_who_sent_is_stored(): void
    {
        $this->send($this->payload(), null, '203.0.113.7')->assertStatus(201);
        $columns = Schema::getColumnListing('car_leads');
        $this->assertNotContains('ip_address', $columns);
        $this->assertNotContains('user_agent', $columns);
        $this->assertStringNotContainsString('203.0.113.7', json_encode(DB::table('car_leads')->first()));
    }

    public function test_the_seller_is_notified_without_personal_data_and_the_admins_without_seller(): void
    {
        $this->send($this->payload())->assertStatus(201);
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('vendedor@exemplo.pt'));
        Mail::assertNotQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('admin@exemplo.pt'));
        Mail::assertQueued(AgencyNoticeMail::class, function (AgencyNoticeMail $m) {
            $all = $m->subjectLine . ' ' . implode(' ', $m->lines);

            return ! str_contains($all, 'Cliente Fictício') && ! str_contains($all, '910 000 000') && ! str_contains($all, 'exemplo.invalid')
                && str_contains($all, 'QR do stand') && str_contains($all, "Ref. {$this->car->id}");
        });
        $alert = Alert::sole();
        $this->assertSame('Novo pedido de proposta', $alert->title);
        $this->assertStringNotContainsString('Cliente Fictício', $alert->message);
        $this->assertSame('/leads?id=' . CarLead::sole()->id, $alert->detail_path);

        $car = Car::factory()->create(['company_id' => $this->company->id, 'seller_user_id' => null]);
        $this->send($this->payload(['car_id' => $car->id, 'event_id' => (string) Str::uuid()]), null, '10.0.0.3')->assertStatus(201);
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('admin@exemplo.pt'));
    }

    public function test_the_lead_list_shows_the_type_and_the_origin(): void
    {
        $this->send($this->payload())->assertStatus(201);
        CarLead::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'name' => 'Outro', 'email' => 'o@exemplo.invalid', 'channel' => 'paid', 'utm_source' => 'facebook']);

        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/leads")->assertOk()->json('data'));
        $proposal = $rows->firstWhere('type', 'proposta');
        $this->assertSame('Pedido de proposta', $proposal['type_label']);
        $this->assertSame('QR do stand', $proposal['origin_label']);
        $this->assertTrue($proposal['wants_financing']);
        $this->assertSame('Autorizo o contacto sobre este pedido.', $proposal['consent_contact_text']);
        $contact = $rows->firstWhere('type', 'contacto');
        $this->assertSame('Contacto', $contact['type_label']);
        $this->assertSame('Anúncios Meta', $contact['origin_label']);

        $paged = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/leads?perPage=10")->assertOk();
        $this->assertContains('QR do stand', collect($paged->json('data.data'))->pluck('origin_label')->all());
    }
}
