<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Mail\SiteContactReceivedMail;
use App\Models\Alert;
use App\Models\InboundContact;
use App\Models\SiteDailyCount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsConsultantSite;
use Tests\TestCase;

/**
 * Site do consultor: a receção dos contactos (central de contactos, canal "site") e das contagens.
 * O consentimento é obrigatório; a partilha com o parceiro de crédito só no pedido de crédito; o
 * honeypot não grava; o aviso ao consultor nunca leva os dados pessoais; as contagens não têm IP.
 */
class InboundContactTest extends TestCase
{
    use BuildsConsultantSite;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->buildConsultantSite();
    }

    private function body(array $extra = []): array
    {
        return array_replace_recursive([
            'type' => 'estudo_zona', 'name' => 'Pessoa Fictícia', 'email' => 'pessoa@exemplo.invalid', 'phone' => '+351 900 000 001',
            'consent_privacy' => true, 'policy_version' => '2026-10',
            'details' => ['parish_id' => $this->parish, 'property_kind' => 'apartamento'],
            'origin' => ['utm_source' => 'meta', 'utm_campaign' => 'vender-porto', 'fbclid' => 'fb.exemplo', 'landing_path' => '/lp/estudo-da-zona', 'referrer_host' => 'facebook.com'],
        ], $extra);
    }

    public function test_a_contact_is_saved_with_origin_consents_and_retention(): void
    {
        $eventId = (string) Str::uuid();
        $r = $this->siteApi('POST', 'contacts', $this->body(['event_id' => $eventId, 'consent_marketing' => true]))->assertCreated();
        $this->assertSame($eventId, $r->json('data.event_id'));
        $c = InboundContact::sole();
        $this->assertSame('site', $c->channel);
        $this->assertSame($eventId, $c->source_ref);
        $this->assertSame($this->site->id, $c->consultant_site_id);
        $this->assertSame(['parish_id' => $this->parish, 'property_kind' => 'apartamento'], $c->details);
        $this->assertNotNull($c->consent_privacy_at);
        $this->assertTrue($c->consent_marketing);
        $this->assertNotNull($c->consent_marketing_at);
        $this->assertFalse($c->consent_credit_share);
        $this->assertSame('2026-10', $c->policy_version);
        $this->assertSame(['meta', 'vender-porto', 'fb.exemplo', '/lp/estudo-da-zona', 'facebook.com'], [$c->utm_source, $c->utm_campaign, $c->fbclid, $c->landing_path, $c->referrer_host]);
        $this->assertSame(now()->addMonths(24)->toDateString(), $c->retain_until->toDateString());
        $this->assertSame('novo', $c->status);
    }

    public function test_privacy_consent_is_required(): void
    {
        $this->siteApi('POST', 'contacts', $this->body(['consent_privacy' => false]))->assertStatus(422)->assertJsonValidationErrors('consent_privacy');
        $b = $this->body();
        unset($b['consent_privacy']);
        $this->siteApi('POST', 'contacts', $b)->assertStatus(422)->assertJsonValidationErrors('consent_privacy');
        $this->assertSame(0, InboundContact::count());
    }

    public function test_credit_share_consent_only_exists_in_the_credit_request(): void
    {
        $credit = $this->body(['type' => 'credito', 'details' => ['property_value' => 300000]]);
        unset($credit['details']['parish_id'], $credit['details']['property_kind']);
        $this->siteApi('POST', 'contacts', $credit)->assertStatus(422)->assertJsonValidationErrors('consent_credit_share');
        $this->siteApi('POST', 'contacts', $credit + ['consent_credit_share' => true])->assertCreated();
        $this->assertTrue(InboundContact::sole()->consent_credit_share);
        // Noutro tipo, mesmo que venha, não fica.
        $this->siteApi('POST', 'contacts', $this->body(['consent_credit_share' => true]))->assertCreated();
        $this->assertFalse(InboundContact::where('type', 'estudo_zona')->sole()->consent_credit_share);
    }

    public function test_email_or_phone_is_required_and_only_the_fields_of_the_type_are_kept(): void
    {
        $b = $this->body();
        unset($b['email'], $b['phone']);
        $this->siteApi('POST', 'contacts', $b)->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);
        $this->siteApi('POST', 'contacts', $this->body(['type' => 'castelo']))->assertStatus(422);
        $this->siteApi('POST', 'contacts', $this->body(['details' => ['owner_nif' => '123', 'notes' => 'x']]))->assertCreated();
        $this->assertSame(['parish_id' => $this->parish, 'property_kind' => 'apartamento'], InboundContact::sole()->details);
    }

    public function test_the_honeypot_answers_success_and_saves_nothing(): void
    {
        $this->siteApi('POST', 'contacts', $this->body(['website' => 'http://spam.invalid']))->assertCreated();
        $this->assertSame(0, InboundContact::count());
        Mail::assertNothingQueued();
    }

    public function test_a_visit_request_needs_a_published_property(): void
    {
        $p = $this->publishedProperty();
        $draft = $this->makeProperty();
        $visit = fn (?string $slug) => $this->body(['type' => 'visita', 'property_slug' => $slug, 'details' => ['preferred_time' => 'sábado de manhã']]);
        $this->siteApi('POST', 'contacts', $visit($draft->slug))->assertStatus(422);
        $this->siteApi('POST', 'contacts', $visit($p->slug))->assertCreated();
        $c = InboundContact::sole();
        $this->assertSame(['property', $p->id], [$c->subject_type, $c->subject_id]);
        $this->assertSame(['preferred_time' => 'sábado de manhã'], $c->details);
    }

    public function test_the_consultant_is_warned_at_once_without_the_visitor_personal_data(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'email' => 'utilizador@consultor-exemplo.invalid']);
        \App\Access\CompatibilityMigration::run();
        $p = $this->publishedProperty(['reference' => 'REF-AVISO']);
        $this->siteApi('POST', 'contacts', $this->body(['type' => 'visita', 'property_slug' => $p->slug, 'details' => []]))->assertCreated();

        $alert = Alert::where('company_id', $this->company->id)->sole();
        $this->assertSame('Novo contacto do site', $alert->title);
        $this->assertSame('Pedido de visita: imóvel REF-AVISO', $alert->message);
        $sent = [];
        Mail::assertQueued(SiteContactReceivedMail::class, function (SiteContactReceivedMail $m) use (&$sent) {
            $sent[] = $m->to[0]['address'];
            $html = $m->render();
            foreach (['Pessoa Fictícia', 'pessoa@exemplo.invalid', '900 000 001'] as $pii) {
                $this->assertStringNotContainsString($pii, $html . $m->envelope()->subject);
            }

            return true;
        });
        sort($sent);
        $this->assertSame(['admin@consultor-exemplo.invalid', 'utilizador@consultor-exemplo.invalid'], $sent, 'quem pode ver os contactos (o utilizador "como hoje" vê)');
        foreach (['Pessoa Fictícia', 'pessoa@exemplo.invalid'] as $pii) {
            $this->assertStringNotContainsString($pii, $alert->message . $alert->title);
        }
    }

    public function test_events_are_daily_counts_without_ip_cookies_or_visitor_id(): void
    {
        $p = $this->publishedProperty();
        $draft = $this->makeProperty();
        $this->siteApi('POST', 'events', ['events' => [
            ['event' => 'view', 'subject' => 'property', 'slug' => $p->slug],
            ['event' => 'view', 'subject' => 'property', 'slug' => $p->slug, 'count' => 2],
            ['event' => 'whatsapp_click', 'subject' => 'property', 'slug' => $p->slug],
            ['event' => 'view', 'subject' => 'property', 'slug' => $draft->slug],
            ['event' => 'view', 'subject' => 'site'],
        ]])->assertStatus(202)->assertJsonPath('data.counted', 5);
        $this->assertSame(3, SiteDailyCount::where('subject_id', $p->id)->where('event', 'view')->value('count'));
        $this->assertSame(0, SiteDailyCount::where('subject_type', 'property')->where('subject_id', $draft->id)->count());
        $this->assertSame([], array_values(array_intersect(Schema::getColumnListing('site_daily_counts'), ['ip', 'ip_address', 'user_agent', 'visitor_id', 'session_id', 'cookie'])));
        $this->assertSame([], array_values(array_intersect(Schema::getColumnListing('inbound_contacts'), ['ip', 'ip_address', 'user_agent', 'visitor_id', 'session_id'])));
        $this->siteApi('POST', 'events', ['events' => [['event' => 'scroll', 'subject' => 'site']]])->assertStatus(422);
    }

    public function test_the_site_part_of_the_interest_counts_adds_to_the_manual_part(): void
    {
        // O que já estava contado à mão (o portal da rede, o telefone, as indicações) fica e soma-se.
        $p = $this->publishedProperty(['contacts_30' => 2, 'visits_30' => 1, 'contacts_30_manual' => 2, 'visits_30_manual' => 1]);
        $other = $this->publishedProperty();
        $this->siteApi('POST', 'contacts', $this->body(['type' => 'visita', 'property_slug' => $p->slug, 'details' => []]))->assertCreated();
        $this->siteApi('POST', 'contacts', $this->body(['type' => 'contacto', 'property_slug' => $p->slug, 'details' => []]))->assertCreated();
        $this->siteApi('POST', 'events', ['events' => [['event' => 'whatsapp_click', 'subject' => 'property', 'slug' => $p->slug, 'count' => 2]]])->assertStatus(202);
        // Um contacto antigo (fora dos 30 dias) não conta.
        InboundContact::create(['company_id' => $this->company->id, 'channel' => 'site', 'type' => 'contacto', 'subject_type' => 'property', 'subject_id' => $p->id,
            'name' => 'Antigo', 'created_at' => now()->subDays(45)]);

        $p->refresh();
        $this->assertSame([4, 1, 2, 1, 6, 2], [$p->contacts_30_site, $p->visits_30_site, $p->contacts_30_manual, $p->visits_30_manual, $p->contacts_30, $p->visits_30]);
        $this->assertSame(now('Europe/Lisbon')->toDateString(), $p->interest_updated_at->toDateString());
        $this->assertSame(0, $other->fresh()->contacts_30);

        // A ficha da carteira grava a parte manual; o total volta a somar a do site.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/companies/{$this->company->id}/properties/{$p->id}", [
            'reference' => $p->reference, 'title' => $p->title, 'kind' => 'apartamento', 'listing' => 'venda', 'price' => 250000, 'status' => 'disponivel',
            'listed_at' => $p->listed_at->toDateString(), 'contacts_30_manual' => 5, 'visits_30_manual' => 3,
        ])->assertOk()->assertJsonPath('data.contacts_30', 9)->assertJsonPath('data.contacts_30_site', 4)->assertJsonPath('data.contacts_30_manual', 5)
            ->assertJsonPath('data.visits_30', 4);
        // O nome antigo (contacts_30) continua a valer como a parte manual.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/companies/{$this->company->id}/properties/{$p->id}", [
            'reference' => $p->reference, 'title' => $p->title, 'kind' => 'apartamento', 'listing' => 'venda', 'price' => 250000, 'status' => 'disponivel',
            'listed_at' => $p->listed_at->toDateString(), 'contacts_30' => 1, 'visits_30' => 0,
        ])->assertOk()->assertJsonPath('data.contacts_30', 5)->assertJsonPath('data.contacts_30_manual', 1);

        // De noite, a janela dos 30 dias anda: sai a parte do site, fica a manual.
        $this->travel(31)->days();
        $this->artisan('consultor:interest-sync')->assertSuccessful();
        $p->refresh();
        $this->assertSame([0, 0, 1, 0, 1, 0], [$p->contacts_30_site, $p->visits_30_site, $p->contacts_30_manual, $p->visits_30_manual, $p->contacts_30, $p->visits_30]);
    }

    public function test_the_compass_uses_the_total_and_says_where_the_counts_come_from(): void
    {
        $p = $this->publishedProperty(['contacts_30' => 5, 'visits_30' => 1, 'contacts_30_manual' => 2, 'visits_30_manual' => 1, 'contacts_30_site' => 3,
            'interest_updated_at' => now()->toDateString(), 'title' => 'T2 exemplo do interesse']);
        $facts = collect(\App\Services\Compass\Imoveis\ImoveisFacts::forCompany($this->company->id, \Carbon\CarbonImmutable::now('Europe/Lisbon')->startOfDay()))
            ->firstWhere('property_id', $p->id);
        $this->assertSame([5, 3, 2], [$facts['contacts_30'], $facts['contacts_30_site'], $facts['contacts_30_manual']]);
        $body = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/marketing/bussola-imoveis")->assertOk()->getContent();
        $this->assertStringContainsString('3 do site, 2 de outros canais', $body);
        $this->assertStringContainsString('Contactos e visitas (do site e de outros canais)', $body);
    }

    public function test_a_company_without_the_site_keeps_manual_counts(): void
    {
        $plain = $this->makeConsultantCompany('Sem Site', ['imoveis']);
        $p = $this->makeProperty(['contacts_30' => 5, 'contacts_30_manual' => 5], $plain);
        app(\App\Services\ConsultantSite\PropertyInterestSync::class)->refreshProperties($plain->id);
        $this->assertSame([5, 0], [$p->fresh()->contacts_30, $p->fresh()->contacts_30_site]);
    }

    public function test_the_contact_inbox_is_per_company(): void
    {
        $this->siteApi('POST', 'contacts', $this->body())->assertCreated();
        $other = $this->makeConsultantCompany('Outra');
        $otherAdmin = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $id = InboundContact::sole()->id;
        $this->actingAs($otherAdmin, 'sanctum')->getJson("/api/v1/companies/{$other->id}/inbound-contacts")->assertOk()->assertJsonCount(0, 'data.contacts');
        $this->actingAs($otherAdmin, 'sanctum')->getJson("/api/v1/companies/{$other->id}/inbound-contacts/{$id}")->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/inbound-contacts")->assertOk()
            ->assertJsonPath('data.contacts.0.name', 'Pessoa Fictícia')->assertJsonPath('data.contacts.0.channel', 'site');
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/companies/{$this->company->id}/inbound-contacts/{$id}", ['status' => 'tratado'])->assertOk()
            ->assertJsonPath('data.status', 'tratado');
        $this->assertNotNull(InboundContact::find($id)->handled_at);
    }
}
