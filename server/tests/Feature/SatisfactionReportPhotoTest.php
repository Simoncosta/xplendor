<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\SatisfactionReport;
use App\Models\SatisfactionReportPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DMS Pós-venda (Incremento 2) — upload PÚBLICO de fotos do cliente.
 * Cobre segurança: validação, re-encode, teto de 3 no servidor, apagamento real
 * do storage (RGPD), consentimento registado, e isolamento (interno + foto alheia).
 */
class SatisfactionReportPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Car $car;
    private CarSale $sale;
    private SatisfactionReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000095', 'fiscal_name' => 'Stand Fotos Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->car = Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold']);
        $this->sale = CarSale::create([
            'car_id' => $this->car->id, 'company_id' => $this->company->id,
            'sale_price' => 20000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45',
            'sale_channel' => 'in_person', 'sold_at' => now(), 'buyer_name' => 'Carlos Comprador',
        ]);
        $this->report = SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => SatisfactionReport::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDays(90),
        ]);
    }

    private function photosUrl(): string
    {
        return "/api/public/report/{$this->report->public_token}/photos";
    }

    private function upload(UploadedFile $file)
    {
        return $this->post($this->photosUrl(), ['photo' => $file], ['Accept' => 'application/json']);
    }

    public function test_accepts_valid_image_and_reencodes_to_webp(): void
    {
        $res = $this->upload(UploadedFile::fake()->image('foto.jpg', 1200, 900));
        $res->assertStatus(201);

        $photo = SatisfactionReportPhoto::first();
        $this->assertNotNull($photo);
        $this->assertStringEndsWith('.webp', $photo->path);                 // re-encode
        $this->assertNotNull($photo->social_consent_at);                    // consentimento registado
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage', '', $photo->path), '/'));
    }

    public function test_rejects_non_image(): void
    {
        $res = $this->upload(UploadedFile::fake()->create('malware.txt', 20, 'text/plain'));
        $res->assertStatus(422);
        $this->assertSame(0, SatisfactionReportPhoto::count());
    }

    public function test_server_enforces_max_three(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->upload(UploadedFile::fake()->image("f{$i}.jpg"))->assertStatus(201);
        }
        // 4.ª → recusada pelo servidor (não confiar no cliente).
        $this->upload(UploadedFile::fake()->image('fourth.jpg'))->assertStatus(422);
        $this->assertSame(3, SatisfactionReportPhoto::count());
    }

    public function test_delete_removes_file_from_storage_rgpd(): void
    {
        $this->upload(UploadedFile::fake()->image('a.jpg'))->assertStatus(201);
        $photo = SatisfactionReportPhoto::first();
        $diskPath = ltrim(str_replace('/storage', '', $photo->path), '/');
        Storage::disk('public')->assertExists($diskPath);

        $this->delete("{$this->photosUrl()}/{$photo->id}", [], ['Accept' => 'application/json'])->assertStatus(200);

        // Direito ao esquecimento: ficheiro E registo desaparecem de verdade.
        Storage::disk('public')->assertMissing($diskPath);
        $this->assertNull(SatisfactionReportPhoto::find($photo->id));
    }

    public function test_cannot_delete_photo_of_another_report(): void
    {
        // Foto de OUTRO relatório (outra venda) — o token não manda nela.
        $car2 = Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold']);
        $sale2 = CarSale::create(['car_id' => $car2->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now()]);
        $report2 = SatisfactionReport::create(['company_id' => $this->company->id, 'car_sale_id' => $sale2->id, 'car_id' => $car2->id, 'public_token' => SatisfactionReport::generateToken(), 'status' => 'pending']);
        $alien = SatisfactionReportPhoto::create(['satisfaction_report_id' => $report2->id, 'path' => '/storage/x.webp', 'order' => 1, 'social_consent_at' => now()]);

        $this->delete("{$this->photosUrl()}/{$alien->id}", [], ['Accept' => 'application/json'])->assertStatus(404);
        $this->assertNotNull(SatisfactionReportPhoto::find($alien->id));
    }

    public function test_public_show_includes_photos_without_buyer_pii(): void
    {
        $this->upload(UploadedFile::fake()->image('a.jpg'))->assertStatus(201);

        $res = $this->getJson("/api/public/report/{$this->report->public_token}");
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data.photos'));
        $this->assertStringNotContainsString('Carlos Comprador', $res->getContent());
    }

    public function test_upload_is_rate_limited(): void
    {
        // throttle:10,1 — a 11.ª bate no limite (429), independentemente do 201/422.
        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->upload(UploadedFile::fake()->image("r{$i}.jpg"));
        }
        $last->assertStatus(429);
    }

    public function test_internal_photos_endpoint_is_tenant_scoped(): void
    {
        $this->upload(UploadedFile::fake()->image('a.jpg'))->assertStatus(201);

        $url = "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/satisfaction-report/photos";
        $this->actingAs($this->user, 'sanctum')->getJson($url)->assertStatus(200)->assertJsonCount(1, 'data.photos');

        // Admin de outra empresa não vê.
        $other = Company::create(['nipc' => '500000096', 'fiscal_name' => 'Outra Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($intruder, 'sanctum')->getJson($url)->assertStatus(403);
    }
}
