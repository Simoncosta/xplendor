<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExpenseCharge;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\Media\MediaService;
use App\Support\Storage\LocalCopy;
use App\Support\Storage\StorageMigration;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Tests\TestCase;

/**
 * R2: os ficheiros no Cloudflare R2 (aqui, um disco simulado em memória que se porta como um
 * disco remoto: sem caminho local). Nenhum pedido real ao R2.
 */
class R2StorageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        Storage::fake('local');
        $adapter = new InMemoryFilesystemAdapter();
        Storage::set('r2', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['driver' => 's3']));
        config([
            'filesystems.disks.r2' => ['driver' => 's3', 'key' => 'chave', 'secret' => 'segredo', 'region' => 'auto', 'bucket' => 'xplendor-media',
                'endpoint' => 'https://conta.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true],
        ]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500700001', 'fiscal_name' => 'Media Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function jpeg(int $w = 64, int $h = 48): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img, null, 85);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function uploadToR2(): MediaAsset
    {
        config(['media.disk' => 'r2']);
        $bytes = $this->jpeg();
        $service = app(MediaService::class);
        $upload = $service->start($this->company->id, $this->user, 'foto.jpg', strlen($bytes), 'image/jpeg');
        $chunk = UploadedFile::fake()->createWithContent('parte', $bytes);
        $service->appendChunk($upload, 0, $chunk);

        return MediaAsset::findOrFail(MediaUpload::findOrFail($upload->id)->media_asset_id);
    }

    public function test_the_simulated_r2_disk_has_no_local_path(): void
    {
        $this->assertFalse(LocalCopy::isLocal(Storage::disk('r2')));
        $this->assertTrue(LocalCopy::isLocal(Storage::disk('media')));
    }

    public function test_a_chunked_upload_ends_in_r2_and_the_worker_processes_a_temporary_copy(): void
    {
        $asset = $this->uploadToR2();

        $this->assertSame('r2', $asset->disk);
        $this->assertTrue(Storage::disk('r2')->exists($asset->pathFor('original')));
        $this->assertSame([], Storage::disk('media')->allFiles('tmp'), 'a parte local é apagada no fim');

        app(MediaService::class)->process($asset->id);
        $asset->refresh();
        $this->assertSame(MediaAsset::READY, $asset->status, (string) $asset->error);
        $this->assertSame(64, $asset->width);
        $this->assertTrue(Storage::disk('r2')->exists($asset->pathFor('thumb')));
        $this->assertTrue(Storage::disk('r2')->exists($asset->pathFor('preview')));
        $this->assertSame([], glob(storage_path('app/tmp-copias/*')) ?: [], 'a cópia temporária do worker é apagada');
    }

    public function test_the_signed_app_url_redirects_to_a_short_r2_signed_url(): void
    {
        $asset = $this->uploadToR2();
        app(MediaService::class)->process($asset->id);

        $res = $this->get($asset->fresh()->signedUrl('thumb'));
        $res->assertStatus(302);
        $location = (string) $res->headers->get('Location');
        $this->assertStringStartsWith('https://conta.r2.cloudflarestorage.com/xplendor-media/' . $asset->dir . '/thumb.webp?', $location);
        $this->assertStringContainsString('X-Amz-Expires=300', $location);
        $this->assertStringContainsString('X-Amz-Signature=', $location);

        // Sem a assinatura da aplicação, nada.
        $this->get("/api/media/{$asset->id}/thumb")->assertStatus(403);
    }

    public function test_the_meta_gets_an_absolute_signed_url_valid_for_one_hour(): void
    {
        $asset = $this->uploadToR2();

        $url = (string) $asset->externalUrl('original');
        $this->assertStringStartsWith('https://conta.r2.cloudflarestorage.com/', $url);
        $this->assertStringContainsString('X-Amz-Expires=3600', $url);
    }

    public function test_retention_and_purge_delete_in_r2(): void
    {
        $asset = $this->uploadToR2();
        app(MediaService::class)->process($asset->id);
        $asset->refresh();

        app(MediaService::class)->purgeOriginal($asset);
        $this->assertFalse(Storage::disk('r2')->exists("{$asset->dir}/original.jpg"));
        $this->assertTrue(Storage::disk('r2')->exists($asset->pathFor('thumb')), 'as miniaturas ficam');

        app(MediaService::class)->purge($asset->fresh());
        $this->assertSame([], Storage::disk('r2')->allFiles($asset->dir));
    }

    public function test_the_quota_still_counts_the_originals_in_r2(): void
    {
        $asset = $this->uploadToR2();
        $this->assertSame($asset->size_bytes, MediaService::usedBytes($this->company->id));
    }

    public function test_the_migration_simulates_copies_verifies_is_idempotent_and_never_deletes_local(): void
    {
        $dir = "company_{$this->company->id}/abc";
        Storage::disk('media')->put("{$dir}/original.jpg", $this->jpeg());
        Storage::disk('media')->put("{$dir}/thumb.webp", 'miniatura');
        $asset = MediaAsset::create(['company_id' => $this->company->id, 'kind' => MediaAsset::IMAGE, 'disk' => 'media', 'dir' => $dir, 'original_name' => 'a.jpg',
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'status' => MediaAsset::READY, 'variants' => ['thumb' => 'thumb.webp']]);
        Storage::disk('local')->put('charges/company_1/x.pdf', '%PDF-1.4 fatura');
        Storage::disk('local')->put('ocr-invoices/1/y.jpg', 'imagem da fatura');

        // Simulação: nada é escrito.
        $sim = (new StorageMigration('r2'))->run(false);
        $this->assertSame(0, $sim['copiados']);
        $this->assertSame([], Storage::disk('r2')->allFiles());
        $this->assertSame(0, DB::table('storage_migration_items')->count());

        // A sério: copia, confirma e o asset passa a ler do R2.
        $run = (new StorageMigration('r2'))->run(true);
        $this->assertSame(2, $run['copiados']);
        $this->assertSame(1, $run['assets_no_r2']);
        $this->assertSame('r2', $asset->fresh()->disk);
        $this->assertSame(Storage::disk('media')->get("{$dir}/original.jpg"), Storage::disk('r2')->get("{$dir}/original.jpg"));
        $this->assertSame(2, DB::table('storage_migration_items')->where('status', 'verificado')->whereNotNull('sha256')->count());
        $this->assertTrue(Storage::disk('media')->exists("{$dir}/original.jpg"), 'o local nunca é apagado pela migração');

        // Idempotente: a segunda vez não copia nada (o asset já lê do R2 e nem é revisto).
        $again = (new StorageMigration('r2'))->run(true);
        $this->assertSame(0, $again['copiados']);
        $this->assertSame(0, $again['falhas']);
        $this->assertSame(2, DB::table('storage_migration_items')->count(), 'sem linhas repetidas');
    }

    public function test_a_missing_local_file_is_recorded_and_a_changed_remote_is_copied_again(): void
    {
        $dir = "company_{$this->company->id}/def";
        Storage::disk('media')->put("{$dir}/original.jpg", 'abc');
        MediaAsset::create(['company_id' => $this->company->id, 'kind' => MediaAsset::IMAGE, 'disk' => 'media', 'dir' => $dir, 'original_name' => 'a.jpg',
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 3, 'sha256' => str_repeat('b', 64), 'status' => MediaAsset::READY, 'variants' => ['thumb' => 'thumb.webp']]);

        $run = (new StorageMigration('r2'))->run(true, ['media']);
        $this->assertSame(1, $run['em_falta'], 'a miniatura não existe no disco local');
        $this->assertSame(0, $run['assets_no_r2'], 'um asset incompleto continua a ler do local');

        Storage::disk('r2')->put("{$dir}/original.jpg", 'estragado');
        $again = (new StorageMigration('r2'))->run(true, ['media']);
        $this->assertSame(1, $again['copiados']);
        $this->assertSame('abc', Storage::disk('r2')->get("{$dir}/original.jpg"));
    }

    public function test_purge_local_only_deletes_what_is_already_read_from_r2(): void
    {
        Storage::disk('local')->put('charges/company_1/x.pdf', '%PDF-1.4 fatura');
        (new StorageMigration('r2'))->run(true, ['media']); // nada
        DB::table('storage_migration_items')->insert(['kind' => 'cobranca', 'source_disk' => 'local', 'target_disk' => 'r2', 'path' => 'charges/company_1/x.pdf',
            'size_bytes' => 15, 'sha256' => hash('sha256', '%PDF-1.4 fatura'), 'status' => 'verificado', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('r2')->put('charges/company_1/x.pdf', '%PDF-1.4 fatura');

        $sim = (new StorageMigration('r2'))->purgeLocal(true);
        $this->assertSame(1, $sim['mantidos'], 'ainda se lê do local (PRIVATE_FILES_DISK não é r2)');
        $this->assertTrue(Storage::disk('local')->exists('charges/company_1/x.pdf'));

        config(['storage_targets.private_disk' => 'r2', 'services.openai.ocr_disk' => 'r2']);
        $dry = (new StorageMigration('r2'))->purgeLocal(false);
        $this->assertSame(0, $dry['apagados'], 'simulação');
        $run = (new StorageMigration('r2'))->purgeLocal(true);
        $this->assertSame(1, $run['apagados']);
        $this->assertFalse(Storage::disk('local')->exists('charges/company_1/x.pdf'));
        $this->assertTrue(Storage::disk('r2')->exists('charges/company_1/x.pdf'));
    }

    public function test_ticket_invoices_and_report_photos_go_to_r2_and_the_old_public_ones_stay_out(): void
    {
        $ticket = \App\Models\SupportTicket::forceCreate(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'site_change',
            'title' => 'Mudar o site', 'description' => 'Texto', 'status' => 'open', 'quote_status' => 'paid', 'quoted_amount' => 100,
            'invoice_path' => "support-invoices/company_{$this->company->id}/f.pdf"]);
        Storage::disk('local')->put($ticket->invoice_path, '%PDF-1.4 fatura do ticket');
        \App\Models\SupportTicket::forceCreate(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'site_change',
            'title' => 'Antigo', 'description' => 'Texto', 'status' => 'open', 'quote_status' => 'paid', 'quoted_amount' => 100,
            'invoice_path' => "/storage/company_{$this->company->id}/ticket-invoices/antiga.pdf"]);
        $car = \App\Models\Car::factory()->create(['company_id' => $this->company->id]);
        $sale = \App\Models\CarSale::create(['car_id' => $car->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'male',
            'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now()]);
        $report = \App\Models\SatisfactionReport::create(['company_id' => $this->company->id, 'car_sale_id' => $sale->id, 'car_id' => $car->id,
            'public_token' => \App\Models\SatisfactionReport::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDays(90)]);
        $photo = \App\Models\SatisfactionReportPhoto::create(['satisfaction_report_id' => $report->id, 'order' => 1, 'social_consent_at' => now(),
            'path' => "satisfaction-reports/company_{$this->company->id}/{$report->id}/a.webp"]);
        Storage::disk('local')->put($photo->path, 'webp');

        $run = (new StorageMigration('r2'))->run(true, ['fatura_ticket', 'foto_relatorio']);
        $this->assertSame(2, $run['copiados'], 'o caminho público antigo fica de fora (passa primeiro pelo files:make-private)');
        $this->assertSame('%PDF-1.4 fatura do ticket', Storage::disk('r2')->get($ticket->invoice_path));
        $this->assertTrue(Storage::disk('r2')->exists($photo->path));

        $this->assertSame(2, (new StorageMigration('r2'))->purgeLocal(true)['mantidos'], 'ainda se lê do local');
        config(['storage_targets.private_disk' => 'r2']);
        $this->get(\App\Support\Storage\PrivateFiles::ticketInvoiceUrl($ticket))->assertRedirect();
        $this->assertSame(2, (new StorageMigration('r2'))->purgeLocal(true)['apagados']);
        $this->assertFalse(Storage::disk('local')->exists($photo->path));
    }

    public function test_with_private_files_on_r2_ticket_invoices_and_report_photos_are_written_and_served_from_r2(): void
    {
        config(['storage_targets.private_disk' => 'r2']);
        $ticket = \App\Models\SupportTicket::forceCreate(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'site_change',
            'title' => 'Mudar o site', 'description' => 'Texto', 'status' => 'open', 'quote_status' => 'approved', 'quoted_amount' => 100]);
        $ticket = app(\App\Services\SupportTicketService::class)->markPaid($ticket, UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4 no R2'));
        $this->assertTrue(Storage::disk('r2')->exists($ticket->invoice_path));
        $this->assertSame([], Storage::disk('local')->allFiles(), 'nada no disco local');

        $car = \App\Models\Car::factory()->create(['company_id' => $this->company->id]);
        $sale = \App\Models\CarSale::create(['car_id' => $car->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'male',
            'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now()]);
        $report = \App\Models\SatisfactionReport::create(['company_id' => $this->company->id, 'car_sale_id' => $sale->id, 'car_id' => $car->id,
            'public_token' => \App\Models\SatisfactionReport::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDays(90)]);
        $photo = app(\App\Services\SatisfactionReportPhotoService::class)->upload($report, UploadedFile::fake()->image('foto.jpg', 120, 90));
        $this->assertTrue(Storage::disk('r2')->exists($photo->path));

        // Saem por um endereço assinado do R2 (redirecionamento), atrás do endereço assinado da aplicação.
        $this->get(\App\Support\Storage\PrivateFiles::ticketInvoiceUrl($ticket))->assertRedirect();
        $this->get(\App\Support\Storage\PrivateFiles::reportPhotoUrl($photo))->assertRedirect();

        // Repetir a migração depois da mudança: o que já está no R2 conta como "já estava", não "em falta".
        $run = (new StorageMigration('r2'))->run(true, ['fatura_ticket', 'foto_relatorio']);
        $this->assertSame([0, 2, 0], [$run['copiados'], $run['ja_estavam'], $run['em_falta']]);

        // O apagamento definitivo apaga no R2.
        app(\App\Services\SatisfactionReportPhotoService::class)->delete($photo);
        $this->assertFalse(Storage::disk('r2')->exists($photo->path));
    }

    public function test_files_make_private_moves_the_old_public_files_straight_to_r2(): void
    {
        Storage::fake('public');
        config(['storage_targets.private_disk' => 'r2']);
        $ticket = \App\Models\SupportTicket::forceCreate(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'type' => 'site_change',
            'title' => 'Antigo', 'description' => 'Texto', 'status' => 'open', 'quote_status' => 'paid', 'quoted_amount' => 100,
            'invoice_path' => "/storage/company_{$this->company->id}/ticket-invoices/antiga.pdf"]);
        Storage::disk('public')->put("company_{$this->company->id}/ticket-invoices/antiga.pdf", '%PDF antiga');

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('files:make-private', ['--execute' => true]));
        $this->assertSame('%PDF antiga', Storage::disk('r2')->get($ticket->fresh()->invoice_path));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_charges_are_stored_and_read_on_the_private_disk(): void
    {
        config(['storage_targets.private_disk' => 'r2']);
        $this->assertSame('r2', \App\Services\Billing\ChargeService::disk());
        $path = UploadedFile::fake()->createWithContent('f.pdf', '%PDF-1.4')->storeAs('charges/company_' . $this->company->id, 'a.pdf', \App\Services\Billing\ChargeService::disk());
        $this->assertTrue(Storage::disk('r2')->exists($path));
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_the_company_purge_also_deletes_in_r2_and_in_the_local_copy(): void
    {
        config(['media.disk' => 'r2', 'storage_targets.private_disk' => 'r2', 'services.openai.ocr_disk' => 'r2']);
        $id = $this->company->id;
        Storage::disk('r2')->put("company_{$id}/abc/original.jpg", 'x');
        Storage::disk('media')->put("company_{$id}/abc/original.jpg", 'x');
        Storage::disk('r2')->put("ocr-invoices/{$id}/f.jpg", 'x');
        Storage::disk('r2')->put("charges/company_{$id}/fatura.pdf", 'x');

        $method = new \ReflectionMethod(\App\Services\Agency\CompanyPurgeService::class, 'deleteFiles');
        $method->invoke(app(\App\Services\Agency\CompanyPurgeService::class), $id);

        $this->assertSame([], Storage::disk('r2')->allFiles("company_{$id}"));
        $this->assertSame([], Storage::disk('media')->allFiles("company_{$id}"));
        $this->assertSame([], Storage::disk('r2')->allFiles("ocr-invoices/{$id}"));
        $this->assertTrue(Storage::disk('r2')->exists("charges/company_{$id}/fatura.pdf"), 'as cobranças ficam (10 anos)');
    }
}
