<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SupportTicketCreatedMail;
use App\Mail\SupportTicketMessageMail;
use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DMS — Tickets de suporte, lado STAND. Cobre criação (com/sem print),
 * tenant isolation, thread, re-encode do print e que o stand NÃO muda estado.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Mail::fake();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000400', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000401', 'fiscal_name' => 'Stand B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function url(?int $id = null, string $suffix = ''): string
    {
        $base = "/api/v1/companies/{$this->company->id}/support-tickets";
        return $id ? "{$base}/{$id}{$suffix}" : $base;
    }

    public function test_creates_ticket_without_screenshot(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'type' => 'idea', 'title' => 'Filtro por cor', 'description' => 'Seria útil filtrar por cor.',
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.type', 'idea')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.author_name', $this->user->name);
        $this->assertNull($res->json('data.screenshot_url'));
    }

    public function test_bug_ticket_stores_and_reencodes_screenshot(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->post($this->url(), [
            'type' => 'bug', 'title' => 'Erro ao gravar', 'description' => 'Rebenta ao gravar.',
            'screenshot' => UploadedFile::fake()->image('erro.png', 1200, 800),
        ], ['Accept' => 'application/json']);
        $res->assertStatus(200);

        $ticket = SupportTicket::first();
        $this->assertNotNull($ticket->screenshot_path);
        $this->assertStringEndsWith('.webp', $ticket->screenshot_path);  // re-encode
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage', '', $ticket->screenshot_path), '/'));
    }

    public function test_rejects_non_image_screenshot(): void
    {
        $this->actingAs($this->user, 'sanctum')->post($this->url(), [
            'type' => 'bug', 'title' => 'x', 'description' => 'y',
            'screenshot' => UploadedFile::fake()->create('mal.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_validates_type(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'type' => 'wtf', 'title' => 'x', 'description' => 'y',
        ])->assertStatus(422);
    }

    public function test_lists_only_own_company_tickets(): void
    {
        SupportTicket::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'idea', 'title' => 'Meu', 'description' => 'd']);
        $bUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        SupportTicket::create(['company_id' => $this->other->id, 'user_id' => $bUser->id, 'type' => 'bug', 'title' => 'Alheio', 'description' => 'd']);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url());
        $titles = collect($res->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Meu'));
        $this->assertFalse($titles->contains('Alheio'));
    }

    public function test_cannot_view_other_company_ticket(): void
    {
        $bUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $alien = SupportTicket::create(['company_id' => $this->other->id, 'user_id' => $bUser->id, 'type' => 'bug', 'title' => 'Alheio', 'description' => 'd']);

        // Rota da empresa A, ticket da B → 404 (findScoped por company).
        $this->actingAs($this->user, 'sanctum')->getJson($this->url($alien->id))->assertStatus(404);
    }

    public function test_stand_message_is_not_staff_and_thread_shows(): void
    {
        $ticket = SupportTicket::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'idea', 'title' => 'T', 'description' => 'd']);

        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url($ticket->id, '/messages'), ['body' => 'Mais um detalhe.']);
        $res->assertStatus(200);
        $msgs = $res->json('data.messages');
        $this->assertCount(1, $msgs);
        $this->assertSame('Mais um detalhe.', $msgs[0]['body']);
        $this->assertFalse($msgs[0]['is_staff']); // stand admin não é staff
    }

    public function test_opening_ticket_emails_the_super_admin(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'type' => 'improvement', 'title' => 'Exportar CSV', 'description' => 'Poder exportar.',
        ])->assertStatus(200);

        Mail::assertQueued(SupportTicketCreatedMail::class, fn ($m) => $m->hasTo('simonfrtd@gmail.com'));
    }

    public function test_stand_message_emails_the_super_admin(): void
    {
        $ticket = SupportTicket::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'idea', 'title' => 'T', 'description' => 'd']);
        Mail::fake(); // limpa o email do create acima (não interessa aqui)

        $this->actingAs($this->user, 'sanctum')->postJson($this->url($ticket->id, '/messages'), ['body' => 'Um detalhe.'])->assertStatus(200);

        Mail::assertQueued(SupportTicketMessageMail::class, fn ($m) => $m->hasTo('simonfrtd@gmail.com'));
    }

    public function test_staff_message_does_not_email(): void
    {
        $root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $ticket = SupportTicket::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'bug', 'title' => 'T', 'description' => 'd']);
        Mail::fake();

        // Super-admin responde → is_staff=true → NENHUM email.
        $this->actingAs($root, 'sanctum')->postJson($this->url($ticket->id, '/messages'), ['body' => 'Estamos a ver.'])->assertStatus(200);

        Mail::assertNothingQueued();
    }

    public function test_email_failure_does_not_break_ticket_creation(): void
    {
        // Simula SMTP/queue a rebentar — o ticket grava na mesma.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'type' => 'idea', 'title' => 'Resiliente', 'description' => 'Deve gravar.',
        ])->assertStatus(200);

        $this->assertDatabaseHas('support_tickets', ['title' => 'Resiliente', 'company_id' => $this->company->id]);
    }

    public function test_stand_cannot_change_status(): void
    {
        // Não existe endpoint de estado no lado stand — a rota PATCH do ticket
        // simplesmente não existe (405/404). O estado só muda no /admin.
        $ticket = SupportTicket::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'bug', 'title' => 'T', 'description' => 'd']);
        $res = $this->actingAs($this->user, 'sanctum')->patchJson($this->url($ticket->id), ['status' => 'resolved']);
        $this->assertContains($res->status(), [404, 405]);
        $this->assertSame('open', $ticket->fresh()->status);
    }
}
