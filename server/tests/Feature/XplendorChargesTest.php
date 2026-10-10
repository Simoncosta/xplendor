<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ChargeRemindersJob;
use App\Mail\ChargeClientMail;
use App\Mail\ChargeTeamMail;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanyModule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCharge;
use App\Models\User;
use App\Services\Billing\ChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cobranças da XPLENDOR: a XPLENDOR não emite faturas; recebe o PDF, mostra-o ao cliente,
 * gere o estado e os lembretes. Categoria universal e bloqueada; só o root cria, marca e
 * anula; o cliente só lê e indica "Já paguei"; lembretes no vencimento e às segundas;
 * link seguro; a agência gestora não vê.
 */
class XplendorChargesTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;
    private Company $xplendor;
    private Company $client;
    private User $root;
    private User $clientAdmin;
    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-14 08:30:00', 'UTC')); // quarta-feira, 09:30 em Lisboa
        config(['app.frontend_url' => 'https://app.exemplo.pt/app']);

        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->xplendor = $this->company('XPLENDOR');
        $this->client = $this->company('Domiway', ['invoice_email' => 'faturas@domiway.pt']);
        $this->root = User::factory()->create(['company_id' => $this->xplendor->id, 'role' => 'root', 'email' => 'simon@xplendor.tech']);
        $this->clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'email' => 'admin@domiway.pt']);
        $this->clientUser = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(string $name, array $extra = []): Company
    {
        return Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $this->plan, 'subscription_status' => 'active'] + $extra);
    }

    private function as(?User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('fatura.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function createCharge(array $extra = [], ?Company $company = null): ExpenseCharge
    {
        $r = $this->as($this->root)->post('/api/v1/admin/charges', [
            'company_id' => ($company ?? $this->client)->id, 'description' => 'Gestão de redes sociais, outubro', 'amount' => '350.00',
            'due_date' => '2026-10-20', 'invoice' => $this->pdf(),
        ] + $extra, ['Accept' => 'application/json'])->assertOk();

        return ExpenseCharge::findOrFail($r->json('data.id'));
    }

    private function token(ExpenseCharge $c): string
    {
        return $c->token();
    }

    private function pub(ExpenseCharge $c): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Charge-Token' => $this->token($c), 'User-Agent' => 'Mozilla/5.0 (Macintosh) Safari/605']);
    }

    // ── Categoria ────────────────────────────────────────────────────────────

    public function test_the_xplendor_category_exists_in_every_company_and_nobody_edits_or_deletes_it(): void
    {
        $new = $this->company('Nova');
        foreach ([$this->xplendor, $this->client, $new] as $c) {
            $this->assertSame(1, ExpenseCategory::where('company_id', $c->id)->where('system_key', 'xplendor')->count());
        }
        CompanyModule::firstOrCreate(['company_id' => $this->client->id, 'module_key' => 'finance']);
        $cat = ExpenseCategory::where('company_id', $this->client->id)->where('system_key', 'xplendor')->sole();
        $base = "/api/v1/companies/{$this->client->id}";

        $this->as($this->clientAdmin)->putJson("{$base}/expense-categories/{$cat->id}", ['name' => 'Outra'])->assertStatus(409);
        $this->as($this->clientAdmin)->deleteJson("{$base}/expense-categories/{$cat->id}")->assertStatus(409);
        $this->as($this->root)->putJson("{$base}/expense-categories/{$cat->id}", ['name' => 'Outra', 'archived' => true])->assertStatus(409);
        $this->assertSame(['XPLENDOR', false], [$cat->fresh()->name, (bool) $cat->fresh()->archived]);
        $list = collect($this->as($this->clientAdmin)->getJson("{$base}/expense-categories")->json('data'));
        $this->assertTrue($list->firstWhere('id', $cat->id)['locked']);
        // Reservada: ninguém a escolhe numa despesa à mão.
        $this->as($this->clientAdmin)->postJson("{$base}/expenses", ['description' => 'X', 'amount' => 10, 'date' => '2026-10-01', 'expense_category_id' => $cat->id])
            ->assertStatus(422)->assertJsonValidationErrors('expense_category_id');
    }

    // ── Só o root cria, marca e anula; o cliente só lê ───────────────────────

    public function test_only_the_root_creates_marks_paid_and_cancels_and_the_client_only_reads(): void
    {
        $this->as($this->clientAdmin)->post('/api/v1/admin/charges', ['company_id' => $this->client->id, 'description' => 'X', 'amount' => 1,
            'due_date' => '2026-10-20', 'invoice' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        $this->as($this->root)->post('/api/v1/admin/charges', ['company_id' => $this->client->id, 'description' => 'X', 'amount' => 1,
            'due_date' => '2026-10-20', 'invoice' => UploadedFile::fake()->create('fatura.png', 10, 'image/png')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('invoice');

        $charge = $this->createCharge();
        $expense = $charge->expense;
        $this->assertSame([Expense::SOURCE_XPLENDOR, 'xplendor', '350.00', false], [$expense->source, $expense->category->system_key, $expense->amount, (bool) $expense->is_paid]);
        Storage::disk('local')->assertExists($charge->invoice_path);

        // O cliente vê nas Despesas (com Finanças), só de leitura, e nos totais.
        CompanyModule::firstOrCreate(['company_id' => $this->client->id, 'module_key' => 'finance']);
        $base = "/api/v1/companies/{$this->client->id}";
        $row = collect($this->as($this->clientAdmin)->getJson("{$base}/expenses")->assertOk()->json('data.data'))->firstWhere('id', $expense->id);
        $this->assertSame([true, false, false, 'open'], [$row['is_xplendor_charge'], $row['can_edit'], $row['can_delete'], $row['charge']['status']]);
        // Quem vê as Finanças sem ser Administrador vê a despesa, sem a cobrança (estado, fatura, "Já paguei").
        $row = collect($this->as($this->clientUser)->getJson("{$base}/expenses")->assertOk()->json('data.data'))->firstWhere('id', $expense->id);
        $this->assertSame([true, null], [$row['is_xplendor_charge'], $row['charge']]);
        $this->assertEquals(350.0, $this->as($this->clientUser)->getJson("{$base}/expenses/summary")->json('data.total_amount'));
        $this->as($this->clientAdmin)->putJson("{$base}/expenses/{$expense->id}", ['description' => 'Y', 'amount' => 1, 'date' => '2026-10-01', 'is_paid' => true])->assertStatus(409);
        $this->as($this->clientAdmin)->deleteJson("{$base}/expenses/{$expense->id}")->assertStatus(409);
        foreach (['paid', 'cancel', 'refuse', 'send'] as $action) {
            $this->as($this->clientAdmin)->postJson("/api/v1/admin/charges/{$charge->id}/{$action}", ['reason' => 'x', 'note' => 'x'])->assertForbidden();
        }
        // Sem o módulo de Finanças, o Administrador vê as cobranças na mesma (e descarrega a fatura);
        // a faturação da XPLENDOR é só do Administrador: o utilizador recebe 403.
        CompanyModule::where('company_id', $this->client->id)->where('module_key', 'finance')->delete();
        $this->assertCount(1, $this->as($this->clientAdmin)->getJson("{$base}/xplendor-charges")->assertOk()->json('data.charges'));
        $this->as($this->clientAdmin)->get("{$base}/xplendor-charges/{$charge->id}/invoice")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->as($this->clientUser)->getJson("{$base}/xplendor-charges")->assertForbidden()->assertJsonPath('reason', 'so_administrador');
        $this->as($this->clientUser)->get("{$base}/xplendor-charges/{$charge->id}/invoice")->assertForbidden();

        // O root marca como paga; anular uma paga é recusado.
        $this->as($this->root)->postJson("/api/v1/admin/charges/{$charge->id}/paid")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertTrue((bool) $expense->fresh()->is_paid);
        $this->as($this->root)->postJson("/api/v1/admin/charges/{$charge->id}/cancel", ['reason' => 'Erro'])->assertStatus(422);

        // Anular (com motivo): a despesa fica arquivada e o link deixa de valer.
        $other = $this->createCharge(['description' => 'Outro serviço']);
        $this->as($this->root)->postJson("/api/v1/admin/charges/{$other->id}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->root)->postJson("/api/v1/admin/charges/{$other->id}/cancel", ['reason' => 'Fatura emitida por engano.'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertTrue((bool) $other->expense->fresh()->archived);
        $this->pub($other)->getJson('/api/public/charge')->assertNotFound();
    }

    // ── Lembretes ────────────────────────────────────────────────────────────

    public function test_reminders_on_the_due_date_then_mondays_until_paid_cancelled_or_indicated_never_twice_a_day(): void
    {
        $this->client->forceFill(['billing_reminders_enabled' => true])->save();
        $charge = $this->createCharge(); // vence a 2026-10-20 (terça-feira)
        Mail::assertQueued(ChargeClientMail::class, fn ($m) => $m->kind === 'new' && $m->hasTo('faturas@domiway.pt'));
        Mail::fake();
        $svc = app(ChargeService::class);
        $day = fn (string $d) => Carbon::parse($d, ChargeService::TIMEZONE);

        $this->assertSame(0, $svc->runReminders($day('2026-10-19'))); // segunda antes do vencimento: nada
        $this->assertSame(1, $svc->runReminders($day('2026-10-20'))); // vencimento
        $this->assertSame(0, $svc->runReminders($day('2026-10-20'))); // nunca dois no mesmo dia
        $this->assertSame(0, $svc->runReminders($day('2026-10-22'))); // quinta: nada
        $this->assertSame(1, $svc->runReminders($day('2026-10-26'))); // segunda seguinte
        $this->assertSame(1, $svc->runReminders($day('2026-11-02'))); // e a outra
        Mail::assertQueued(ChargeClientMail::class, 3);
        Mail::assertQueued(ChargeClientMail::class, fn ($m) => $m->kind === 'reminder' && $m->overdue && str_contains($m->url, '/cobranca#'));

        // Pagamento indicado: param; recusado: retomam na segunda seguinte.
        $this->pub($charge)->post('/api/public/charge/paid', ['note' => 'Transferência feita.'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, $svc->runReminders($day('2026-11-09')));
        $this->as($this->root)->postJson("/api/v1/admin/charges/{$charge->id}/refuse", ['note' => 'Não encontrámos a transferência.'])->assertOk()->assertJsonPath('data.status', 'open');
        Mail::assertQueued(ChargeClientMail::class, fn ($m) => $m->kind === 'refused' && $m->note === 'Não encontrámos a transferência.');
        $this->assertSame(1, $svc->runReminders($day('2026-11-16')));
        // Paga ou anulada: param.
        $this->as($this->root)->postJson("/api/v1/admin/charges/{$charge->id}/paid")->assertOk();
        $this->assertSame(0, $svc->runReminders($day('2026-11-23')));

        // O job agendado corre o mesmo cálculo.
        $this->assertSame(['sent' => 0, 'pruned_opens' => 0], app(ChargeRemindersJob::class)->handle($svc));
    }

    public function test_the_switch_is_off_by_default_and_the_root_can_always_send_now(): void
    {
        $this->assertFalse((bool) $this->client->fresh()->billing_reminders_enabled);
        $charge = $this->createCharge(['due_date' => '2026-10-14']);
        Mail::assertNothingQueued();
        $this->assertSame(0, app(ChargeService::class)->runReminders(Carbon::parse('2026-10-14', ChargeService::TIMEZONE)));

        $this->as($this->root)->postJson("/api/v1/admin/charges/{$charge->id}/send")->assertOk();
        Mail::assertQueued(ChargeClientMail::class, 1);
        // "Enviar agora" conta como o email do dia: com os lembretes ligados, o job não repete hoje.
        $this->client->forceFill(['billing_reminders_enabled' => true])->save();
        $this->assertSame(0, app(ChargeService::class)->runReminders(Carbon::parse('2026-10-14', ChargeService::TIMEZONE)));

        // O admin da empresa liga o interruptor no perfil.
        $this->as($this->clientAdmin)->putJson("/api/v1/companies/{$this->client->id}", ['billing_reminders_enabled' => false])->assertOk();
        $this->assertFalse((bool) $this->client->fresh()->billing_reminders_enabled);
    }

    public function test_recipients_fall_back_to_the_admins_and_then_to_an_alert_for_the_root(): void
    {
        $this->client->forceFill(['billing_reminders_enabled' => true, 'invoice_email' => null])->save();
        $this->createCharge();
        Mail::assertQueued(ChargeClientMail::class, fn ($m) => $m->hasTo('admin@domiway.pt'));

        $empty = $this->company('Sem contactos', ['billing_reminders_enabled' => true]);
        $this->createCharge([], $empty);
        $this->createCharge(['description' => 'Segunda'], $empty);
        $alerts = Alert::where('company_id', $this->xplendor->id)->where('title', 'like', 'Cobrança sem destinatário%')->count();
        $this->assertSame(2, $alerts, 'Um aviso por cobrança, uma só vez.');
        app(ChargeService::class)->runReminders(Carbon::parse('2026-10-20', ChargeService::TIMEZONE));
        $this->assertSame(2, Alert::where('company_id', $this->xplendor->id)->where('title', 'like', 'Cobrança sem destinatário%')->count());
    }

    // ── Pagamento indicado ───────────────────────────────────────────────────

    public function test_payment_indicated_in_the_app_with_proof_stops_reminders_and_notifies_the_root(): void
    {
        $charge = $this->createCharge();
        $base = "/api/v1/companies/{$this->client->id}/xplendor-charges/{$charge->id}";
        $this->as($this->clientAdmin)->post("{$base}/paid", ['proof' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->as($this->clientAdmin)->post("{$base}/paid", ['proof' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->as($this->clientAdmin)->post("{$base}/paid", ['note' => 'Pago por MB Way.', 'proof' => UploadedFile::fake()->image('comprovativo.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'payment_indicated');

        $charge->refresh();
        $this->assertSame(['app', $this->clientAdmin->id], [$charge->payment_indicated_via, $charge->payment_indicated_by_user_id]);
        Storage::disk('local')->assertExists($charge->proof_path);
        $this->assertSame(1, Alert::where('company_id', $this->xplendor->id)->where('title', 'Pagamento indicado: Domiway')->count());
        Mail::assertQueued(ChargeTeamMail::class, fn ($m) => $m->hasTo('simon@xplendor.tech') && $m->hasProof && $m->via === 'app');
        $this->as($this->root)->get("/api/v1/admin/charges/{$charge->id}/proof")->assertOk();
        // Uma segunda indicação não é possível enquanto aguarda confirmação.
        $this->as($this->clientAdmin)->post("{$base}/paid", [], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ── Link seguro ──────────────────────────────────────────────────────────

    public function test_the_secure_link(): void
    {
        $charge = $this->createCharge();
        $token = $this->token($charge);

        // Token só no cabeçalho; mal formado ou desconhecido: 404 sem distinguir.
        $this->getJson("/api/public/charge?token={$token}")->assertNotFound();
        $this->withHeaders(['X-Charge-Token' => str_repeat('a', 64)])->getJson('/api/public/charge')->assertNotFound();
        $this->withHeaders(['X-Charge-Token' => 'curto'])->getJson('/api/public/charge')->assertNotFound();

        $r = $this->pub($charge)->getJson('/api/public/charge')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(['Domiway', 350.0, '2026-10-20', 'open', true], [$r->json('data.company'), (float) $r->json('data.amount'), $r->json('data.due_date'), $r->json('data.status'), $r->json('data.can_indicate_payment')]);
        $this->assertStringNotContainsString($token, $r->getContent());
        $this->assertArrayNotHasKey('payment_note', $r->json('data'));
        $this->pub($charge)->get('/api/public/charge/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Aberturas: robôs (verificadores dos emails) e a equipa não contam; a mesma visita conta uma vez.
        $this->withHeaders(['X-Charge-Token' => $token, 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->postJson('/api/public/charge/open', ['visitor_id' => 'visitante-0123456789'])->assertOk();
        $this->pub($charge)->postJson('/api/public/charge/open', ['visitor_id' => 'visitante-0123456789'])->assertOk()->assertJsonPath('data.counted', true);
        $this->pub($charge)->postJson('/api/public/charge/open', ['visitor_id' => 'visitante-0123456789'])->assertOk()->assertJsonPath('data.counted', false);
        $this->assertSame(1, $charge->fresh()->open_count);

        // "Já paguei" pelo link (sem conta), com comprovativo em PDF.
        $this->pub($charge)->post('/api/public/charge/paid', ['proof' => UploadedFile::fake()->create('comprovativo.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'payment_indicated')->assertJsonPath('data.can_indicate_payment', false);
        $this->assertSame('link', $charge->fresh()->payment_indicated_via);

        // Paga: o link fica como recibo 90 dias; depois deixa de valer.
        $this->as($this->root)->postJson("/api/v1/admin/charges/{$charge->id}/paid")->assertOk();
        $this->pub($charge)->getJson('/api/public/charge')->assertOk()->assertJsonPath('data.status', 'paid');
        Carbon::setTestNow(now()->addDays(91));
        $this->pub($charge)->getJson('/api/public/charge')->assertNotFound();
    }

    public function test_the_link_has_request_limits(): void
    {
        $charge = $this->createCharge();
        $codes = collect(range(1, 12))->map(fn () => $this->pub($charge)->post('/api/public/charge/paid', [], ['Accept' => 'application/json'])->status());
        $this->assertContains(429, $codes->all(), 'Ações pelo link têm limite por minuto.');
    }

    // ── Visibilidade e tenancy ───────────────────────────────────────────────

    public function test_the_managing_agency_never_sees_the_charges_and_other_companies_neither(): void
    {
        $agency = $this->company('Agência Norte');
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        $member = User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']);
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform', 'status' => 'active', 'active_key' => $this->client->id]);
        CompanyModule::firstOrCreate(['company_id' => $this->client->id, 'module_key' => 'finance']);
        $charge = $this->createCharge();
        $manual = Expense::create(['company_id' => $this->client->id, 'description' => 'Renda', 'amount' => 500, 'date' => '2026-10-01']);
        $base = "/api/v1/companies/{$this->client->id}";

        $ids = collect($this->as($member)->getJson("{$base}/expenses")->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$manual->id], $ids);
        $this->assertEquals(500.0, $this->as($member)->getJson("{$base}/expenses/summary")->json('data.total_amount'));
        $this->as($member)->getJson("{$base}/expenses/{$charge->expense_id}")->assertNotFound();
        $this->as($member)->getJson("{$base}/xplendor-charges")->assertForbidden();
        $this->as($member)->get("{$base}/xplendor-charges/{$charge->id}/invoice")->assertForbidden();
        $this->as($member)->post("{$base}/xplendor-charges/{$charge->id}/paid", [], ['Accept' => 'application/json'])->assertForbidden();

        // Outra empresa: nem pelo seu endereço nem pelo da cliente.
        $other = $this->company('Outra');
        $otherUser = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->as($otherUser)->getJson("{$base}/xplendor-charges")->assertForbidden();
        $this->as($otherUser)->get("/api/v1/companies/{$other->id}/xplendor-charges/{$charge->id}/invoice")->assertNotFound();
        $this->as($otherUser)->getJson('/api/v1/admin/charges')->assertForbidden();
        // O root vê tudo.
        $this->assertCount(1, $this->as($this->root)->getJson('/api/v1/admin/charges')->assertOk()->json('data.charges'));
    }
}
