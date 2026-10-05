<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Quote;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Compatibilidade do editor de orçamentos com os campos novos das linhas ("Opcional" e
 * "Do pacote"): o pedido exato do editor anterior (sem os campos), pedidos multipart
 * (booleanos como texto, como o axios envia nos POST), null valem false, e as mensagens
 * de erro das linhas são legíveis ("Linha 1: …"), nunca "lines.0.is_optional".
 */
class QuoteRequestCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $team = Company::create(['nipc' => '500070001', 'fiscal_name' => 'Xplendor', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $team->id, 'role' => 'root']);
    }

    private function api(): self
    {
        return $this->actingAs($this->root, 'sanctum');
    }

    /** O pedido EXATO do editor anterior (toPayload antes da Parte C): sem is_optional nem in_package. */
    private function oldEditorPayload(): array
    {
        return [
            'new_customer' => ['name' => 'Pastelaria Doce Ribeira', 'phone' => '+351 912 345 678', 'email' => 'ana@exemplo.pt'],
            'company_id' => null,
            'title' => 'Presença digital',
            'intro' => '',
            'notes' => '',
            'lines' => [
                ['catalog_item_id' => ServiceCatalogItem::where('name', 'Social Media')->value('id'), 'name' => 'Social Media', 'description' => '2 publicações por semana.',
                    'unit' => 'month', 'billing_type' => 'monthly', 'quantity' => 1, 'unit_price' => 200, 'discount_type' => null, 'discount_value' => null],
                ['catalog_item_id' => null, 'name' => 'Website', 'description' => null, 'unit' => 'hour', 'billing_type' => 'one_off',
                    'quantity' => 12, 'unit_price' => 25, 'discount_type' => 'percent', 'discount_value' => 10],
            ],
            'global_discount_type' => 'percent', 'global_discount_value' => 10, 'global_discount_target' => null, 'global_discount_label' => 'Desconto de pacote',
            'minimum_contract_months' => 3, 'monthly_start_terms' => '', 'payment_terms_monthly' => '', 'payment_terms_one_off' => '50% na adjudicação e 50% na entrega.',
        ];
    }

    public function test_old_editor_request_saves_previews_and_sends(): void
    {
        $quote = $this->api()->postJson('/api/v1/admin/quotes', $this->oldEditorPayload())->assertStatus(201)->json('data');
        $this->assertSame([false, false], array_column($quote['lines'], 'is_optional'));
        $this->assertSame([false, false], array_column($quote['lines'], 'in_package'));

        $payload = $this->oldEditorPayload();
        $payload['title'] = 'Presença digital, revisto';
        $this->api()->patchJson("/api/v1/admin/quotes/{$quote['id']}", $payload)->assertOk()->assertJsonPath('data.title', 'Presença digital, revisto');

        $this->api()->get("/api/v1/admin/quotes/{$quote['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->api()->postJson("/api/v1/admin/quotes/{$quote['id']}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertSame(1, \App\Models\QuotePublicLink::where('quote_id', $quote['id'])->count());
    }

    public function test_multipart_booleans_as_text_and_nulls_are_accepted(): void
    {
        // Como o axios envia um POST multipart: booleanos e números em texto, null omitido.
        $payload = $this->oldEditorPayload();
        $payload['lines'][0] += ['is_optional' => 'false', 'in_package' => 'true'];
        $payload['lines'][1] += ['is_optional' => '1', 'in_package' => null];
        $payload['lines'] = array_map(fn ($l) => array_map(fn ($v) => is_int($v) ? (string) $v : $v, $l), $payload['lines']);

        $quote = $this->api()->post('/api/v1/admin/quotes', $payload, ['Accept' => 'application/json'])->assertStatus(201)->json('data');
        $this->assertSame([false, true], array_column($quote['lines'], 'is_optional'));
        $this->assertSame([true, false], array_column($quote['lines'], 'in_package'));

        // E em JSON, true/false/1/0.
        $payload = $this->oldEditorPayload();
        $payload['lines'][0] += ['is_optional' => true, 'in_package' => 0];
        $payload['lines'][1] += ['is_optional' => 0, 'in_package' => true];
        $quote = $this->api()->postJson('/api/v1/admin/quotes', $payload)->assertStatus(201)->json('data');
        $this->assertSame([true, false], array_column($quote['lines'], 'is_optional'));
        $this->assertSame([false, true], array_column($quote['lines'], 'in_package'));
    }

    public function test_line_errors_are_readable_with_the_line_number(): void
    {
        $payload = $this->oldEditorPayload();
        $payload['lines'][0]['is_optional'] = 'talvez';
        $payload['lines'][1]['name'] = '';
        $payload['lines'][1]['unit'] = 'semana';
        $r = $this->api()->postJson('/api/v1/admin/quotes', $payload)->assertStatus(422);

        $this->assertSame("Linha 1: o valor de 'Opcional' não é válido.", $r->json('errors')['lines.0.is_optional'][0]);
        $this->assertSame('Linha 2: indique o nome do serviço.', $r->json('errors')['lines.1.name'][0]);
        $this->assertSame("Linha 2: o valor de 'Unidade' não é válido.", $r->json('errors')['lines.1.unit'][0]);
        $this->assertStringNotContainsString('lines.', implode(' ', array_merge(...array_values($r->json('errors')))));
        $this->assertStringNotContainsString('lines.', (string) $r->json('message'));

        // Erros calculados no servidor também levam o número da linha.
        $payload = $this->oldEditorPayload();
        $payload['lines'][1]['discount_type'] = 'amount';
        $payload['lines'][1]['discount_value'] = 999;
        $r = $this->api()->postJson('/api/v1/admin/quotes', $payload)->assertStatus(422);
        $this->assertSame('Linha 2: O desconto da linha não pode ser maior do que o valor da linha.', $r->json('errors')['lines.1.discount_value'][0]);
        $this->assertSame(0, Quote::count());
    }

    public function test_catalog_accepts_multipart_booleans_and_absent_active_never_disables(): void
    {
        $created = $this->api()->post('/api/v1/admin/service-catalog', [
            'name' => 'Email marketing', 'unit_price' => '80', 'unit' => 'month', 'billing_type' => 'monthly', 'active' => 'true',
        ], ['Accept' => 'application/json'])->assertStatus(201)->json('data');
        $this->assertTrue(ServiceCatalogItem::find($created['id'])->active);

        $this->api()->patchJson("/api/v1/admin/service-catalog/{$created['id']}", ['onboarding_checklist' => ['Ligar a lista de contactos']])->assertOk();
        $this->assertTrue(ServiceCatalogItem::find($created['id'])->active, 'Sem o campo, o serviço continua ativo.');

        $this->api()->post("/api/v1/admin/service-catalog", ['name' => 'Fotografia', 'unit_price' => '50', 'unit' => 'hour', 'billing_type' => 'one_off', 'active' => 'false'],
            ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertFalse(ServiceCatalogItem::where('name', 'Fotografia')->value('active'));
    }
}
