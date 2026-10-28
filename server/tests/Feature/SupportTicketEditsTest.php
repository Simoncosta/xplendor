<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SiteChangeQuotedMail;
use App\Mail\SupportInvoiceAvailableMail;
use App\Mail\SupportTicketCreatedMail;
use App\Mail\SupportTicketMessageMail;
use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketRemovedFile;
use App\Models\User;
use App\Services\Storage\CompanyStorageUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedidos de suporte: quem escreveu (o root na sessão como cliente), editar e apagar (só o root, e
 * só o que é dele; o que a cliente escreveu nunca se mexe), a fatura separada do pagamento, o e-mail
 * da fatura, a limpeza das faturas retiradas e os pedidos antigos lidos pela auditoria.
 * Autenticação por token em cada pedido (como no ImpersonationTest), para misturar o root, a
 * cliente e a sessão como cliente no mesmo teste.
 */
class SupportTicketEditsTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private Company $other;
    private User $root;
    private User $otherRoot;
    private User $clientAdmin;
    private User $clientUser;
    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Mail::fake();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $team = Company::create(['nipc' => '500000700', 'fiscal_name' => 'Equipa', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->client = Company::create(['nipc' => '500000701', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000702', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $team->id, 'role' => 'root', 'name' => 'Equipa Um']);
        $this->otherRoot = User::factory()->create(['company_id' => $team->id, 'role' => 'root', 'name' => 'Equipa Dois']);
        $this->clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'name' => 'Cliente Admin']);
        $this->clientUser = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user', 'name' => 'Cliente Utilizador']);
        $this->otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
    }

    // ── Ajudas ──────────────────────────────────────────────────────────────

    private function auth(User $u): array
    {
        $this->app['auth']->forgetGuards(); // o guarda do Sanctum guarda o utilizador entre pedidos

        return ['Authorization' => 'Bearer ' . $u->createToken('login')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** Token da sessão como cliente (o root na conta do admin da cliente), como o endpoint start. */
    private function impersonation(?User $root = null): array
    {
        $root ??= $this->root;
        $this->app['auth']->forgetGuards();
        $token = $this->clientAdmin->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $root->id, 'target_user_id' => $this->clientAdmin->id, 'company_id' => $this->client->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'teste', 'started_at' => now()]);

        return ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json'];
    }

    private function standUrl(string $suffix = ''): string
    {
        return "/api/v1/companies/{$this->client->id}/support-tickets{$suffix}";
    }

    private function adminUrl(SupportTicket $t, string $suffix = ''): string
    {
        return "/api/v1/admin/tickets/{$t->id}{$suffix}";
    }

    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge(['company_id' => $this->client->id, 'user_id' => $this->clientAdmin->id, 'type' => 'site_change',
            'title' => 'Mudar o banner', 'description' => 'Trocar o banner.', 'status' => 'in_review', 'quote_status' => 'approved',
            'estimated_hours' => 2, 'quoted_amount' => 50], $extra));
    }

    private function message(SupportTicket $t, User $author, bool $staff, array $extra = []): SupportTicketMessage
    {
        return SupportTicketMessage::create(array_merge(['support_ticket_id' => $t->id, 'user_id' => $author->id, 'body' => 'Texto original.', 'is_staff' => $staff], $extra));
    }

    private function audit(SupportTicket $t, User $by, string $event, array $new): void
    {
        DB::table('audits')->insert(['user_type' => User::class, 'user_id' => $by->id, 'event' => $event, 'auditable_type' => SupportTicket::class,
            'auditable_id' => $t->id, 'old_values' => '[]', 'new_values' => json_encode($new), 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
    }

    private function pdf(string $name = 'fatura.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4 ' . $name);
    }

    // ── Quem escreveu ───────────────────────────────────────────────────────

    public function test_a_ticket_created_by_root_in_the_client_session_is_the_clients_records_root_and_sends_no_email(): void
    {
        $id = $this->postJson($this->standUrl(), ['type' => 'site_change', 'title' => 'Página nova', 'description' => 'Criar a página.'], $this->impersonation())
            ->assertOk()->json('data.id');
        $t = SupportTicket::findOrFail($id);
        $this->assertSame([$this->client->id, $this->clientAdmin->id, $this->root->id], [(int) $t->company_id, (int) $t->user_id, (int) $t->impersonator_user_id]);
        Mail::assertNotQueued(SupportTicketCreatedMail::class);

        // A cliente, na conta dela, continua a avisar o Simon.
        $this->postJson($this->standUrl(), ['type' => 'idea', 'title' => 'Ideia', 'description' => 'Uma ideia.'], $this->auth($this->clientUser))->assertOk();
        Mail::assertQueued(SupportTicketCreatedMail::class, 1);
        $this->assertNull(SupportTicket::latest('id')->first()->impersonator_user_id);
    }

    public function test_a_message_written_by_root_in_the_client_session_counts_as_the_team_and_sends_no_email(): void
    {
        $t = $this->ticket();
        $this->postJson($this->standUrl("/{$t->id}/messages"), ['body' => 'Já tratámos disto.'], $this->impersonation())->assertOk();
        $m = $t->messages()->latest('id')->first();
        $this->assertTrue($m->is_staff);
        $this->assertSame([$this->clientAdmin->id, $this->root->id], [(int) $m->user_id, (int) $m->impersonator_user_id]);
        Mail::assertNotQueued(SupportTicketMessageMail::class);

        // A cliente vê-a como da equipa XPLENDOR, com o nome de quem a escreveu.
        $msg = collect($this->getJson($this->standUrl("/{$t->id}"), $this->auth($this->clientUser))->assertOk()->json('data.messages'))->firstWhere('id', $m->id);
        $this->assertSame([true, 'Equipa Um'], [$msg['is_staff'], $msg['author_name']]);
        $this->assertArrayNotHasKey('team', $msg);

        // A mensagem da própria cliente continua a avisar o Simon.
        $this->postJson($this->standUrl("/{$t->id}/messages"), ['body' => 'Obrigada.'], $this->auth($this->clientUser))->assertOk();
        Mail::assertQueued(SupportTicketMessageMail::class, 1);
    }

    // ── Editar e apagar mensagens ───────────────────────────────────────────

    public function test_root_edits_and_deletes_his_messages_and_the_client_sees_the_marks(): void
    {
        $t = $this->ticket();
        $mine = $this->message($t, $this->root, true);

        $this->patchJson($this->adminUrl($t, "/messages/{$mine->id}"), ['body' => 'Texto corrigido.'], $this->auth($this->root))->assertOk();
        $mine->refresh();
        $this->assertSame('Texto corrigido.', $mine->body);
        $this->assertNotNull($mine->edited_at);

        $client = collect($this->getJson($this->standUrl("/{$t->id}"), $this->auth($this->clientUser))->json('data.messages'))->firstWhere('id', $mine->id);
        $this->assertSame('Texto corrigido.', $client['body']);
        $this->assertNotNull($client['edited_at']);
        $this->assertArrayNotHasKey('team', $client); // o texto anterior só a equipa o vê

        $admin = collect($this->getJson($this->adminUrl($t), $this->auth($this->root))->json('data.messages'))->firstWhere('id', $mine->id);
        $this->assertSame('Texto original.', $admin['team']['versions'][0]['body']);
        $this->assertTrue($admin['team']['can_edit']);

        $this->deleteJson($this->adminUrl($t, "/messages/{$mine->id}"), [], $this->auth($this->root))->assertOk();
        $client = collect($this->getJson($this->standUrl("/{$t->id}"), $this->auth($this->clientUser))->json('data.messages'))->firstWhere('id', $mine->id);
        $this->assertSame([null, true], [$client['body'], $client['deleted']]);
        $this->assertNotNull($client['deleted_at']);
        $admin = collect($this->getJson($this->adminUrl($t), $this->auth($this->root))->json('data.messages'))->firstWhere('id', $mine->id);
        $this->assertSame(['Texto corrigido.', false], [$admin['body'], $admin['team']['can_edit']]);
        $this->patchJson($this->adminUrl($t, "/messages/{$mine->id}"), ['body' => 'Outra vez.'], $this->auth($this->root))->assertStatus(422);

        Mail::assertNothingQueued(); // editar e apagar nunca reenvia e-mails
    }

    public function test_what_the_client_wrote_never_changes_and_nobody_else_edits(): void
    {
        $t = $this->ticket();
        $clientMsg = $this->message($t, $this->clientUser, false);
        $legacyImpersonated = $this->message($t, $this->clientAdmin, false); // escrita antes de se gravar quem estava
        $otherRootMsg = $this->message($t, $this->otherRoot, true);
        $byRootInSession = $this->message($t, $this->clientAdmin, true, ['impersonator_user_id' => $this->root->id]);

        foreach ([$clientMsg, $legacyImpersonated, $otherRootMsg] as $m) {
            $this->patchJson($this->adminUrl($t, "/messages/{$m->id}"), ['body' => 'Mexido.'], $this->auth($this->root))->assertForbidden();
            $this->deleteJson($this->adminUrl($t, "/messages/{$m->id}"), [], $this->auth($this->root))->assertForbidden();
            $this->assertSame(['Texto original.', null], [$m->fresh()->body, $m->fresh()->deleted_at]);
        }
        // A que o root escreveu na sessão como cliente é dele.
        $this->patchJson($this->adminUrl($t, "/messages/{$byRootInSession->id}"), ['body' => 'Corrigido.'], $this->auth($this->root))->assertOk();

        // A cliente, outra empresa e a sessão como cliente não passam do /admin.
        $mine = $this->message($t, $this->root, true);
        foreach ([$this->auth($this->clientAdmin), $this->auth($this->otherAdmin), $this->impersonation()] as $headers) {
            $this->patchJson($this->adminUrl($t, "/messages/{$mine->id}"), ['body' => 'Mexido.'], $headers)->assertForbidden();
            $this->deleteJson($this->adminUrl($t, "/messages/{$mine->id}"), [], $headers)->assertForbidden();
            $this->deleteJson($this->adminUrl($t), [], $headers)->assertForbidden();
            $this->postJson($this->adminUrl($t, '/invoice'), [], $headers)->assertForbidden();
        }
        $this->assertSame('Texto original.', $mine->fresh()->body);
    }

    // ── Anexo e ticket ──────────────────────────────────────────────────────

    public function test_root_removes_the_attachment_of_his_ticket_and_the_file_goes_at_once(): void
    {
        $id = $this->post($this->standUrl(), ['type' => 'bug', 'title' => 'Erro', 'description' => 'Rebenta.',
            'screenshot' => UploadedFile::fake()->image('ecra.png', 200, 120)], $this->impersonation())->assertOk()->json('data.id');
        $t = SupportTicket::findOrFail($id);
        $disk = substr($t->screenshot_path, strlen('/storage/'));
        Storage::disk('public')->assertExists($disk);

        $this->deleteJson($this->adminUrl($t, '/screenshot'), [], $this->auth($this->root))->assertOk();
        Storage::disk('public')->assertMissing($disk);
        $this->assertNull($t->fresh()->screenshot_path);
        $notice = $t->messages()->latest('id')->first();
        $this->assertTrue($notice->is_staff);
        $this->assertStringContainsString('retirou o anexo', $notice->body);

        // O anexo de um pedido da cliente não se mexe.
        $clientTicket = $this->ticket(['type' => 'bug', 'quote_status' => null, 'screenshot_path' => '/storage/company_1/tickets/x.webp']);
        $this->deleteJson($this->adminUrl($clientTicket, '/screenshot'), [], $this->auth($this->root))->assertForbidden();
    }

    public function test_root_deletes_a_ticket_he_created_by_mistake_and_it_leaves_the_lists(): void
    {
        $t = $this->ticket(['quote_status' => 'awaiting_quote', 'impersonator_user_id' => $this->root->id]);
        $this->message($t, $this->root, true);
        $this->deleteJson($this->adminUrl($t), [], $this->auth($this->otherRoot))->assertForbidden(); // não é dele

        $this->deleteJson($this->adminUrl($t), [], $this->auth($this->root))->assertOk()->assertJsonPath('data.team.deleted_by_name', 'Equipa Um');
        $this->assertSoftDeleted($t);
        $this->assertSame($this->root->id, (int) SupportTicket::withTrashed()->find($t->id)->deleted_by_user_id);

        $this->assertNotContains($t->id, array_column($this->getJson($this->standUrl(), $this->auth($this->clientUser))->json('data'), 'id'));
        $this->getJson($this->standUrl("/{$t->id}"), $this->auth($this->clientUser))->assertNotFound();
        $this->assertNotContains($t->id, array_column($this->getJson('/api/v1/admin/tickets', $this->auth($this->root))->json('data'), 'id'));
        $this->assertContains($t->id, array_column($this->getJson('/api/v1/admin/tickets?deleted=1', $this->auth($this->root))->json('data'), 'id'));
        $this->getJson($this->adminUrl($t), $this->auth($this->root))->assertOk();
    }

    public function test_a_ticket_with_client_messages_or_approved_work_or_written_by_the_client_does_not_delete(): void
    {
        $withClientMessage = $this->ticket(['quote_status' => 'awaiting_quote', 'impersonator_user_id' => $this->root->id]);
        $this->message($withClientMessage, $this->clientUser, false);
        $this->deleteJson($this->adminUrl($withClientMessage), [], $this->auth($this->root))->assertStatus(422);

        foreach (['approved', 'completed'] as $status) {
            $approved = $this->ticket(['quote_status' => $status, 'impersonator_user_id' => $this->root->id]);
            $this->deleteJson($this->adminUrl($approved), [], $this->auth($this->root))->assertStatus(422);
        }
        $paid = $this->ticket(['quote_status' => 'quoted', 'paid_at' => now(), 'impersonator_user_id' => $this->root->id]);
        $this->deleteJson($this->adminUrl($paid), [], $this->auth($this->root))->assertStatus(422);

        $byClient = $this->ticket(['quote_status' => 'awaiting_quote']);
        $this->deleteJson($this->adminUrl($byClient), [], $this->auth($this->root))->assertForbidden();
        $this->assertSame(0, SupportTicket::onlyTrashed()->count());
    }

    public function test_old_tickets_are_read_by_the_created_audit(): void
    {
        $old = $this->ticket(['quote_status' => 'awaiting_quote']); // user_id da cliente, sem impersonator (antes da coluna)
        $this->audit($old, $this->otherRoot, 'created', ['title' => $old->title]);
        $this->deleteJson($this->adminUrl($old), [], $this->auth($this->root))->assertForbidden();

        $mine = $this->ticket(['quote_status' => 'awaiting_quote']);
        $this->audit($mine, $this->root, 'created', ['title' => $mine->title]);
        $this->deleteJson($this->adminUrl($mine), [], $this->auth($this->root))->assertOk();
        $this->assertSoftDeleted($mine);
    }

    // ── Fatura separada do pagamento ───────────────────────────────────────

    public function test_the_invoice_comes_before_the_payment_and_emails_who_sees_the_billing(): void
    {
        $t = $this->ticket();
        $data = $this->post($this->adminUrl($t, '/invoice'), ['invoice' => $this->pdf()], $this->auth($this->root))->assertOk()->json('data');
        $this->assertSame(['approved', 'invoiced', null], [$data['quote_status'], $data['payment_status'], $data['paid_at']]);
        $this->assertNotNull($data['invoice_url']);
        $fresh = $t->fresh();
        $this->assertSame($this->root->id, (int) $fresh->invoice_by_user_id);
        $this->assertNotNull($fresh->invoiced_at);
        Storage::disk('local')->assertExists($fresh->invoice_path);

        // Só a quem vê a faturação da XPLENDOR (o admin da cliente, não o utilizador), sem o PDF.
        Mail::assertQueued(SupportInvoiceAvailableMail::class, function (SupportInvoiceAvailableMail $m) use ($t) {
            return $m->hasTo($this->clientAdmin->email) && ! $m->hasTo($this->clientUser->email)
                && $m->envelope()->subject === 'Fatura disponível: Mudar o banner'
                && str_ends_with($m->ticketUrl, "/support/{$t->id}") && $m->attachments === [] && $m->rawAttachments === [];
        });
        // O utilizador da cliente vê o estado, mas não a fatura.
        $this->getJson($this->standUrl("/{$t->id}"), $this->auth($this->clientUser))->assertJsonPath('data.invoice_url', null)->assertJsonPath('data.payment_status', null);
        $this->getJson($this->standUrl("/{$t->id}"), $this->auth($this->clientAdmin))->assertJsonPath('data.payment_status', 'invoiced');

        // Antes da aprovação, não há fatura.
        $quoted = $this->ticket(['quote_status' => 'quoted']);
        $this->post($this->adminUrl($quoted, '/invoice'), ['invoice' => $this->pdf()], $this->auth($this->root))->assertStatus(422);
    }

    public function test_replacing_or_removing_the_invoice_trashes_the_old_file_and_only_its_author_does_it(): void
    {
        $t = $this->ticket();
        $this->post($this->adminUrl($t, '/invoice'), ['invoice' => $this->pdf('a.pdf')], $this->auth($this->root))->assertOk();
        $first = $t->fresh()->invoice_path;

        $this->post($this->adminUrl($t, '/invoice'), ['invoice' => $this->pdf('b.pdf')], $this->auth($this->otherRoot))->assertForbidden();
        $this->deleteJson($this->adminUrl($t, '/invoice'), [], $this->auth($this->otherRoot))->assertForbidden();

        $this->post($this->adminUrl($t, '/invoice'), ['invoice' => $this->pdf('b.pdf')], $this->auth($this->root))->assertOk();
        $second = $t->fresh()->invoice_path;
        $this->assertNotSame($first, $second);
        $trash = SupportTicketRemovedFile::where('support_ticket_id', $t->id)->get();
        $this->assertSame([$first], $trash->pluck('path')->all());
        Storage::disk('local')->assertExists($first); // até ao fim do prazo

        Mail::fake(); // a partir daqui, nenhum e-mail novo
        $this->deleteJson($this->adminUrl($t, '/invoice'), [], $this->auth($this->root))->assertOk()->assertJsonPath('data.invoice_url', null);
        $this->assertSame([$first, $second], SupportTicketRemovedFile::where('support_ticket_id', $t->id)->orderBy('id')->pluck('path')->all());
        $this->assertNull($t->fresh()->invoiced_at);
        $this->assertStringContainsString('retirou a fatura', $t->messages()->latest('id')->first()->body);
        Mail::assertNothingQueued();
    }

    public function test_old_invoices_are_read_by_the_audit(): void
    {
        $mine = $this->ticket(['invoice_path' => 'support-invoices/company_1/antiga.pdf']);
        $this->audit($mine, $this->root, 'updated', ['quote_status' => 'paid', 'invoice_path' => 'support-invoices/company_1/antiga.pdf']);
        $this->deleteJson($this->adminUrl($mine, '/invoice'), [], $this->auth($this->root))->assertOk();

        $theirs = $this->ticket(['invoice_path' => 'support-invoices/company_1/outra.pdf']);
        $this->audit($theirs, $this->otherRoot, 'updated', ['invoice_path' => 'support-invoices/company_1/outra.pdf']);
        $this->deleteJson($this->adminUrl($theirs, '/invoice'), [], $this->auth($this->root))->assertForbidden();
    }

    public function test_payment_without_invoice_completion_before_payment_and_undoing_the_payment(): void
    {
        config(['audit.console' => true]);
        $t = $this->ticket();

        $this->patchJson($this->adminUrl($t, '/complete'), [], $this->auth($this->root))->assertOk()
            ->assertJsonPath('data.quote_status', 'completed')->assertJsonPath('data.payment_status', 'pending');
        $this->post($this->adminUrl($t, '/mark-paid'), [], $this->auth($this->root))->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.invoice_url', null);
        $this->assertSame($this->root->id, (int) $t->fresh()->paid_by_user_id);
        $this->post($this->adminUrl($t, '/mark-paid'), [], $this->auth($this->root))->assertStatus(422);

        $this->deleteJson($this->adminUrl($t, '/mark-paid'), [], $this->auth($this->root))->assertOk()->assertJsonPath('data.payment_status', 'pending');
        $this->assertNull($t->fresh()->paid_at);
        $undo = DB::table('audits')->where('auditable_type', SupportTicket::class)->where('auditable_id', $t->id)->where('event', 'updated')
            ->orderByDesc('id')->first();
        $this->assertSame($this->root->id, (int) $undo->user_id);
        $this->assertArrayHasKey('paid_at', json_decode($undo->old_values, true));
        $this->deleteJson($this->adminUrl($t, '/mark-paid'), [], $this->auth($this->root))->assertStatus(422);
        Mail::assertNotQueued(SupportInvoiceAvailableMail::class); // sem fatura, sem e-mail
    }

    public function test_the_type_does_not_change_with_an_invoice_or_a_payment(): void
    {
        $paid = $this->ticket(['quote_status' => 'quoted', 'paid_at' => now()]);
        $this->patchJson($this->adminUrl($paid, '/type'), ['type' => 'idea', 'confirm_reset' => true], $this->auth($this->root))->assertStatus(422);
        $invoiced = $this->ticket(['quote_status' => 'quoted', 'invoice_path' => 'support-invoices/x.pdf']);
        $this->patchJson($this->adminUrl($invoiced, '/type'), ['type' => 'idea', 'confirm_reset' => true], $this->auth($this->root))->assertStatus(422);
        $this->assertSame(['site_change', 'site_change'], [$paid->fresh()->type, $invoiced->fresh()->type]);
    }

    public function test_the_quote_email_goes_to_who_sees_the_billing(): void
    {
        $t = $this->ticket(['quote_status' => 'awaiting_quote', 'user_id' => $this->clientUser->id, 'estimated_hours' => null, 'quoted_amount' => null]);
        $this->patchJson($this->adminUrl($t, '/quote'), ['estimated_hours' => 2], $this->auth($this->root))->assertOk();
        Mail::assertQueued(SiteChangeQuotedMail::class, fn (SiteChangeQuotedMail $m) => $m->hasTo($this->clientAdmin->email) && ! $m->hasTo($this->clientUser->email));
    }

    // ── Limpeza das faturas retiradas ───────────────────────────────────────

    public function test_removed_invoices_are_deleted_after_30_days_and_the_company_space_is_corrected(): void
    {
        Storage::disk('local')->put('support-invoices/velha.pdf', str_repeat('v', 700));
        Storage::disk('local')->put('support-invoices/recente.pdf', str_repeat('r', 300));
        $t = $this->ticket();
        $old = SupportTicketRemovedFile::create(['company_id' => $this->client->id, 'support_ticket_id' => $t->id, 'kind' => 'invoice',
            'path' => 'support-invoices/velha.pdf', 'size_bytes' => 700, 'removed_by_user_id' => $this->root->id, 'removed_at' => now()->subDays(31)]);
        $recent = SupportTicketRemovedFile::create(['company_id' => $this->client->id, 'support_ticket_id' => $t->id, 'kind' => 'invoice',
            'path' => 'support-invoices/recente.pdf', 'size_bytes' => 300, 'removed_by_user_id' => $this->root->id, 'removed_at' => now()->subDays(29)]);
        $this->assertSame(1000, CompanyStorageUsage::forCompany($this->client->id)['bytes']['faturas_tickets']);

        $this->artisan('support:purge-removed-files')->assertSuccessful();

        Storage::disk('local')->assertMissing('support-invoices/velha.pdf');
        Storage::disk('local')->assertExists('support-invoices/recente.pdf');
        $this->assertNotNull($old->fresh()->purged_at);
        $this->assertNull($recent->fresh()->purged_at);
        $this->assertSame(300, CompanyStorageUsage::forCompany($this->client->id)['bytes']['faturas_tickets']);
    }
}
