<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SatisfactionReport;
use App\Models\SatisfactionReportPhoto;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SatisfactionReportPhotoService;
use App\Services\SupportTicketService;
use App\Support\Images\ImageMetadata;
use App\Support\Storage\PrivateFiles;
use App\Support\Storage\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pré-deploy, ponto 5: exposição de ficheiros. As faturas dos pedidos de suporte e as
 * fotografias dos relatórios de satisfação ficam privadas (URL assinado); as fotografias
 * originais das viaturas e os avatares ficam sem EXIF; a rota antiga /api/media/{path} só
 * serve imagens de viaturas; os caminhos públicos pedem-se ao disco público.
 */
class FileExposureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500800001', 'fiscal_name' => 'Ficheiros Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    /** Um JPEG com EXIF e localização GPS (APP1 escrito à mão: o GD não escreve EXIF). */
    private function jpegWithGps(): string
    {
        $img = imagecreatetruecolor(40, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        // TIFF (little endian): IFD0 com o apontador para o IFD do GPS; IFD do GPS com GPSLatitudeRef = "N".
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 1) . pack('vvVV', 0x8825, 4, 1, 26) . pack('V', 0)
            . pack('v', 1) . pack('vvV', 0x0001, 2, 2) . "N\0\0\0" . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::forceCreate(array_merge([
            'company_id' => $this->company->id, 'user_id' => $this->admin->id, 'type' => 'site_change', 'title' => 'Mudar o site',
            'description' => 'Texto', 'status' => 'open', 'quote_status' => 'approved', 'quoted_amount' => 100,
        ], $extra));
    }

    private function report(): SatisfactionReport
    {
        $car = \App\Models\Car::factory()->create(['company_id' => $this->company->id]);
        $sale = \App\Models\CarSale::create(['car_id' => $car->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'male',
            'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now()]);

        return SatisfactionReport::create(['company_id' => $this->company->id, 'car_sale_id' => $sale->id, 'car_id' => $car->id,
            'public_token' => SatisfactionReport::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDays(90)]);
    }

    public function test_the_ticket_invoice_is_private_and_served_only_by_a_signed_url(): void
    {
        $ticket = app(SupportTicketService::class)->markPaid($this->ticket(), UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4 fatura'));

        $this->assertStringStartsWith("support-invoices/company_{$this->company->id}/", $ticket->invoice_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nada no disco público');
        $this->assertTrue(Storage::disk('local')->exists($ticket->invoice_path));

        $url = PrivateFiles::ticketInvoiceUrl($ticket);
        $this->assertStringStartsWith('/api/files/ticket-invoice/', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/files/ticket-invoice/{$ticket->id}")->assertStatus(403); // sem assinatura
        // A fatura é da faturação da XPLENDOR: só o Administrador recebe o endereço.
        $show = "/api/v1/companies/{$this->company->id}/support-tickets/{$ticket->id}";
        $this->assertNotNull($this->actingAs($this->admin)->getJson($show)->assertOk()->json('data.invoice_url'));
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $this->assertNull($this->actingAs($user)->getJson($show)->assertOk()->json('data.invoice_url'));
    }

    public function test_the_report_photos_are_private_signed_and_deleted_for_good(): void
    {
        $report = $this->report();
        $photo = app(SatisfactionReportPhotoService::class)->upload($report, UploadedFile::fake()->image('foto.jpg', 200, 150));

        $this->assertStringStartsWith("satisfaction-reports/company_{$this->company->id}/{$report->id}/", $photo->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get(PrivateFiles::reportPhotoUrl($photo))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get("/api/files/report-photo/{$photo->id}")->assertStatus(403);

        app(SatisfactionReportPhotoService::class)->delete($photo);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'RGPD: sem cópia escondida');
    }

    public function test_the_command_moves_the_old_public_files_and_updates_the_references(): void
    {
        $ticket = $this->ticket(['quote_status' => 'paid', 'invoice_path' => "/storage/company_{$this->company->id}/ticket-invoices/antiga.pdf"]);
        Storage::disk('public')->put("company_{$this->company->id}/ticket-invoices/antiga.pdf", '%PDF antiga');
        $report = $this->report();
        $photo = SatisfactionReportPhoto::create(['satisfaction_report_id' => $report->id, 'path' => "/storage/company_{$this->company->id}/reports/{$report->id}/a.webp", 'order' => 1, 'social_consent_at' => now()]);
        Storage::disk('public')->put("company_{$this->company->id}/reports/{$report->id}/a.webp", 'webp');

        // Antes de migrar, o valor antigo continua a funcionar como antes.
        $this->assertSame($ticket->invoice_path, PrivateFiles::ticketInvoiceUrl($ticket));

        Artisan::call('files:make-private');
        $this->assertStringStartsWith('/storage/', $ticket->fresh()->invoice_path, 'a simulação não muda nada');

        $this->assertSame(0, Artisan::call('files:make-private', ['--execute' => true]));
        $this->assertSame("support-invoices/company_{$this->company->id}/antiga.pdf", $ticket->fresh()->invoice_path);
        $this->assertSame("satisfaction-reports/company_{$this->company->id}/{$report->id}/a.webp", $photo->fresh()->path);
        $this->assertSame('%PDF antiga', Storage::disk('local')->get($ticket->fresh()->invoice_path));
        $this->assertSame([], Storage::disk('public')->allFiles(), 'o público foi apagado depois de confirmado');

        $this->assertSame(0, Artisan::call('files:make-private', ['--execute' => true]), 'idempotente');
    }

    public function test_car_originals_are_saved_without_exif_and_gps(): void
    {
        $raw = $this->jpegWithGps();
        $tmp = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($tmp, $raw);
        $this->assertSame(['exif' => true, 'gps' => true], ImageMetadata::inspect($tmp), 'o ficheiro de teste tem GPS');

        $file = new UploadedFile($tmp, 'carro.jpg', 'image/jpeg', null, true);
        $out = app(\App\Services\CarImageService::class)->handleUploads([$file], [], 'images', $this->company->id, 7, 'marca-modelo');

        $original = ltrim(substr($out[0]['original_path'], strlen('/storage')), '/');
        $this->assertStringStartsWith('/storage/company_', $out[0]['original_path']);
        $this->assertSame(['exif' => false, 'gps' => false], ImageMetadata::inspect(Storage::disk('public')->path($original)));
        @unlink($tmp);
    }

    public function test_the_command_strips_exif_from_existing_originals_and_avatars(): void
    {
        $path = "company_{$this->company->id}/cars/x-1/originals/1.jpg";
        Storage::disk('public')->put($path, $this->jpegWithGps());
        DB::table('car_images')->insert(['car_id' => \App\Models\Car::factory()->create(['company_id' => $this->company->id])->id, 'company_id' => $this->company->id,
            'image' => "/storage/company_{$this->company->id}/cars/x-1/images/1.webp", 'original_path' => "/storage/{$path}", 'order' => 1, 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $avatar = "company_{$this->company->id}/users/a.jpg";
        Storage::disk('public')->put($avatar, $this->jpegWithGps());
        $this->admin->forceFill(['avatar' => $avatar])->saveQuietly();

        Artisan::call('images:strip-exif');
        $this->assertTrue(ImageMetadata::inspect(Storage::disk('public')->path($path))['gps'], 'a simulação não muda nada');

        Artisan::call('images:strip-exif', ['--execute' => true]);
        $this->assertSame(['exif' => false, 'gps' => false], ImageMetadata::inspect(Storage::disk('public')->path($path)));
        $this->assertSame(['exif' => false, 'gps' => false], ImageMetadata::inspect(Storage::disk('public')->path($avatar)));
    }

    public function test_avatars_are_reencoded_and_saved_under_the_company(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $tmp = tempnam(sys_get_temp_dir(), 'av') . '.jpg';
        file_put_contents($tmp, $this->jpegWithGps());

        $updated = app(\App\Services\UserService::class)->update($user->id, ['avatar' => new UploadedFile($tmp, 'eu.php.jpg', 'image/jpeg', null, true)]);

        $this->assertMatchesRegularExpression("#^company_{$this->company->id}/users/[0-9]+_[a-z0-9]+\\.webp$#", (string) $updated->avatar);
        $this->assertStringNotContainsString("company_{$user->id}/", (string) $updated->avatar);
        @unlink($tmp);
    }

    public function test_the_old_media_route_only_serves_car_images(): void
    {
        Storage::disk('public')->put("company_{$this->company->id}/cars/marca-modelo-7/images/1.webp", 'webp');
        Storage::disk('public')->put("company_{$this->company->id}/logo/logo.webp", 'logo');
        Storage::disk('public')->put("company_{$this->company->id}/ticket-invoices/f.pdf", '%PDF');

        $this->get("/api/media/company_{$this->company->id}/cars/marca-modelo-7/images/1.webp", ['Origin' => 'https://xplendor.tech'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://xplendor.tech');
        $this->get("/api/media/company_{$this->company->id}/logo/logo.webp")->assertNotFound();
        $this->get("/api/media/company_{$this->company->id}/ticket-invoices/f.pdf")->assertNotFound();
        $this->get('/api/media/..%2F..%2F.env')->assertNotFound();
    }

    public function test_public_paths_come_from_the_public_disk(): void
    {
        config(['filesystems.default' => 'r2']);
        $this->assertSame("/storage/company_{$this->company->id}/logo/logo.webp", PublicUrl::path("company_{$this->company->id}/logo/logo.webp"));
    }
}
