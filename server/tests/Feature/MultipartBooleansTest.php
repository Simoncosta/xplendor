<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedidos com ficheiros (multipart): a empresa com o logótipo e o blog com o banner. Os booleanos
 * chegam como texto; "true"/"false" passam a true/false antes da validação, como "1"/"0". A
 * viatura está no CarImageUpdateTest.
 */
class MultipartBooleansTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500000901', 'fiscal_name' => 'Empresa Teste Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
    }

    public function test_company_update_with_the_logo_accepts_text_booleans(): void
    {
        $url = "/api/v1/companies/{$this->company->id}";
        $this->actingAs($this->admin, 'sanctum')->post($url, ['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
            'uses_vat' => 'true', 'billing_reminders_enabled' => 'false'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([true, false], [(bool) $this->company->fresh()->uses_vat, (bool) $this->company->fresh()->billing_reminders_enabled]);

        $this->actingAs($this->admin, 'sanctum')->post($url, ['_method' => 'PUT', 'uses_vat' => 'false'], ['Accept' => 'application/json'])->assertOk();
        $this->assertFalse((bool) $this->company->fresh()->uses_vat);

        $this->actingAs($this->admin, 'sanctum')->post($url, ['_method' => 'PUT', 'uses_vat' => 'sim'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('uses_vat');
    }

    public function test_blog_with_the_banner_accepts_text_booleans(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->post("/api/v1/companies/{$this->company->id}/blogs", [
            'title' => 'Novidades da semana', 'banner' => UploadedFile::fake()->image('banner.jpg', 800, 400), 'seo_answer_first_ok' => 'true',
        ], ['Accept' => 'application/json'])->assertSuccessful()->json('data.id');
        $this->assertTrue((bool) DB::table('blogs')->where('id', $id)->value('seo_answer_first_ok'));
    }
}
