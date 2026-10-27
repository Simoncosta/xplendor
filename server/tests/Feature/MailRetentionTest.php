<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Mailbox\FakeMailboxImap;
use App\Settings\Settings;
use App\Settings\SettingsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E-mail 8 · A retenção das mensagens guardadas: apaga só o que passou do prazo, com o prazo de cada
 * empresa (12 meses por omissão, de 3 a 24); não toca noutras empresas nem no servidor de e-mail;
 * regista só quantas apagou; o prazo muda-o só o administrador da própria empresa (ou o root), em
 * JSON, com histórico; e não aparece nas Configurações da Restauração.
 */
class MailRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $adminA;
    private Mailbox $mailboxA;
    private Mailbox $mailboxB;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        FakeMailboxImap::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500060001', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500060002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
        $this->mailboxA = $this->mailbox($this->adminA);
        $this->mailboxB = $this->mailbox($adminB);
    }

    private function mailbox(User $user): Mailbox
    {
        return Mailbox::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'account_type' => 'cpanel', 'email' => "pessoa{$user->id}@exemplo.pt",
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => "pessoa{$user->id}@exemplo.pt", 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE]);
    }

    /** Uma mensagem guardada com a data de envio, de receção ou só a de quando foi guardada. */
    private function message(Mailbox $mailbox, string $subject, array $dates): MailMessage
    {
        $customer = Customer::create(['company_id' => $mailbox->company_id, 'name' => 'Cliente ' . $subject]);
        $m = MailMessage::create(['company_id' => $mailbox->company_id, 'mailbox_id' => $mailbox->id, 'user_id' => $mailbox->user_id,
            'direction' => isset($dates['received_at']) ? MailMessage::IN : MailMessage::OUT, 'message_id' => '<' . md5($subject . $mailbox->id) . '@exemplo.pt>',
            'from_address' => 'a@exemplo.invalid', 'to_address' => 'b@exemplo.invalid', 'subject' => $subject, 'body_text' => 'Conteúdo pessoal',
            'status' => isset($dates['received_at']) ? MailMessage::STATUS_RECEIVED : MailMessage::STATUS_SENT, 'customer_id' => $customer->id,
            'sent_at' => $dates['sent_at'] ?? null, 'received_at' => $dates['received_at'] ?? null]);
        if (isset($dates['created_at'])) {
            $m->forceFill(['created_at' => $dates['created_at']])->saveQuietly();
        }

        return $m;
    }

    public function test_it_deletes_only_what_passed_the_period_of_each_company_and_logs_only_counts(): void
    {
        Log::spy();
        // Empresa A: 12 meses (omissão). Empresa B: 3 meses.
        Settings::setCompany($this->b->id, ['mail.retention_months' => 3], null);
        $this->message($this->mailboxA, 'A antiga enviada', ['sent_at' => now()->subMonths(13)]);
        $this->message($this->mailboxA, 'A antiga recebida', ['received_at' => now()->subMonths(12)->subDay()]);
        $this->message($this->mailboxA, 'A antiga sem data', ['created_at' => now()->subMonths(14)]);
        $this->message($this->mailboxA, 'A dentro do prazo', ['sent_at' => now()->subMonths(11)]);
        $this->message($this->mailboxA, 'A recebida há pouco, guardada há muito', ['received_at' => now()->subMonth(), 'created_at' => now()->subMonths(20)]);
        $this->message($this->mailboxB, 'B com 4 meses', ['sent_at' => now()->subMonths(4)]);
        $this->message($this->mailboxB, 'B com 2 meses', ['sent_at' => now()->subMonths(2)]);

        $this->artisan('mailbox:purge-retention')->expectsOutput('Mensagens apagadas: 4 (2 empresas)')->assertSuccessful();

        $this->assertSame(['A dentro do prazo', 'A recebida há pouco, guardada há muito'], MailMessage::where('company_id', $this->a->id)->orderBy('subject')->pluck('subject')->all());
        $this->assertSame(['B com 2 meses'], MailMessage::where('company_id', $this->b->id)->pluck('subject')->all());
        // Só cópias da XPLENDOR: nenhuma ligação ao servidor de e-mail, nem a outros serviços.
        $this->assertSame([], FakeMailboxImap::$calls);
        // O registo diz quantas e de que empresa, nunca o conteúdo.
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Retenção') && $ctx === ['company_id' => $this->a->id, 'deleted' => 3, 'months' => 12])->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Retenção') && $ctx === ['company_id' => $this->b->id, 'deleted' => 1, 'months' => 3])->once();
        // Correr outra vez não apaga mais nada.
        $this->artisan('mailbox:purge-retention')->expectsOutput('Mensagens apagadas: 0 (0 empresas)')->assertSuccessful();
    }

    public function test_the_period_changes_by_the_company_admin_in_json_with_history_and_limits_3_to_24(): void
    {
        Sanctum::actingAs($this->adminA);
        $url = "/api/v1/companies/{$this->a->id}/mail-settings";
        $this->getJson($url)->assertOk()->assertJsonPath('data.retention_months', 12)->assertJsonPath('data.min', 3)->assertJsonPath('data.max', 24)
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.history', []);
        foreach ([2, 25, 0, '6.5', 'doze', null] as $bad) {
            $this->putJson($url, ['retention_months' => $bad])->assertStatus(422);
        }
        $this->putJson($url, ['retention_months' => 3])->assertOk()->assertJsonPath('data.retention_months', 3)->assertJsonPath('message', 'Prazo de retenção guardado.');
        $this->putJson($url, ['retention_months' => 24])->assertOk()->assertJsonPath('data.retention_months', 24)
            ->assertJsonPath('data.history.0.before', 3)->assertJsonPath('data.history.0.after', 24);
        $this->putJson($url, ['retention_months' => 24])->assertOk()->assertJsonPath('message', 'Nada mudou.');
        // A outra empresa continua com 12.
        $this->assertSame(12, (int) Settings::company($this->b->id, 'mail.retention_months'));
    }

    public function test_only_the_admin_of_the_company_or_root_changes_it(): void
    {
        $url = "/api/v1/companies/{$this->a->id}/mail-settings";
        // Um utilizador da empresa sem perfil de administrador: vê, mas não muda.
        $user = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        Sanctum::actingAs($user);
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->putJson($url, ['retention_months' => 6])->assertStatus(403);

        // O administrador da agência gestora: não muda (decisão do cliente).
        $agency = Company::create(['nipc' => '500060003', 'fiscal_name' => 'Agência Lda', 'plan_id' => $this->a->plan_id, 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->a->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->a->id, 'team_scope' => 'all', 'requested_at' => now()]);
        Sanctum::actingAs(User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']));
        $this->putJson($url, ['retention_months' => 6])->assertStatus(403);

        // Outra empresa: 403.
        Sanctum::actingAs(User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']));
        $this->getJson($url)->assertStatus(403);
        $this->putJson($url, ['retention_months' => 6])->assertStatus(403);

        // O root muda.
        Sanctum::actingAs(User::factory()->create(['company_id' => $this->b->id, 'role' => 'root']));
        $this->putJson($url, ['retention_months' => 6])->assertOk()->assertJsonPath('data.retention_months', 6);
        $this->assertSame(12, (int) Settings::company($this->b->id, 'mail.retention_months')); // só na empresa A
    }

    public function test_the_mail_setting_is_not_in_the_restaurant_settings(): void
    {
        $this->assertNotContains('mail.retention_months', SettingsCatalog::keys(SettingsCatalog::COMPANY));
        $this->assertSame(['mail.retention_months'], SettingsCatalog::keys(SettingsCatalog::COMPANY_MAIL));
    }
}
