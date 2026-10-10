<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Models\OcrInvoice;
use App\Models\SatisfactionReport;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Billing\ChargeService;
use App\Services\Media\MediaService;
use App\Services\SatisfactionReportPhotoService;
use App\Services\Storage\CompanyStorageUsage;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Complemento ao pré-deploy, ponto 4: o espaço por empresa conta todos os ficheiros guardados
 * (Linha Editorial com as variantes, faturas do OCR, cobranças e comprovativos, faturas dos
 * tickets e fotografias dos relatórios), somado na base de dados; o root vê-o no /admin.
 */
class CompanyStorageUsageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('media');
        Storage::fake('local');
        Storage::fake('public');
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500900001', 'fiscal_name' => 'Espaço Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $xplendor = Company::create(['nipc' => '500900002', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root']);
    }

    private function jpeg(int $w = 400, int $h = 300): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 90, 160));
        ob_start();
        imagejpeg($img, null, 85);

        return (string) ob_get_clean();
    }

    private function asset(): MediaAsset
    {
        $bytes = $this->jpeg();
        $service = app(MediaService::class);
        $upload = $service->start($this->company->id, $this->admin, 'foto.jpg', strlen($bytes), 'image/jpeg');
        $service->appendChunk($upload, 0, UploadedFile::fake()->createWithContent('parte', $bytes));
        $asset = MediaAsset::findOrFail(MediaUpload::findOrFail($upload->id)->media_asset_id);
        $service->process($asset->id);

        return $asset->fresh();
    }

    public function test_every_stored_file_counts_by_kind_and_the_quota_uses_the_total(): void
    {
        $asset = $this->asset();
        $this->assertGreaterThan(0, $asset->variants_bytes, 'as variantes ficam com o tamanho ao serem geradas');

        OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'ocr-invoices/1/a.pdf', 'image_size_bytes' => 3000, 'image_mime' => 'application/pdf', 'status' => 'por_validar', 'synced_to_pingwin' => false]);
        $charge = app(ChargeService::class)->create($this->company, ['description' => 'Gestão', 'amount' => 10, 'due_date' => now()->addDays(10)->toDateString()],
            UploadedFile::fake()->createWithContent('f.pdf', str_repeat('p', 1500)), $this->root);
        app(ChargeService::class)->indicatePayment($charge, 'app', $this->admin, null, UploadedFile::fake()->createWithContent('c.pdf', str_repeat('c', 700)));
        $ticket = SupportTicket::forceCreate(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'type' => 'site_change', 'title' => 'Página',
            'description' => 'Texto', 'status' => 'open', 'quote_status' => 'approved', 'quoted_amount' => 100]);
        app(SupportTicketService::class)->markPaid($ticket, UploadedFile::fake()->createWithContent('t.pdf', str_repeat('t', 900)));
        $car = \App\Models\Car::factory()->create(['company_id' => $this->company->id]);
        $sale = \App\Models\CarSale::create(['car_id' => $car->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'male',
            'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now()]);
        $report = SatisfactionReport::create(['company_id' => $this->company->id, 'car_sale_id' => $sale->id, 'car_id' => $car->id,
            'public_token' => SatisfactionReport::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDays(90)]);
        $photo = app(SatisfactionReportPhotoService::class)->upload($report, UploadedFile::fake()->image('f.jpg', 200, 150));

        $u = CompanyStorageUsage::forCompany($this->company->id);
        $this->assertSame([
            'media' => $asset->size_bytes + $asset->variants_bytes, 'ocr' => 3000, 'cobrancas' => 2200, 'faturas_tickets' => 900,
            'fotos_relatorios' => (int) $photo->size_bytes,
        ], $u['bytes']);
        $this->assertSame(array_sum($u['bytes']), $u['total']);
        $this->assertSame(0, $u['sem_tamanho']);
        $this->assertSame($u['total'], MediaService::usedBytes($this->company->id), 'a quota soma todos os ficheiros');

        // Sem o original (retenção), as variantes continuam a contar.
        app(MediaService::class)->purgeOriginal($asset);
        $this->assertSame($asset->variants_bytes, CompanyStorageUsage::forCompany($this->company->id)['bytes']['media']);
    }

    public function test_only_the_root_sees_the_space_per_company_in_admin(): void
    {
        OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'ocr-invoices/1/a.pdf', 'image_size_bytes' => 5 * 1048576, 'image_mime' => 'application/pdf', 'status' => 'por_validar', 'synced_to_pingwin' => false]);
        $res = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/storage-usage')->assertOk();
        $row = collect($res->json('data.rows'))->firstWhere('company_id', $this->company->id);
        $this->assertSame(['Espaço Lda', 5 * 1048576, 5 * 1048576], [$row['company'], $row['bytes']['ocr'], $row['total']]);
        $this->assertSame('Faturas do OCR', $res->json('data.kinds.ocr'));
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/storage-usage')->assertForbidden();
    }

    public function test_the_command_fills_the_sizes_of_existing_files(): void
    {
        Storage::disk('local')->put('ocr-invoices/1/antiga.pdf', str_repeat('x', 1234));
        $ocr = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'ocr-invoices/1/antiga.pdf', 'image_mime' => 'application/pdf', 'status' => 'validada', 'synced_to_pingwin' => false]);
        $gone = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'ocr-invoices/1/sumiu.pdf', 'image_mime' => 'application/pdf', 'status' => 'validada', 'synced_to_pingwin' => false]);
        Storage::disk('public')->put("company_{$this->company->id}/ticket-invoices/velha.pdf", str_repeat('v', 321));
        $ticket = SupportTicket::forceCreate(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'type' => 'site_change', 'title' => 'Antigo',
            'description' => 'Texto', 'status' => 'open', 'quote_status' => 'paid', 'quoted_amount' => 1, 'invoice_path' => "/storage/company_{$this->company->id}/ticket-invoices/velha.pdf"]);
        $this->assertSame(3, CompanyStorageUsage::forCompany($this->company->id)['sem_tamanho']);

        Artisan::call('storage:fill-sizes');
        $this->assertNull($ocr->fresh()->image_size_bytes, 'a simulação não muda nada');

        Artisan::call('storage:fill-sizes', ['--execute' => true]);
        $this->assertSame([1234, 0, 321], [$ocr->fresh()->image_size_bytes, $gone->fresh()->image_size_bytes, $ticket->fresh()->invoice_size_bytes]);
        $this->assertSame(0, CompanyStorageUsage::forCompany($this->company->id)['sem_tamanho']);
        $this->assertSame(1555, CompanyStorageUsage::totalBytes($this->company->id));
    }
}
