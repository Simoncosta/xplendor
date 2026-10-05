<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PruneQuoteActivityJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteOpen;
use App\Models\QuotePublicLink;
use App\Models\QuoteResponse;
use App\Models\ServiceCatalogItem;
use App\Models\SupportTicket;
use App\Models\SupportTicketTask;
use App\Models\User;
use App\Support\TeamDeviceMarker;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Orçamentos, Parte A (backend): link público por versão enviada, página pública,
 * aberturas (robôs e equipa não contam, janela de 30 minutos, avisos no máximo a cada
 * 6 horas, sem IP), aceitação total ou parcial com o desconto de pacote ligado às
 * linhas, aceitação única, recusa, pedido de alterações, expirado e versão antiga,
 * ticket de arranque com a lista de tarefas, dashboard só com as linhas aceites,
 * retenção e segurança das rotas públicas.
 */
class QuotePublicLinkTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

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
        config(['app.frontend_url' => 'https://app.exemplo.pt/app']);

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->team = Company::create(['nipc' => '500060001', 'fiscal_name' => 'Xplendor', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->client = Company::create(['nipc' => '500060002', 'fiscal_name' => 'Quebom', 'email' => 'geral@quebom.pt', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->team->id, 'role' => 'root']);
        $this->clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── auxiliares ───────────────────────────────────────────────────────────

    private function travelToLisbon(string $lisbonTime): void
    {
        $utc = CarbonImmutable::parse($lisbonTime, 'Europe/Lisbon')->utc();
        Carbon::setTestNow(Carbon::instance($utc->toDateTime()));
        CarbonImmutable::setTestNow($utc);
    }

    private function later(int $minutes): void
    {
        $next = CarbonImmutable::now()->addMinutes($minutes);
        Carbon::setTestNow(Carbon::instance($next->toDateTime()));
        CarbonImmutable::setTestNow($next);
    }

    private function asRoot(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->root, 'sanctum');
    }

    /** Pedido sem sessão (página pública). */
    private function anon(string $userAgent = self::BROWSER): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['User-Agent' => $userAgent]);
    }

    private function catalogId(string $name): int
    {
        return (int) ServiceCatalogItem::where('name', $name)->value('id');
    }

    /**
     * Social Media (mensal 200, do pacote), Tráfego Pago (mensal 200, opcional, do pacote),
     * Website (12 h a 25, opcional, fora do pacote). Desconto de pacote de 10%.
     * Totais completos: mensal 360, valor único 270.
     */
    private function sentQuote(array $extra = []): array
    {
        $quote = $this->asRoot()->postJson('/api/v1/admin/quotes', array_merge([
            'new_customer' => ['name' => 'Pastelaria Doce Ribeira', 'phone' => '+351 912 345 678', 'email' => 'ana@exemplo.pt'],
            'title' => 'Presença digital',
            'notes' => 'NOTA INTERNA: cliente sensível ao preço',
            'lines' => [
                ['catalog_item_id' => $this->catalogId('Social Media'), 'name' => 'Social Media', 'unit' => 'month', 'billing_type' => 'monthly', 'quantity' => 1, 'unit_price' => 200, 'in_package' => true],
                ['catalog_item_id' => $this->catalogId('Tráfego Pago'), 'name' => 'Tráfego Pago', 'unit' => 'month', 'billing_type' => 'monthly', 'quantity' => 1, 'unit_price' => 200, 'is_optional' => true, 'in_package' => true],
                ['catalog_item_id' => $this->catalogId('Website'), 'name' => 'Website', 'unit' => 'hour', 'billing_type' => 'one_off', 'quantity' => 12, 'unit_price' => 25, 'is_optional' => true],
            ],
            'global_discount_type' => 'percent', 'global_discount_value' => 10, 'global_discount_label' => 'Desconto de pacote',
        ], $extra))->assertStatus(201)->json('data');
        $this->asRoot()->postJson("/api/v1/admin/quotes/{$quote['id']}/send")->assertOk();

        return ['id' => $quote['id'], 'token' => $this->tokenOf($quote['id'])];
    }

    private function tokenOf(int $quoteId, ?int $version = null): string
    {
        $links = $this->asRoot()->getJson("/api/v1/admin/quotes/{$quoteId}/activity")->assertOk()->json('data.links');
        $link = $version ? collect($links)->firstWhere('version', $version) : collect($links)->firstWhere('is_latest', true);

        return substr($link['url'], strrpos($link['url'], '/') + 1);
    }

    private function open(string $token, string $visitor = 'visitante-aaaaaaaaaaaa', string $ua = self::BROWSER, array $extra = [])
    {
        return $this->anon($ua)->postJson("/api/public/quotes/{$token}/open", ['visitor_id' => $visitor] + $extra);
    }

    private function accept(string $token, array $optionalKeys, array $extra = [])
    {
        return $this->anon()->postJson("/api/public/quotes/{$token}/accept", array_merge([
            'name' => 'Ana Ribeiro', 'email' => 'Ana@Exemplo.pt', 'terms_accepted' => true, 'optional_keys' => $optionalKeys,
        ], $extra));
    }

    private function teamAlerts(): \Illuminate\Support\Collection
    {
        return Alert::where('company_id', $this->team->id)->orderBy('id')->get();
    }

    // ── 1. Link por versão enviada ───────────────────────────────────────────

    public function test_sending_creates_an_unguessable_link_stored_only_hashed_and_encrypted(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        $this->assertSame(64, strlen($token));
        $this->assertTrue(ctype_alnum($token));
        $link = QuotePublicLink::where('quote_id', $id)->sole();
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertStringNotContainsString($token, (string) DB::table('quote_public_links')->where('id', $link->id)->value('token_encrypted'));
        $this->assertSame("https://app.exemplo.pt/app/orcamento/{$token}", $link->url());
        // Só a equipa vê os links e a atividade.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clientAdmin, 'sanctum')->getJson("/api/v1/admin/quotes/{$id}/activity")->assertForbidden();
    }

    // ── 2. Página pública ────────────────────────────────────────────────────

    public function test_public_page_shows_only_that_version_without_internal_notes_and_with_noindex(): void
    {
        ['token' => $token] = $this->sentQuote();
        $this->sentQuote(['new_customer' => ['name' => 'Outro Cliente Lda', 'email' => 'outro@exemplo.pt']]);

        $r = $this->anon()->getJson("/api/public/quotes/{$token}")->assertOk();
        $r->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame('open', $r->json('data.state'));
        $this->assertTrue($r->json('data.can_respond'));
        $this->assertTrue($r->json('data.has_optional'));
        $this->assertSame(['Social Media', 'Tráfego Pago', 'Website'], array_column($r->json('data.lines'), 'name'));
        $this->assertSame([false, true, true], array_column($r->json('data.lines'), 'is_optional'));
        $this->assertSame(360.0, (float) $r->json('data.buckets.monthly.total'));
        $body = $r->getContent();
        $this->assertStringNotContainsString('NOTA INTERNA', $body);
        $this->assertStringNotContainsString('Outro Cliente', $body);

        $pdf = $this->anon()->get("/api/public/quotes/{$token}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $pdf->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        // Token desconhecido ou mal formado: 404, sem distinguir.
        $this->anon()->getJson('/api/public/quotes/' . str_repeat('a', 64))->assertNotFound();
        $this->anon()->getJson('/api/public/quotes/curto')->assertNotFound();
    }

    // ── 3. Aberturas ─────────────────────────────────────────────────────────

    public function test_robots_and_the_team_never_count_as_opens(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        foreach (['WhatsApp/2.23.20.0 A', 'facebookexternalhit/1.1', 'TelegramBot (like TwitterBot)', 'Slackbot-LinkExpanding 1.0',
            'Twitterbot/1.0', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'Mozilla/5.0 HeadlessChrome/120', 'Microsoft Office Existence Discovery', ''] as $ua) {
            $this->open($token, 'robo-bbbbbbbbbbbbbbbb', $ua)->assertOk()->assertJsonPath('data.counted', false)->assertJsonPath('data.reason', 'bot');
        }
        // Browser da equipa (marca assinada no localStorage) e root com sessão.
        $this->open($token, 'equipa-ccccccccccccccc', self::BROWSER, ['team_marker' => TeamDeviceMarker::issue($this->root)])
            ->assertJsonPath('data.reason', 'team');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->root, 'sanctum')->withHeaders(['User-Agent' => self::BROWSER])
            ->postJson("/api/public/quotes/{$token}/open", ['visitor_id' => 'equipa-dddddddddddddd'])->assertJsonPath('data.reason', 'team');
        // A pré-visualização da equipa nunca conta nem permite responder.
        $p = $this->asRoot()->getJson("/api/v1/admin/quotes/{$id}/versions/1/public-preview")->assertOk();
        $this->assertTrue($p->json('data.preview'));
        $this->assertFalse($p->json('data.can_respond'));
        // Marca adulterada não serve.
        $this->open($token, 'cliente-eeeeeeeeeeeeee', self::BROWSER, ['team_marker' => TeamDeviceMarker::issue($this->root) . 'x'])
            ->assertJsonPath('data.counted', true);

        $this->assertSame(1, QuoteOpen::count());
        $this->assertSame(1, Quote::find($id)->open_count);
    }

    public function test_reloading_within_30_minutes_is_the_same_open_and_no_ip_is_stored(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        $this->open($token)->assertJsonPath('data.counted', true);
        $this->later(10);
        $this->open($token)->assertJsonPath('data.counted', false)->assertJsonPath('data.reason', 'same_visit');
        $this->later(25); // 25 minutos depois da última atividade: continua a ser a mesma visita
        $this->open($token)->assertJsonPath('data.counted', false);
        $this->later(31); // 31 minutos sem atividade: nova abertura
        $this->open($token)->assertJsonPath('data.counted', true);
        $this->open($token, 'outro-browser-ffffffff', self::DESKTOP)->assertJsonPath('data.counted', true);

        $quote = Quote::find($id);
        $this->assertSame(3, $quote->open_count);
        $this->assertNotNull($quote->first_opened_at);
        $this->assertSame(['mobile', 'mobile', 'desktop'], QuoteOpen::orderBy('id')->pluck('device')->all());
        // Sem IP em lado nenhum da abertura, e o identificador do browser só em hash.
        $row = (array) DB::table('quote_opens')->first();
        $this->assertArrayNotHasKey('ip', $row);
        $this->assertArrayNotHasKey('ip_address', $row);
        $this->assertStringNotContainsString('127.0.0.1', json_encode(DB::table('quote_opens')->get()));
        $this->assertStringNotContainsString('visitante-aaaaaaaaaaaa', json_encode(DB::table('quote_opens')->get()));

        $activity = $this->asRoot()->getJson("/api/v1/admin/quotes/{$id}/activity")->assertOk();
        $this->assertSame(3, $activity->json('data.opens.count'));
        $this->assertCount(3, $activity->json('data.opens.timeline'));
        $list = collect($this->asRoot()->getJson('/api/v1/admin/quotes')->json('data'))->firstWhere('id', $id);
        $this->assertSame(3, $list['open_count']);
    }

    public function test_team_alert_on_first_open_then_at_most_every_6_hours(): void
    {
        ['token' => $token] = $this->sentQuote();

        $this->open($token, 'browser-1-aaaaaaaaaaa');
        $this->assertSame(['Orçamento aberto pela primeira vez: ORC-2026-001'], $this->teamAlerts()->pluck('title')->all());
        $this->assertSame('/admin/quotes/' . Quote::first()->id, $this->teamAlerts()->first()->detail_path);

        $this->later(60);
        $this->open($token, 'browser-2-aaaaaaaaaaa'); // nova abertura, mas dentro das 6 horas
        $this->later(4 * 60);
        $this->open($token, 'browser-3-aaaaaaaaaaa');
        $this->assertCount(1, $this->teamAlerts());

        $this->later(61); // 6 horas e 1 minuto depois do primeiro aviso
        $this->open($token, 'browser-4-aaaaaaaaaaa');
        $this->assertSame('Orçamento aberto de novo: ORC-2026-001', $this->teamAlerts()->last()->title);
        $this->assertCount(2, $this->teamAlerts());
        $this->assertSame(4, Quote::first()->open_count);
    }

    // ── 4 e 5. Aceitação total ou parcial ────────────────────────────────────

    public function test_partial_acceptance_drops_the_package_discount_with_an_explanation(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        // Fica só o Website (opcional, fora do pacote); o Tráfego Pago (do pacote) sai.
        $r = $this->accept($token, [2])->assertOk();
        $this->assertSame('accepted', $r->json('data.state'));
        $this->assertFalse($r->json('data.can_respond'));
        $this->assertFalse($r->json('data.acceptance.discount.applies'));
        $this->assertSame('Desconto de pacote: deixa de se aplicar porque o serviço Tráfego Pago não foi incluído.', $r->json('data.acceptance.discount.reason'));

        $quote = Quote::find($id);
        $this->assertSame('accepted', $quote->status);
        $this->assertSame(200.0, (float) $quote->accepted_total_monthly);
        $this->assertSame(300.0, (float) $quote->accepted_total_one_off);
        $this->assertSame(360.0, (float) $quote->total_monthly, 'O total proposto não muda.');

        $response = QuoteResponse::where('type', 'accepted')->sole();
        $this->assertSame(['Ana Ribeiro', 'ana@exemplo.pt', true, [0, 2]], [$response->name, $response->email, $response->terms_accepted, $response->accepted_line_keys]);
        $this->assertNotNull($response->created_at);
        $this->assertSame([1], $response->selection['excluded_keys']);

        // Dashboard root: só as linhas aceites.
        $summary = $this->asRoot()->getJson('/api/v1/admin/quotes/summary')->json('data.accepted_all');
        $this->assertSame([200.0, 300.0], [(float) $summary['monthly'], (float) $summary['one_off']]);
    }

    public function test_package_discount_stays_when_all_package_lines_are_kept(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        $r = $this->accept($token, [1])->assertOk(); // sai só o Website (fora do pacote)
        $this->assertTrue($r->json('data.acceptance.discount.applies'));
        $this->assertSame([360.0, 0.0], [(float) Quote::find($id)->accepted_total_monthly, (float) Quote::find($id)->accepted_total_one_off]);
    }

    public function test_only_optional_lines_can_be_left_out_and_acceptance_needs_name_email_and_terms(): void
    {
        ['token' => $token] = $this->sentQuote();

        $this->accept($token, [0])->assertStatus(422)->assertJsonValidationErrors('lines'); // linha 0 não é opcional
        $this->accept($token, [9])->assertStatus(422);
        $this->accept($token, [1, 2], ['terms_accepted' => false])->assertStatus(422)->assertJsonValidationErrors('terms_accepted');
        $this->accept($token, [1, 2], ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->accept($token, [1, 2], ['email' => 'nao-e-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, QuoteResponse::count());
        $this->assertSame('sent', Quote::first()->status);
    }

    public function test_acceptance_is_unique_and_cannot_be_changed_afterwards(): void
    {
        ['token' => $token] = $this->sentQuote();

        $this->accept($token, [1, 2])->assertOk();
        $this->accept($token, [1])->assertStatus(409)->assertJsonPath('message', 'Este orçamento já foi aceite.');
        $this->anon()->postJson("/api/public/quotes/{$token}/refuse", ['reason' => 'Mudei de ideias'])->assertStatus(409);
        $this->anon()->postJson("/api/public/quotes/{$token}/request-changes", ['message' => 'Quero mudar'])->assertStatus(409);

        $this->assertSame(1, QuoteResponse::count());
        $this->assertSame([0, 1, 2], QuoteResponse::first()->accepted_line_keys);
    }

    // ── 6. Recusar e pedir alterações ────────────────────────────────────────

    public function test_refuse_with_optional_reason_notifies_the_team(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        $this->anon()->postJson("/api/public/quotes/{$token}/refuse", ['reason' => 'Fora do orçamento'])->assertOk()->assertJsonPath('data.state', 'refused');
        $this->assertSame('refused', Quote::find($id)->status);
        $alert = $this->teamAlerts()->last();
        $this->assertSame('Orçamento recusado: ORC-2026-001', $alert->title);
        $this->assertStringContainsString('Fora do orçamento', $alert->message);
        $this->assertSame('Fora do orçamento', QuoteResponse::where('type', 'refused')->value('message'));
    }

    public function test_acceptance_after_a_change_request_is_highlighted_to_the_team(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        $this->anon()->postJson("/api/public/quotes/{$token}/request-changes", ['message' => ''])->assertStatus(422);
        $r = $this->anon()->postJson("/api/public/quotes/{$token}/request-changes", ['message' => 'Podem incluir LinkedIn?'])->assertOk();
        $this->assertTrue($r->json('data.changes_requested'));
        $this->assertTrue($r->json('data.can_respond'), 'Pode ainda aceitar a mesma versão.');
        $this->assertNotNull(Quote::find($id)->changes_requested_at);
        $this->assertSame('Pedido de alterações: ORC-2026-001', $this->teamAlerts()->last()->title);
        $this->assertSame('Podem incluir LinkedIn?', $this->asRoot()->getJson("/api/v1/admin/quotes/{$id}/activity")->json('data.responses.0.message'));

        $this->accept($token, [1, 2])->assertOk();
        $alert = $this->teamAlerts()->firstWhere('title', 'Aceite depois de um pedido de alterações: ORC-2026-001');
        $this->assertNotNull($alert);
        $this->assertSame(['urgent', 'high'], [$alert->type, $alert->severity]);
        $this->assertTrue(QuoteResponse::where('type', 'accepted')->sole()->after_changes_request);
    }

    // ── Expirado e versão antiga ─────────────────────────────────────────────

    public function test_expired_quote_shows_expired_and_rejects_answers(): void
    {
        ['token' => $token] = $this->sentQuote();

        $this->travelToLisbon('2026-11-05 09:00:00'); // validade até 4 de novembro
        $this->anon()->getJson("/api/public/quotes/{$token}")->assertOk()
            ->assertJsonPath('data.state', 'expired')->assertJsonPath('data.state_message', 'Este orçamento expirou.')->assertJsonPath('data.can_respond', false);
        $this->accept($token, [1, 2])->assertStatus(409)->assertJsonPath('message', 'Este orçamento expirou.');
        $this->assertSame(0, QuoteResponse::count());
    }

    public function test_old_version_link_shows_the_newer_version_notice_and_rejects_answers(): void
    {
        ['id' => $id, 'token' => $v1] = $this->sentQuote();

        $this->asRoot()->putJson("/api/v1/admin/quotes/{$id}", ['title' => 'Presença digital, revisto'])->assertOk(); // versão 2 em rascunho
        $this->anon()->getJson("/api/public/quotes/{$v1}")->assertJsonPath('data.state', 'under_revision');
        $this->accept($v1, [1, 2])->assertStatus(409);

        $this->asRoot()->postJson("/api/v1/admin/quotes/{$id}/send")->assertOk();
        $v2 = $this->tokenOf($id);
        $this->assertNotSame($v1, $v2);
        $this->anon()->getJson("/api/public/quotes/{$v1}")->assertJsonPath('data.state', 'superseded')
            ->assertJsonPath('data.state_message', 'Existe uma versão mais recente deste orçamento.')
            ->assertJsonPath('data.version', 1);
        $this->accept($v1, [1, 2])->assertStatus(409);
        $this->accept($v2, [1, 2])->assertOk();
        $this->assertSame(2, QuoteResponse::first()->version->version);
    }

    // ── 7. Arranque automático ───────────────────────────────────────────────

    public function test_acceptance_creates_the_onboarding_ticket_with_the_tasks_of_each_accepted_service(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();

        $this->accept($token, [2])->assertOk(); // Social Media e Website

        $quote = Quote::find($id);
        $ticket = SupportTicket::findOrFail($quote->onboarding_ticket_id);
        $this->assertSame('onboarding', $ticket->type);
        $this->assertSame('Arranque: Pastelaria Doce Ribeira, Social Media, Website', $ticket->title);
        $this->assertSame($this->team->id, $ticket->company_id, 'Sem empresa ligada: o arranque fica na empresa da equipa.');
        $this->assertSame($this->root->id, $ticket->user_id);
        $this->assertSame([
            ['Social Media', 'Preencher o perfil da marca'], ['Social Media', 'Pedir o acesso às redes sociais'], ['Social Media', 'Preparar o primeiro calendário editorial'],
            ['Website', 'Confirmar o domínio'], ['Website', 'Confirmar o alojamento'], ['Website', 'Receber os conteúdos e as fotos'],
        ], SupportTicketTask::orderBy('position')->get()->map(fn ($t) => [$t->group_label, $t->title])->all());
        $this->assertSame('Orçamento aceite: ORC-2026-001', $this->teamAlerts()->last()->title);

        // A equipa marca as tarefas; o cliente (empresa ligada) só as vê.
        $task = SupportTicketTask::first();
        $this->asRoot()->patchJson("/api/v1/admin/tickets/{$ticket->id}/tasks/{$task->id}", ['done' => true])->assertOk()
            ->assertJsonPath('data.tasks.0.done', true);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clientAdmin, 'sanctum')->patchJson("/api/v1/admin/tickets/{$ticket->id}/tasks/{$task->id}", ['done' => false])->assertForbidden();
    }

    public function test_linked_company_gets_the_ticket_and_manual_acceptance_also_starts_the_project_once(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote(['company_id' => $this->client->id]);

        // A empresa ligada decide no painel (aceita tudo): também arranca.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clientAdmin, 'sanctum')->patchJson("/api/v1/companies/{$this->client->id}/quotes/{$id}/decision", ['decision' => 'approve'])->assertOk();

        $quote = Quote::find($id);
        $ticket = SupportTicket::findOrFail($quote->onboarding_ticket_id);
        $this->assertSame($this->client->id, $ticket->company_id);
        $this->assertSame([360.0, 270.0], [(float) $quote->accepted_total_monthly, (float) $quote->accepted_total_one_off]);
        $this->assertSame(9, SupportTicketTask::where('support_ticket_id', $ticket->id)->count());
        $this->accept($token, [1, 2])->assertStatus(409); // a primeira decisão vale
        $this->assertSame(1, SupportTicket::where('type', 'onboarding')->count());
        // O cliente vê a lista no Suporte.
        $show = $this->actingAs($this->clientAdmin, 'sanctum')->getJson("/api/v1/companies/{$this->client->id}/support-tickets/{$ticket->id}")->assertOk();
        $this->assertCount(9, $show->json('data.tasks'));
    }

    public function test_catalog_checklist_is_editable_by_the_team(): void
    {
        $id = $this->catalogId('Website');
        $this->asRoot()->putJson("/api/v1/admin/service-catalog/{$id}", ['onboarding_checklist' => ['Confirmar o domínio', 'Pedir o logótipo']])->assertOk();
        $this->assertSame(['Confirmar o domínio', 'Pedir o logótipo'], ServiceCatalogItem::find($id)->onboarding_checklist);
        $this->asRoot()->putJson("/api/v1/admin/service-catalog/{$id}", ['onboarding_checklist' => [str_repeat('x', 201)]])->assertStatus(422);
    }

    // ── 8. Segurança das rotas públicas ──────────────────────────────────────

    public function test_public_actions_are_rate_limited(): void
    {
        ['token' => $token] = $this->sentQuote();

        for ($i = 0; $i < 10; $i++) {
            $this->accept($token, [9])->assertStatus(422);
        }
        $this->accept($token, [1, 2])->assertStatus(429);
        $this->assertSame(0, QuoteResponse::count());

        for ($i = 0; $i < 30; $i++) {
            $this->open($token, 'v' . str_pad((string) $i, 20, 'x'));
        }
        $this->open($token, 'limite-xxxxxxxxxxxxxxx')->assertStatus(429);
    }

    public function test_login_gives_the_team_marker_only_to_root(): void
    {
        $this->app['auth']->forgetGuards();
        $rootLogin = $this->postJson('/api/v1/login', ['email' => $this->root->email, 'password' => 'password'])->assertOk();
        $this->assertTrue(TeamDeviceMarker::verify($rootLogin->json('data.team_marker')));
        $adminLogin = $this->postJson('/api/v1/login', ['email' => $this->clientAdmin->email, 'password' => 'password'])->assertOk();
        $this->assertNull($adminLogin->json('data.team_marker'));
        $this->assertFalse(TeamDeviceMarker::verify(null));
        $this->assertFalse(TeamDeviceMarker::verify($this->clientAdmin->id . '.' . now()->timestamp . '.assinatura'));
    }

    // ── 9. Retenção ──────────────────────────────────────────────────────────

    public function test_retention_prunes_opens_after_12_months_and_keeps_counters(): void
    {
        ['id' => $id, 'token' => $token] = $this->sentQuote();
        $this->open($token);
        $this->accept($token, [1, 2])->assertOk();

        $this->travelToLisbon('2027-10-06 10:00:00');
        (new PruneQuoteActivityJob())->handle();

        $this->assertSame(0, QuoteOpen::count());
        $this->assertSame(1, QuoteResponse::count(), 'A aceitação fica 10 anos.');
        $this->assertSame(1, Quote::find($id)->open_count);
    }
}
