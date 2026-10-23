<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Models\Company;
use App\Models\FileFolder;
use App\Models\MediaAsset;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Files\SystemFileCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ficheiros (F3): as pastas do sistema e o registo dos ficheiros que os módulos já guardam. Nada
 * é copiado: cada ficheiro fica onde está. Simulação por omissão, idempotente, e o que sai do
 * módulo deixa de aparecer.
 */
class SystemFileCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Company $c;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        foreach (['local', 'public', 'media'] as $d) {
            Storage::fake($d);
        }
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->c = Company::create(['nipc' => '501200001', 'fiscal_name' => 'Catálogo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($this->c->id, 'ficheiros');
        $this->admin = User::factory()->create(['company_id' => $this->c->id, 'role' => 'admin', 'name' => 'Ana Exemplo']);
        \App\Access\CompatibilityMigration::run();
        $this->seedSources();
    }

    private function putFile(string $disk, string $path, string $content = 'conteúdo'): void
    {
        Storage::disk($disk)->put($path, $content);
    }

    /** Um ficheiro de cada origem, no disco e na tabela do módulo (dados fictícios). */
    private function seedSources(): void
    {
        $c = $this->c->id;
        $this->putFile('media', "company_{$c}/aa/original.jpg");
        MediaAsset::create(['company_id' => $c, 'kind' => MediaAsset::IMAGE, 'disk' => 'media', 'dir' => "company_{$c}/aa", 'original_name' => 'Montra.jpg',
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 9, 'sha256' => str_repeat('a', 64), 'status' => MediaAsset::READY, 'created_at' => '2026-10-05 10:00:00']);
        $this->putFile('local', "ocr-invoices/{$c}/f.pdf", '%PDF ocr');
        DB::table('ocr_invoices')->insert(['company_id' => $c, 'image_path' => "ocr-invoices/{$c}/f.pdf", 'image_mime' => 'application/pdf', 'supplier_name' => 'Fornecedor Exemplo',
            'number' => 'FT 1', 'issue_date' => '2026-09-14', 'status' => 'validada', 'synced_to_pingwin' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->putFile('local', "charges/company_{$c}/x.pdf", '%PDF cobrança');
        $expense = DB::table('expenses')->insertGetId(['company_id' => $c, 'description' => 'Gestão, outubro', 'amount' => 10, 'date' => '2026-10-01', 'is_paid' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expense_charges')->insert(['expense_id' => $expense, 'company_id' => $c, 'status' => 'open', 'due_date' => '2026-10-20', 'invoice_path' => "charges/company_{$c}/x.pdf",
            'invoice_name' => 'Fatura XPLENDOR 12.pdf', 'token_hash' => str_repeat('b', 64), 'token_encrypted' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->putFile('public', "company_{$c}/tickets/s.webp");
        DB::table('support_tickets')->insert(['company_id' => $c, 'user_id' => $this->admin->id, 'type' => 'bug', 'title' => 'Erro', 'description' => 'x', 'status' => 'open',
            'screenshot_path' => "/storage/company_{$c}/tickets/s.webp", 'created_at' => now(), 'updated_at' => now()]);
        $car = \App\Models\Car::factory()->create(['company_id' => $c]);
        $this->putFile('public', "company_{$c}/cars/x-{$car->id}/images/1.webp");
        $this->putFile('public', "company_{$c}/cars/x-{$car->id}/originals/1.jpg");
        DB::table('car_images')->insert(['car_id' => $car->id, 'company_id' => $c, 'image' => "/storage/company_{$c}/cars/x-{$car->id}/images/1.webp",
            'original_path' => "/storage/company_{$c}/cars/x-{$car->id}/originals/1.jpg", 'order' => 0, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('car_images')->insert(['car_id' => $car->id, 'company_id' => $c, 'image' => "/storage/company_{$c}/cars/x-{$car->id}/images/sem-ficheiro.webp",
            'order' => 1, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->putFile('public', "company_{$c}/logo/logo.webp");
        DB::table('companies')->where('id', $c)->update(['logo_path' => "/storage/company_{$c}/logo/logo.webp"]);
        $this->putFile('public', "company_{$c}/team/t.webp");
        DB::table('collaborators')->insert(['company_id' => $c, 'name' => 'Rui Exemplo', 'photo_path' => "company_{$c}/team/t.webp", 'created_at' => now(), 'updated_at' => now()]);
        $this->putFile('local', "company_{$c}/document_templates/m.docx");
        DB::table('document_templates')->insert(['company_id' => $c, 'name' => 'Contrato de venda', 'original_path' => "company_{$c}/document_templates/m.docx", 'created_at' => now(), 'updated_at' => now()]);
    }

    private function allFiles(): array
    {
        return collect(['local', 'public', 'media'])->flatMap(fn ($d) => Storage::disk($d)->allFiles())->sort()->values()->all();
    }

    public function test_the_command_simulates_then_registers_every_source_in_its_system_folder_without_copying(): void
    {
        $before = $this->allFiles();

        Artisan::call('files:catalog');
        $this->assertSame(0, StoredFile::count(), 'a simulação não grava');
        $this->assertStringContainsString('SIMULAÇÃO', Artisan::output());

        $this->assertSame(0, Artisan::call('files:catalog', ['--execute' => true]));
        $this->assertSame($before, $this->allFiles(), 'nenhum ficheiro foi copiado, movido ou apagado');

        $bySource = StoredFile::where('company_id', $this->c->id)->get()->keyBy('source');
        $this->assertSame(['captura', 'cobranca', 'equipa', 'logotipo', 'media', 'modelo', 'ocr', 'viatura', 'viatura_original'],
            $bySource->keys()->sort()->values()->all());
        $folder = fn (string $source) => FileFolder::find($bySource[$source]->folder_id);
        $this->assertSame(['editorial:2026-10', 'Outubro de 2026'], [$folder('media')->system_key, $folder('media')->name]);
        $this->assertSame(['ocr:2026-09', 'Setembro de 2026', 'Fornecedor Exemplo FT 1.pdf'], [$folder('ocr')->system_key, $folder('ocr')->name, $bySource['ocr']->name]);
        $this->assertSame('xplendor:cobrancas', $folder('cobranca')->system_key);
        $this->assertSame(['public', "company_{$this->c->id}/logo/logo.webp"], [$bySource['logotipo']->disk, $bySource['logotipo']->path], 'fica onde está');
        $this->assertTrue($bySource['viatura']->is_system);
        $this->assertNotNull($bySource['media']->media_asset_id);
        $this->assertSame('Faturação XPLENDOR', FileFolder::where('company_id', $this->c->id)->where('system_key', 'xplendor')->value('name'));
        $this->assertSame(1, StoredFile::where('source', 'viatura')->count(), 'a imagem sem ficheiro no disco não é registada');

        // Idempotente.
        Artisan::call('files:catalog', ['--execute' => true]);
        $this->assertSame(9, StoredFile::count());
    }

    public function test_without_the_files_module_nothing_is_registered_whoever_asks(): void
    {
        // Spike de 22/10: registos criados sem o módulo (o site do consultor chamava o registo direto).
        app(CompanyModuleService::class)->disable($this->c->id, 'ficheiros');
        $this->assertSame([], app(SystemFileCatalog::class)->sync($this->c->id, true));
        app(SystemFileCatalog::class)->syncIfStale($this->c->id);
        Artisan::call('files:catalog', ['--execute' => true]);
        $this->assertSame(0, StoredFile::count());
        $this->assertSame(0, FileFolder::where('company_id', $this->c->id)->count());

        app(CompanyModuleService::class)->enable($this->c->id, 'ficheiros');
        app(SystemFileCatalog::class)->sync($this->c->id, true);
        $this->assertSame(9, StoredFile::count());
    }

    public function test_what_leaves_the_module_stops_appearing_and_the_folders_follow_their_area(): void
    {
        app(SystemFileCatalog::class)->sync($this->c->id, true);
        DB::table('collaborators')->update(['photo_path' => null]);
        $stats = app(SystemFileCatalog::class)->sync($this->c->id, true);
        $this->assertSame(1, $stats['equipa']['em_falta']);
        $this->assertNotNull(StoredFile::where('source', 'equipa')->value('missing_at'));

        // O utilizador não vê a faturação da XPLENDOR nem a equipa? Vê a equipa (utilizadores.ver), não vê a faturação.
        $user = User::factory()->create(['company_id' => $this->c->id, 'role' => 'user']);
        app(CompanyModuleService::class)->enable($this->c->id, 'stock');
        $keys = collect($this->actingAs($user, 'sanctum')->getJson("/api/v1/companies/{$this->c->id}/files")->assertOk()->json('data.tree'))->pluck('system_key');
        $this->assertNotContains('xplendor', $keys->all());
        $this->assertContains('viaturas', $keys->all());
        $this->assertNotContains('editorial', $keys->all(), 'o módulo da Linha Editorial está desligado');
        $admin = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->c->id}/files")->json('data.tree'))->pluck('system_key');
        $this->assertContains('xplendor', $admin->all());
    }

    public function test_opening_the_files_registers_the_new_module_files(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->c->id}/files")->assertOk();
        $this->assertSame(9, StoredFile::where('company_id', $this->c->id)->count(), 'o primeiro abrir regista');
    }
}
