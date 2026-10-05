<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\QuoteCreatedForCompanyMail;
use App\Mail\QuoteDecisionMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ImpersonationSession;
use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Models\User;
use App\Services\QuoteService;
use App\Services\Quotes\QuotePdfPresenter;
use App\Services\Quotes\QuoteSnapshot;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Orçamentos de serviços da XPLENDOR: cliente novo e existente, totais separados,
 * descontos, envio (número sem buracos, validade, versão congelada com o PDF),
 * versões, expiração aos 30 dias, rascunho não expira, emails só no envio, painel
 * da empresa ligada, dashboard root, catálogo, migração dos estados antigos e acesso.
 */
class QuoteModuleTest extends TestCase
{
    use RefreshDatabase;

    private Company $team;
    private Company $client;
    private User $root;
    private User $clientAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->travelToLisbon('2026-10-05 10:00:00');

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->team = Company::create(['nipc' => '500016001', 'fiscal_name' => 'Xplendor', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->client = Company::create(['nipc' => '500016002', 'fiscal_name' => 'Quebom', 'email' => 'geral@quebom.pt', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->team->id, 'role' => 'root']);
        $this->clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function travelToLisbon(string $lisbonTime): void
    {
        $utc = CarbonImmutable::parse($lisbonTime, 'Europe/Lisbon')->utc();
        Carbon::setTestNow(Carbon::instance($utc->toDateTime()));
        CarbonImmutable::setTestNow($utc);
    }

    private function api(): self
    {
        return $this->actingAs($this->root, 'sanctum');
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'new_customer' => ['name' => 'Pastelaria Doce Ribeira', 'phone' => '+351 912 345 678', 'email' => 'ana@exemplo.pt'],
            'title' => 'Presença digital',
            'lines' => [
                ['name' => 'Social Media', 'unit' => 'month', 'billing_type' => 'monthly', 'quantity' => 1, 'unit_price' => 200],
                ['name' => 'Tráfego Pago', 'unit' => 'month', 'billing_type' => 'monthly', 'quantity' => 1, 'unit_price' => 200],
                ['name' => 'Website', 'unit' => 'hour', 'billing_type' => 'one_off', 'quantity' => 12, 'unit_price' => 25, 'discount_type' => 'percent', 'discount_value' => 10],
            ],
            'global_discount_type' => 'amount', 'global_discount_value' => 100, 'global_discount_target' => 'monthly',
            'global_discount_label' => 'Desconto de pacote',
        ], $extra);
    }

    private function createDraft(array $extra = []): array
    {
        return $this->api()->postJson('/api/v1/admin/quotes', $this->payload($extra))->assertStatus(201)->json('data');
    }

    private function send(int $id)
    {
        return $this->api()->postJson("/api/v1/admin/quotes/{$id}/send");
    }

    // ── Cliente novo e existente ─────────────────────────────────────────────

    public function test_new_customer_is_saved_in_the_team_clients_module(): void
    {
        $q = $this->createDraft();

        $customer = Customer::sole();
        $this->assertSame([$this->team->id, 'Pastelaria Doce Ribeira', '+351 912 345 678', 'ana@exemplo.pt'], [$customer->company_id, $customer->name, $customer->phone, $customer->email]);
        $this->assertSame([$customer->id, 'Pastelaria Doce Ribeira', 'ana@exemplo.pt'], [$q['customer_id'], $q['client_name'], $q['client_email']]);
        $this->assertNull($q['number']);                        // número só no envio
        $this->assertSame('Rascunho', $q['display_number']);
        $this->assertSame(3, $q['minimum_contract_months']);    // condições por omissão
        $this->assertSame('Os serviços mensais têm início no mês seguinte à aceitação.', $q['monthly_start_terms']);
        $this->assertStringStartsWith('Pagamento antecipado', $q['payment_terms_monthly']);
        $this->assertSame('50% na adjudicação e 50% na entrega.', $q['payment_terms_one_off']);
        $this->assertNull($q['intro']);                         // introdução vazia por omissão
    }

    public function test_empty_conditions_on_create_fall_back_to_the_defaults(): void
    {
        $q = $this->createDraft(['payment_terms_monthly' => '', 'payment_terms_one_off' => null, 'monthly_start_terms' => '', 'minimum_contract_months' => null]);

        $this->assertSame(3, $q['minimum_contract_months']);
        $this->assertSame(config('quotes.defaults.payment_terms_monthly'), $q['payment_terms_monthly']);
        $this->assertSame(config('quotes.defaults.payment_terms_one_off'), $q['payment_terms_one_off']);
        $this->assertSame(config('quotes.defaults.monthly_start_terms'), $q['monthly_start_terms']);
        $this->api()->getJson('/api/v1/admin/quotes/defaults')->assertOk()
            ->assertJsonPath('data.minimum_contract_months', 3)->assertJsonPath('data.validity_days', 30);

        // Depois de criado, a forma de pagamento pode ser apagada de propósito.
        $this->api()->putJson("/api/v1/admin/quotes/{$q['id']}", ['payment_terms_monthly' => ''])->assertOk()->assertJsonPath('data.payment_terms_monthly', null);
    }

    public function test_existing_customer_is_used_and_another_companys_customer_is_refused(): void
    {
        $mine = Customer::create(['company_id' => $this->team->id, 'name' => 'Spacedrive', 'email' => 's@spacedrive.pt']);
        $other = Customer::create(['company_id' => $this->client->id, 'name' => 'Cliente do Quebom']);

        $q = $this->createDraft(['new_customer' => null, 'customer_id' => $mine->id]);
        $this->assertSame([$mine->id, 'Spacedrive'], [$q['customer_id'], $q['client_name']]);
        $this->assertSame(1, Customer::where('company_id', $this->team->id)->count());

        $this->api()->postJson('/api/v1/admin/quotes', $this->payload(['new_customer' => null, 'customer_id' => $other->id]))
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_team_company_comes_from_config_when_set(): void
    {
        $configured = Company::create(['nipc' => '500016003', 'fiscal_name' => 'Simon Costa, Unipessoal, Lda', 'plan_id' => $this->team->plan_id, 'subscription_status' => 'active']);
        config(['quotes.team_company_id' => $configured->id]);

        $this->createDraft();

        $this->assertSame($configured->id, Customer::sole()->company_id);
        $this->api()->postJson('/api/v1/admin/quotes/customers', ['name' => 'Novo', 'phone' => '910000000'])->assertStatus(201);
        $this->assertSame(2, Customer::where('company_id', $configured->id)->count());
        $this->assertCount(2, $this->api()->getJson('/api/v1/admin/quotes/customers')->json('data'));
    }

    // ── Totais e descontos ───────────────────────────────────────────────────

    public function test_totals_are_separate_with_line_and_package_discounts(): void
    {
        $q = $this->createDraft();

        $this->assertSame(300.0, (float) $q['total_monthly']);   // 400 − 100 de pacote
        $this->assertSame(270.0, (float) $q['total_one_off']);   // 300 − 10%
        $this->assertSame(100.0, (float) $q['buckets']['monthly']['discount']);
        $this->assertSame(270.0, (float) $q['lines'][2]['line_total']);
        $this->assertArrayNotHasKey('total', $q);                // nunca um total combinado
    }

    public function test_euro_package_discount_needs_its_total(): void
    {
        $this->api()->postJson('/api/v1/admin/quotes', $this->payload(['global_discount_target' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('global_discount_target');
        $this->api()->postJson('/api/v1/admin/quotes', $this->payload(['global_discount_value' => 500]))
            ->assertStatus(422)->assertJsonValidationErrors('global_discount_value');
        $this->assertSame(0, Quote::count());
    }

    public function test_pdf_shows_package_discount_as_its_own_line_and_vat_note_without_vat_value(): void
    {
        $q = Quote::find($this->createDraft()['id']);
        $doc = app(QuotePdfPresenter::class)->present(QuoteSnapshot::fromQuote($q));

        $monthly = $doc['sections'][0];
        $this->assertSame('Desconto de pacote', $monthly['package_discount']['label']);
        $this->assertSame('−100,00 €/mês', $monthly['package_discount']['value']);
        $this->assertSame('300,00 €/mês', $monthly['total']);
        $this->assertSame([['label' => 'Total mensal', 'value' => '300,00 €', 'unit' => '/mês'], ['label' => 'Total valor único', 'value' => '270,00 €', 'unit' => '']], $doc['totals']);
        $this->assertSame('Acresce IVA à taxa legal em vigor.', $doc['vat_note']);
        $this->assertStringContainsString('3 meses para os serviços mensais.', collect($doc['conditions'])->firstWhere('key', 'Contrato mínimo')['value']);
        $this->assertStringContainsString('não está incluído', collect($doc['conditions'])->firstWhere('key', 'Anúncios')['value']);
        $this->assertSame('XPLENDOR é uma marca de Simon Costa, Unipessoal, Lda · NIF 517343355', $doc['legal']['brand_line']);
        $this->assertStringNotContainsString('—', json_encode($doc, JSON_UNESCAPED_UNICODE));
    }

    // ── Título, introdução e condições conforme as linhas ────────────────────

    /** Documento do PDF (presenter) e o HTML da vista, para um orçamento com estas linhas. */
    private function documentFor(array $lines, array $extra = []): array
    {
        $q = Quote::find($this->createDraft(array_merge(['lines' => $lines, 'title' => null, 'global_discount_type' => null, 'global_discount_value' => null], $extra))['id']);
        $doc = app(QuotePdfPresenter::class)->present(QuoteSnapshot::fromQuote($q));

        return [$doc, view('pdf.quote', ['doc' => $doc])->render()];
    }

    private const MONTHLY_LINE = ['name' => 'Social Media', 'unit' => 'month', 'billing_type' => 'monthly', 'quantity' => 1, 'unit_price' => 200];
    private const ONE_OFF_LINE = ['name' => 'Website', 'unit' => 'hour', 'billing_type' => 'one_off', 'quantity' => 10, 'unit_price' => 25];

    public function test_only_monthly_shows_the_monthly_conditions_and_not_the_one_off_one(): void
    {
        [$doc, $html] = $this->documentFor([self::MONTHLY_LINE]);

        $this->assertSame(['IVA', 'Anúncios', 'Início dos serviços mensais', 'Contrato mínimo', 'Pagamento dos serviços mensais', 'Validade'], array_column($doc['conditions'], 'key'));
        $this->assertSame('3 meses', $doc['minimum_contract']);
        $this->assertSame(['Serviços mensais'], array_column($doc['sections'], 'title'));
        $this->assertSame(['Total mensal'], array_column($doc['totals'], 'label'));
        $this->assertStringContainsString('Os serviços mensais têm início no mês seguinte à aceitação.', $html);
        $this->assertStringNotContainsString('50% na adjudicação', $html);
        $this->assertStringNotContainsString('Pagamento do valor único', $html);
        $this->assertStringNotContainsString('Total valor único', $html);
    }

    public function test_only_one_off_hides_every_monthly_condition(): void
    {
        [$doc, $html] = $this->documentFor([self::ONE_OFF_LINE]);

        $this->assertSame(['IVA', 'Anúncios', 'Pagamento do valor único', 'Validade'], array_column($doc['conditions'], 'key'));
        $this->assertNull($doc['minimum_contract']);
        $this->assertSame(['Total valor único'], array_column($doc['totals'], 'label'));
        $this->assertStringContainsString('50% na adjudicação e 50% na entrega.', $html);
        foreach (['têm início no mês seguinte', 'Contrato mínimo', 'Pagamento dos serviços mensais', 'Serviços mensais', 'Total mensal', '/mês'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function test_mixed_shows_both_sets_of_conditions(): void
    {
        [$doc, $html] = $this->documentFor([self::MONTHLY_LINE, self::ONE_OFF_LINE]);

        $this->assertSame(
            ['IVA', 'Anúncios', 'Início dos serviços mensais', 'Contrato mínimo', 'Pagamento dos serviços mensais', 'Pagamento do valor único', 'Validade'],
            array_column($doc['conditions'], 'key')
        );
        $this->assertSame(['Total mensal', 'Total valor único'], array_column($doc['totals'], 'label'));
        $this->assertStringContainsString('Os serviços mensais têm início no mês seguinte à aceitação.', $html);
        $this->assertStringContainsString('50% na adjudicação e 50% na entrega.', $html);
    }

    public function test_title_and_intro_are_optional_and_absent_from_the_pdf_when_empty(): void
    {
        [$doc, $html] = $this->documentFor([self::MONTHLY_LINE], ['title' => '   ', 'intro' => '']);
        $this->assertNull($doc['title_text']);
        $this->assertNull($doc['intro']);
        $this->assertStringNotContainsString('class="intro"', $html);

        [$doc2, $html2] = $this->documentFor([self::MONTHLY_LINE], ['title' => 'Presença digital', 'intro' => 'Proposta para o próximo trimestre.']);
        $this->assertStringContainsString('Presença digital', $html2);
        $this->assertStringContainsString('Proposta para o próximo trimestre.', $html2);
    }

    public function test_an_emptied_condition_is_not_shown(): void
    {
        $q = $this->createDraft(['lines' => [self::MONTHLY_LINE]]);
        $this->api()->putJson("/api/v1/admin/quotes/{$q['id']}", ['monthly_start_terms' => '', 'minimum_contract_months' => null])->assertOk();

        $doc = app(QuotePdfPresenter::class)->present(QuoteSnapshot::fromQuote(Quote::find($q['id'])));
        $this->assertSame(['IVA', 'Anúncios', 'Pagamento dos serviços mensais', 'Validade'], array_column($doc['conditions'], 'key'));
        $this->assertNull($doc['minimum_contract']);
    }

    // ── Envio: número, validade, versão congelada com o PDF ──────────────────

    public function test_send_assigns_number_validity_and_freezes_the_version_with_its_pdf(): void
    {
        $q = $this->createDraft();

        $sent = $this->send($q['id'])->assertOk()->json('data');

        $this->assertSame(['ORC-2026-001', 'sent', '2026-11-04'], [$sent['number'], $sent['status'], $sent['valid_until']]);
        $version = QuoteVersion::sole();
        $this->assertSame([1, 'ORC-2026-001'], [$version->version, $version->number]);
        $this->assertEquals(300.0, $version->snapshot['buckets']['monthly']['total']);
        Storage::disk('local')->assertExists($version->pdf_path);
        $bytes = Storage::disk('local')->get($version->pdf_path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(hash('sha256', $bytes), $version->pdf_sha256);

        $download = $this->api()->get("/api/v1/admin/quotes/{$q['id']}/versions/1/pdf")->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertSame($bytes, $download->getContent());
    }

    public function test_validity_uses_the_lisbon_day(): void
    {
        $this->travelToLisbon('2026-10-05 23:30:00');   // 22:30 UTC
        $q = $this->createDraft();

        $this->assertSame('2026-11-04', $this->send($q['id'])->json('data.valid_until'));
    }

    public function test_numbers_have_no_gaps_from_deleted_drafts_and_restart_each_year(): void
    {
        $a = $this->createDraft();
        $deleted = $this->createDraft();
        $b = $this->createDraft();
        $this->api()->deleteJson("/api/v1/admin/quotes/{$deleted['id']}")->assertOk();

        $this->assertSame('ORC-2026-001', $this->send($a['id'])->json('data.number'));
        $this->assertSame('ORC-2026-002', $this->send($b['id'])->json('data.number'));

        $this->travelToLisbon('2027-01-02 09:00:00');
        $c = $this->createDraft();
        $this->assertSame('ORC-2027-001', $this->send($c['id'])->json('data.number'));
    }

    public function test_sent_quotes_cannot_be_deleted(): void
    {
        $q = $this->createDraft();
        $this->send($q['id']);

        $this->api()->deleteJson("/api/v1/admin/quotes/{$q['id']}")->assertStatus(422);
        $this->assertSame(1, Quote::count());
    }

    // ── Versões ──────────────────────────────────────────────────────────────

    public function test_editing_a_sent_quote_creates_a_new_version_and_keeps_the_previous_one_frozen(): void
    {
        $q = $this->createDraft();
        $this->send($q['id']);
        $v1 = QuoteVersion::sole();
        $v1Bytes = Storage::disk('local')->get($v1->pdf_path);

        $edited = $this->api()->putJson("/api/v1/admin/quotes/{$q['id']}", ['global_discount_value' => 50])->assertOk()->json('data');
        $this->assertSame(['draft', 2, 'ORC-2026-001', 350.0], [$edited['status'], $edited['version'], $edited['number'], (float) $edited['total_monthly']]);
        $this->assertNull($edited['valid_until']);

        $this->travelToLisbon('2026-10-10 10:00:00');
        $sent2 = $this->send($q['id'])->assertOk()->json('data');
        $this->assertSame(['ORC-2026-001', 2, '2026-11-09'], [$sent2['number'], $sent2['version'], $sent2['valid_until']]);

        $v1->refresh();
        $this->assertEquals(300.0, $v1->snapshot['buckets']['monthly']['total']);             // a versão 1 não mudou
        $this->assertSame($v1Bytes, Storage::disk('local')->get($v1->pdf_path));
        $v2 = QuoteVersion::where('version', 2)->sole();
        $this->assertEquals(350.0, $v2->snapshot['buckets']['monthly']['total']);
        $this->assertNotSame($v1->pdf_path, $v2->pdf_path);
        $this->assertCount(2, $sent2['versions']);
        $this->assertSame(1, DB::table('quote_number_sequences')->value('last_number'));   // a versão nova não gasta número
    }

    public function test_accepted_cannot_be_edited_but_can_be_duplicated(): void
    {
        $q = $this->createDraft();
        $this->send($q['id']);
        $this->api()->patchJson("/api/v1/admin/quotes/{$q['id']}/decision", ['decision' => 'accept'])->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->api()->putJson("/api/v1/admin/quotes/{$q['id']}", ['title' => 'x'])->assertStatus(422);

        $copy = $this->api()->postJson("/api/v1/admin/quotes/{$q['id']}/duplicate")->assertStatus(201)->json('data');
        $this->assertSame(['draft', null, 1, 300.0], [$copy['status'], $copy['number'], $copy['version'], (float) $copy['total_monthly']]);
        $this->assertCount(3, $copy['lines']);
    }

    public function test_refused_and_expired_reopen_as_a_new_version(): void
    {
        $refused = $this->createDraft();
        $this->send($refused['id']);
        $this->api()->patchJson("/api/v1/admin/quotes/{$refused['id']}/decision", ['decision' => 'refuse'])->assertJsonPath('data.status', 'refused');
        $this->api()->putJson("/api/v1/admin/quotes/{$refused['id']}", ['title' => 'Nova proposta'])->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 2);

        $expired = $this->createDraft();
        $this->send($expired['id']);
        Quote::whereKey($expired['id'])->update(['status' => 'expired']);
        $this->api()->putJson("/api/v1/admin/quotes/{$expired['id']}", ['title' => 'Revista'])->assertOk()->assertJsonPath('data.version', 2);
    }

    // ── Expiração ────────────────────────────────────────────────────────────

    public function test_sent_quote_expires_after_30_days_and_drafts_never_expire(): void
    {
        $sent = $this->createDraft();
        $this->send($sent['id']);                    // válido até 4 de novembro
        $draft = $this->createDraft();
        $service = app(QuoteService::class);

        $this->travelToLisbon('2026-11-04 23:59:00');   // ainda no último dia
        $this->assertSame(0, $service->expireDue());
        $this->assertSame('sent', Quote::find($sent['id'])->status);

        $this->travelToLisbon('2026-11-05 00:15:00');   // o job diário do dia seguinte
        (new \App\Jobs\ExpireQuotesJob())->handle($service);
        $this->assertSame('expired', Quote::find($sent['id'])->status);
        $this->assertNotNull(Quote::find($sent['id'])->expired_at);

        $this->travelToLisbon('2027-06-01 00:15:00');
        $service->expireDue();
        $this->assertSame('draft', Quote::find($draft['id'])->status);
    }

    // ── Emails só no envio; painel da empresa ligada ─────────────────────────

    public function test_linked_company_is_emailed_only_when_the_quote_is_sent(): void
    {
        $q = $this->createDraft(['company_id' => $this->client->id]);
        $this->api()->putJson("/api/v1/admin/quotes/{$q['id']}", ['title' => 'Alterado'])->assertOk();
        Mail::assertNothingQueued();

        $this->send($q['id'])->assertOk();

        Mail::assertQueued(QuoteCreatedForCompanyMail::class, 1);
        Mail::assertQueued(QuoteCreatedForCompanyMail::class, fn ($m) => $m->hasTo('geral@quebom.pt')
            && $m->number === 'ORC-2026-001' && $m->totalMonthly === 300.0 && $m->totalOneOff === 270.0);
    }

    public function test_company_sees_only_sent_quotes_decides_and_downloads_the_pdf(): void
    {
        $draft = $this->createDraft(['company_id' => $this->client->id]);
        $sent = $this->createDraft(['company_id' => $this->client->id]);
        $this->send($sent['id']);
        $base = "/api/v1/companies/{$this->client->id}/quotes";

        $list = $this->actingAs($this->clientAdmin, 'sanctum')->getJson($base)->assertOk()->json('data');
        $this->assertSame([$sent['id']], array_column($list, 'id'));
        $this->assertArrayNotHasKey('notes', $list[0]);
        $this->actingAs($this->clientAdmin, 'sanctum')->patchJson("{$base}/{$draft['id']}/decision", ['decision' => 'approve'])->assertStatus(404);

        $pdf = $this->actingAs($this->clientAdmin, 'sanctum')->get("{$base}/{$sent['id']}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->clientAdmin, 'sanctum')->patchJson("{$base}/{$sent['id']}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        Mail::assertQueued(QuoteDecisionMail::class, fn ($m) => $m->accepted && $m->hasTo('simonfrtd@gmail.com'));

        // A equipa não decide pela empresa ligada.
        $other = $this->createDraft(['company_id' => $this->client->id]);
        $this->send($other['id']);
        $this->api()->patchJson("/api/v1/admin/quotes/{$other['id']}/decision", ['decision' => 'accept'])->assertStatus(422);
    }

    public function test_other_company_cannot_see_or_decide(): void
    {
        $q = $this->createDraft(['company_id' => $this->client->id]);
        $this->send($q['id']);
        $outsider = User::factory()->create(['company_id' => $this->team->id, 'role' => 'admin']);

        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/companies/{$this->client->id}/quotes")->assertStatus(403);
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/companies/{$this->team->id}/quotes")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($outsider, 'sanctum')->patchJson("/api/v1/companies/{$this->team->id}/quotes/{$q['id']}/decision", ['decision' => 'approve'])->assertStatus(404);
    }

    // ── Só a equipa XPLENDOR ─────────────────────────────────────────────────

    public function test_only_root_outside_impersonation_reaches_the_admin_module(): void
    {
        $q = $this->createDraft();

        $this->actingAs($this->clientAdmin, 'sanctum')->getJson('/api/v1/admin/quotes')->assertStatus(403);
        $this->actingAs($this->clientAdmin, 'sanctum')->postJson("/api/v1/admin/quotes/{$q['id']}/send")->assertStatus(403);
        $this->actingAs($this->clientAdmin, 'sanctum')->getJson('/api/v1/admin/service-catalog')->assertStatus(403);

        $nt = $this->clientAdmin->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $this->clientAdmin->id, 'company_id' => $this->client->id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->getJson('/api/v1/admin/quotes', ['Authorization' => 'Bearer ' . $nt->plainTextToken])->assertStatus(403);
    }

    // ── Catálogo ─────────────────────────────────────────────────────────────

    public function test_catalog_starts_with_the_three_services_and_is_editable(): void
    {
        $items = $this->api()->getJson('/api/v1/admin/service-catalog')->assertOk()->json('data');
        $this->assertSame(
            [['Social Media', 200.0, 'month', 'monthly'], ['Tráfego Pago', 200.0, 'month', 'monthly'], ['Website', 25.0, 'hour', 'one_off']],
            array_map(fn ($i) => [$i['name'], (float) $i['unit_price'], $i['unit'], $i['billing_type']], $items)
        );

        $this->api()->patchJson("/api/v1/admin/service-catalog/{$items[0]['id']}", ['unit_price' => 220, 'active' => false])->assertOk();
        $this->api()->postJson('/api/v1/admin/service-catalog', ['name' => 'Fotografia', 'unit_price' => 150, 'unit' => 'project', 'billing_type' => 'one_off'])->assertStatus(201);
        $active = $this->api()->getJson('/api/v1/admin/service-catalog?active_only=1')->json('data');
        $this->assertSame(['Tráfego Pago', 'Website', 'Fotografia'], array_column($active, 'name'));
    }

    // ── Dashboard root ───────────────────────────────────────────────────────

    public function test_summary_keeps_monthly_and_one_off_separate(): void
    {
        $open = $this->createDraft();
        $this->send($open['id']);                                             // em aberto: 300 €/mês · 270 €
        $acceptedNow = $this->createDraft(['global_discount_type' => null, 'global_discount_value' => null]);
        $this->send($acceptedNow['id']);
        $this->api()->patchJson("/api/v1/admin/quotes/{$acceptedNow['id']}/decision", ['decision' => 'accept']);   // 400 €/mês · 270 €
        $acceptedLastYear = $this->createDraft(['lines' => [['name' => 'Website', 'unit' => 'hour', 'billing_type' => 'one_off', 'quantity' => 40, 'unit_price' => 25]],
            'global_discount_type' => null, 'global_discount_value' => null]);
        $this->send($acceptedLastYear['id']);
        Quote::whereKey($acceptedLastYear['id'])->update(['status' => 'accepted', 'decided_at' => '2025-12-15 10:00:00']);
        $this->createDraft();                                                 // rascunho: não conta

        $s = $this->api()->getJson('/api/v1/admin/quotes/summary')->assertOk()->json('data');

        $this->assertSame([1, 300, 270], [$s['open']['count'], (int) $s['open']['monthly'], (int) $s['open']['one_off']]);
        $this->assertSame([2026, 1, 400, 270], [$s['accepted_year']['year'], $s['accepted_year']['count'], (int) $s['accepted_year']['monthly'], (int) $s['accepted_year']['one_off']]);
        $this->assertSame([2, 400, 1270], [$s['accepted_all']['count'], (int) $s['accepted_all']['monthly'], (int) $s['accepted_all']['one_off']]);
        $this->assertSame(1, $s['by_status']['draft']);
        $this->assertSame(0, $s['open']['expiring_7d']);                       // válido até 4 de novembro
        $this->assertArrayNotHasKey('total_amount', $s);

        $this->travelToLisbon('2026-10-30 10:00:00');
        $this->assertSame(1, $this->api()->getJson('/api/v1/admin/quotes/summary')->json('data.open.expiring_7d'));
    }

    // ── Migração dos estados antigos ─────────────────────────────────────────

    public function test_legacy_statuses_are_migrated_without_losing_data(): void
    {
        $insert = fn (string $status, string $created, float $amount) => DB::table('quotes')->insertGetId([
            'client_name' => "Cliente {$status}", 'description' => "Serviço {$status}", 'amount' => $amount,
            'status' => $status, 'created_at' => $created, 'updated_at' => $created,
        ]);
        $ids = [
            'pending'   => $insert('pending', '2026-09-20 10:00:00', 100),
            'approved'  => $insert('approved', '2026-09-01 10:00:00', 200),
            'rejected'  => $insert('rejected', '2026-09-02 10:00:00', 300),
            'paid'      => $insert('paid', '2026-09-03 10:00:00', 400),
            'completed' => $insert('completed', '2025-11-03 10:00:00', 500),
        ];

        $migration = require database_path('migrations/2026_11_10_100200_migrate_legacy_quote_statuses.php');
        $migration->up();
        $migration->up();   // idempotente

        $q = fn (string $k) => Quote::find($ids[$k]);
        $this->assertSame(['sent', 'pending', '2026-11-04'], [$q('pending')->status, $q('pending')->legacy_status, $q('pending')->valid_until->toDateString()]);
        $this->assertSame(['accepted', 'approved'], [$q('approved')->status, $q('approved')->legacy_status]);
        $this->assertSame(['refused', 'rejected'], [$q('rejected')->status, $q('rejected')->legacy_status]);
        $this->assertSame(['accepted', 'paid'], [$q('paid')->status, $q('paid')->legacy_status]);
        $this->assertSame(['accepted', 'completed'], [$q('completed')->status, $q('completed')->legacy_status]);

        $line = $q('paid')->lines()->sole();
        $this->assertSame(['Serviço paid', 'one_off', '400.00'], [$line->name, $line->billing_type, $line->line_total]);
        $this->assertSame([400.0, 0.0], [(float) $q('paid')->total_one_off, (float) $q('paid')->total_monthly]);

        // Números pela ordem de criação, por ano de criação.
        $this->assertSame('ORC-2025-001', $q('completed')->number);
        $this->assertSame(['ORC-2026-001', 'ORC-2026-002', 'ORC-2026-003', 'ORC-2026-004'],
            [$q('approved')->number, $q('rejected')->number, $q('paid')->number, $q('pending')->number]);
        $this->assertSame(5, DB::table('quote_lines')->count());

        // O primeiro orçamento novo continua a sequência sem repetir.
        $new = $this->createDraft();
        $this->assertSame('ORC-2026-005', $this->send($new['id'])->json('data.number'));
    }
}
