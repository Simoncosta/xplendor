<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Public\CarPublicResource;
use App\Models\Car;
use App\Models\Company;
use App\Models\User;
use App\Services\CarService;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ficha da viatura (A4): o contacto comercial e a nota por empresa. Com o telefone, a ficha recebe
 * a designação (Dep. Comercial por omissão) e o telefone; sem ele, recebe o vendedor como antes.
 * A nota (até 250 caracteres) só vem quando está preenchida. Os dois só saem na ficha (nunca na
 * API pública, e o vendedor das outras utilizações não muda) e só os altera quem pode alterar os
 * outros dados da empresa.
 */
class PrintSheetContactTest extends TestCase
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
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019311', 'fiscal_name' => 'Autocaravanas Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500019312', 'fiscal_name' => 'Outro Stand Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'stock');
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'name' => 'Vendedor Exemplo', 'mobile' => '910000001']);
        \App\Access\CompatibilityMigration::run();
        $this->car = Car::factory()->create(['company_id' => $this->company->id, 'vehicle_type' => 'motorhome', 'status' => 'active', 'seller_user_id' => $this->seller->id]);
    }

    private function sheet(): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/print-sheet")->assertOk()->json('data');
    }

    public function test_without_the_company_contact_the_sheet_keeps_the_seller(): void
    {
        $data = $this->sheet();
        $this->assertNull($data['print_contact']);
        $this->assertSame(['Vendedor Exemplo', '910000001'], [$data['seller']['name'], $data['seller']['mobile']]);

        // Só a designação, sem telefone: continua o vendedor.
        $this->company->forceFill(['print_contact_label' => 'Comercial'])->save();
        $this->assertNull($this->sheet()['print_contact']);
    }

    public function test_with_the_company_contact_the_sheet_gets_the_label_and_the_phone(): void
    {
        $this->company->forceFill(['print_contact_phone' => '917349847'])->save();
        $this->assertSame(['label' => 'Dep. Comercial', 'phone' => '917349847'], $this->sheet()['print_contact']);

        $this->company->forceFill(['print_contact_label' => 'Stand Exemplo'])->save();
        $this->assertSame(['label' => 'Stand Exemplo', 'phone' => '917349847'], $this->sheet()['print_contact']);
    }

    public function test_the_contact_is_only_on_the_sheet_and_the_seller_of_the_other_uses_does_not_change(): void
    {
        $this->company->forceFill(['print_contact_label' => 'Dep. Comercial', 'print_contact_phone' => '917349847'])->save();

        // O vendedor da API pública e do site (appendPublicSellerContact) fica igual.
        $out = app(CarService::class)->appendPublicSellerContact(Car::find($this->car->id));
        $this->assertSame([$this->seller->id, '910000001'], [$out->seller_contact['id'], $out->seller_contact['mobile']]);

        $public = json_encode(CarPublicResource::make($out->load(['brand', 'model', 'category']))->resolve(request()));
        $this->assertStringNotContainsString('917349847', $public);
        $this->assertStringNotContainsString('print_contact', $public);
    }

    public function test_the_note_is_on_the_sheet_only_when_filled(): void
    {
        $this->assertNull($this->sheet()['print_note']);

        $this->company->forceFill(['print_note' => '   '])->save();
        $this->assertNull($this->sheet()['print_note']);

        $this->company->forceFill(['print_note' => 'Oficina própria. Financiamento à medida.'])->save();
        $this->assertSame('Oficina própria. Financiamento à medida.', $this->sheet()['print_note']);
    }

    public function test_the_note_has_at_most_250_characters_and_only_who_edits_the_company_changes_it(): void
    {
        $url = "/api/v1/companies/{$this->company->id}";
        $full = str_repeat('á', 250); // conta caracteres, não bytes

        $this->actingAs($this->seller, 'sanctum')->putJson($url, ['print_note' => 'Nota'])->assertForbidden();
        $otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->actingAs($otherAdmin, 'sanctum')->putJson($url, ['print_note' => 'Nota'])->assertForbidden();
        $this->assertNull($this->company->fresh()->print_note);

        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['print_note' => $full . 'a'])->assertStatus(422)->assertJsonValidationErrors('print_note');
        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['print_note' => $full])->assertOk();
        $this->assertSame($full, $this->company->fresh()->print_note);

        // Vazia, deixa de aparecer.
        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['print_note' => ''])->assertOk();
        $this->assertNull($this->sheet()['print_note']);
    }

    public function test_the_note_is_never_in_the_public_api(): void
    {
        $this->company->forceFill(['print_note' => 'Nota exclusiva da ficha impressa'])->save();
        $out = app(CarService::class)->appendPublicSellerContact(Car::find($this->car->id));
        $public = json_encode(CarPublicResource::make($out->load(['brand', 'model', 'category', 'company']))->resolve(request()));
        $this->assertStringNotContainsString('Nota exclusiva', $public);
        $this->assertStringNotContainsString('print_note', $public);
    }

    public function test_who_edits_the_company_data_edits_the_contact_and_nobody_else(): void
    {
        $url = "/api/v1/companies/{$this->company->id}";
        $fields = ['print_contact_label' => 'Dep. Comercial', 'print_contact_phone' => '917 349 847'];

        $this->actingAs($this->seller, 'sanctum')->putJson($url, $fields)->assertForbidden();
        $otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->actingAs($otherAdmin, 'sanctum')->putJson($url, $fields)->assertForbidden();
        $this->assertNull($this->company->fresh()->print_contact_phone);

        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['print_contact_phone' => '917-349-847'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson($url, $fields)->assertOk();
        $this->assertSame(['Dep. Comercial', '917 349 847'], [$this->company->fresh()->print_contact_label, $this->company->fresh()->print_contact_phone]);

        // A ficha da outra empresa não se abre.
        $this->actingAs($otherAdmin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/print-sheet")->assertForbidden();

        // Vazio volta ao vendedor.
        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['print_contact_phone' => ''])->assertOk();
        $this->assertNull($this->sheet()['print_contact']);
    }
}
