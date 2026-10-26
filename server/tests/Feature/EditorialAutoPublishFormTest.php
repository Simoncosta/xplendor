<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Criar publicação" na Linha Editorial dava "The auto publish field must be true or false.": o web
 * envia os POST em multipart/form-data (api_helper), e aí o false da caixa "Publicar automaticamente"
 * chega como o texto "false", que a regra boolean recusa. Aqui, o pedido tal como o ecrã o fazia
 * (o corpo do planPayload em formulário); o web passa a enviar JSON, e o servidor aceita os dois.
 */
class EditorialAutoPublishFormTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500095781', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        CompanyModule::create(['company_id' => $this->company->id, 'module_key' => 'linha_editorial']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 11, 'state' => EditorialMonth::OPEN]);
    }

    /** O planPayload de uma publicação nova, como o FormData o envia (null vai vazio, booleanos em texto). */
    private function screenBody(string $autoPublish): array
    {
        return ['title' => 'Menu de São Martinho', 'publish_date' => '2026-11-20', 'keyword' => '', 'pillar' => '', 'channel' => 'social',
            'format' => 'Carrossel', 'publish_time' => '', 'blog_id' => '', 'networks' => ['instagram' => ''], 'auto_publish' => $autoPublish];
    }

    public function test_creating_from_the_screen_form_accepts_the_auto_publish_box(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/editorial/posts";

        $r = $this->actingAs($this->admin, 'sanctum')->post($url, $this->screenBody('false'), ['Accept' => 'application/json']);
        $this->assertSame(200, $r->status(), (string) $r->json('message'));
        $this->assertFalse(EditorialPost::where('company_id', $this->company->id)->sole()->auto_publish);

        $r = $this->actingAs($this->admin, 'sanctum')->post($url, ['title' => 'Outra'] + $this->screenBody('true'), ['Accept' => 'application/json']);
        $this->assertSame(200, $r->status(), (string) $r->json('message'));
        $this->assertTrue(EditorialPost::where('title', 'Outra')->sole()->auto_publish);
    }

    public function test_json_booleans_still_work_and_other_values_are_refused_in_portuguese(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/editorial/posts";
        $json = ['title' => 'Menu', 'publish_date' => '2026-11-20', 'channel' => 'social', 'format' => 'Carrossel', 'networks' => ['instagram' => null]];

        $this->actingAs($this->admin, 'sanctum')->postJson($url, $json + ['auto_publish' => true])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson($url, $json)->assertOk();
        $this->assertSame(1, EditorialPost::where('auto_publish', true)->count(), 'sem o campo, fica desligada');

        $this->actingAs($this->admin, 'sanctum')->postJson($url, $json + ['auto_publish' => 'talvez'])->assertStatus(422)
            ->assertJsonFragment(['O campo publicar automaticamente tem de ser verdadeiro ou falso.']);
    }
}
