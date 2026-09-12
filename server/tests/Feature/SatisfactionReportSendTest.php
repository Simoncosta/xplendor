<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SatisfactionReportMail;
use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\SatisfactionReport;
use App\Models\User;
use App\Support\SatisfactionMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DMS Pós-venda (Incremento 5) — envio do link ao cliente (WhatsApp/email).
 * Cobre a mensagem (com/sem nome), o email ao comprador (queue), guardas de
 * email/consentimento, registo de envio e isolamento.
 */
class SatisfactionReportSendTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000097', 'fiscal_name' => 'Stand Envio Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function makeSale(array $overrides = []): CarSale
    {
        $car = Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold']);
        return CarSale::create(array_merge([
            'car_id' => $car->id, 'company_id' => $this->company->id,
            'sale_price' => 20000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45',
            'sale_channel' => 'in_person', 'sold_at' => now(),
            'buyer_name' => 'Ana Cliente', 'buyer_email' => 'ana@cliente.pt', 'buyer_phone' => '910000000',
            'contact_consent' => true,
        ], $overrides));
    }

    // ---------- mensagem (fonte única) ----------
    public function test_message_builds_with_and_without_name(): void
    {
        $this->assertStringStartsWith('Olá Ana!', SatisfactionMessage::build('Ana', 'https://x/r/t'));
        $this->assertStringStartsWith('Olá!', SatisfactionMessage::build(null, 'https://x/r/t'));
        $this->assertStringNotContainsString('Olá !', SatisfactionMessage::build('', 'https://x/r/t'));
        $this->assertStringContainsString('https://x/r/t', SatisfactionMessage::build('Ana', 'https://x/r/t'));
    }

    public function test_store_returns_whatsapp_message_and_contact(): void
    {
        $sale = $this->makeSale();
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$sale->car_id}/satisfaction-report");
        $res->assertStatus(200)
            ->assertJsonPath('data.customer.phone', '910000000')
            ->assertJsonPath('data.customer.email', 'ana@cliente.pt');
        $this->assertStringContainsString('Olá Ana Cliente!', $res->json('data.whatsapp_message'));
        $this->assertStringContainsString('/r/' . $res->json('data.public_token'), $res->json('data.whatsapp_message'));
    }

    // ---------- email ----------
    public function test_send_email_queues_mail_to_customer_and_records_sent(): void
    {
        Mail::fake();
        $sale = $this->makeSale();

        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$sale->car_id}/satisfaction-report/send-email");
        $res->assertStatus(200)->assertJsonPath('data.sent_channel', 'email');

        Mail::assertQueued(SatisfactionReportMail::class, fn ($mail) => $mail->hasTo('ana@cliente.pt'));
        $report = SatisfactionReport::where('car_sale_id', $sale->id)->first();
        $this->assertNotNull($report->sent_at);
        $this->assertSame('email', $report->sent_channel);
    }

    public function test_send_email_requires_email(): void
    {
        Mail::fake();
        $sale = $this->makeSale(['buyer_email' => null]);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$sale->car_id}/satisfaction-report/send-email")
            ->assertStatus(422);
        Mail::assertNothingQueued();
    }

    public function test_send_email_blocked_without_consent(): void
    {
        Mail::fake();
        // Email presente mas consentimento negativo → bloqueia (defesa RGPD).
        $sale = $this->makeSale(['contact_consent' => false]);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$sale->car_id}/satisfaction-report/send-email")
            ->assertStatus(422);
        Mail::assertNothingQueued();
    }

    public function test_send_email_tenant_isolation(): void
    {
        Mail::fake();
        $sale = $this->makeSale();
        $other = Company::create(['nipc' => '500000093', 'fiscal_name' => 'Outra Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$sale->car_id}/satisfaction-report/send-email")
            ->assertStatus(403);
        Mail::assertNothingQueued();
    }

    // ---------- registo (WhatsApp) ----------
    public function test_mark_sent_records_channel(): void
    {
        $sale = $this->makeSale();
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$sale->car_id}/satisfaction-report/mark-sent", ['channel' => 'whatsapp']);
        $res->assertStatus(200)->assertJsonPath('data.sent_channel', 'whatsapp');
        $this->assertSame('whatsapp', SatisfactionReport::where('car_sale_id', $sale->id)->first()->sent_channel);
    }
}
