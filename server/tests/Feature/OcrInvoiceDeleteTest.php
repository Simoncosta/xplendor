<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoicePingwinLink;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentWrite;
use App\Models\PingwinSupplierDocument;
use App\Models\SupplierArticleMap;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use App\Services\OcrInvoiceDeleteService;
use App\Services\OcrPingwinLinkService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * XPLENDOR — F2c: apagar faturas OCR (soft delete), repor, purga dos ficheiros aos 30 dias,
 * ficheiro duplicado no carregamento, teto que não volta atrás, apagar várias com motivos,
 * reprocessar as faturas antigas (dry-run e real) e tenancy. Nunca fala com o PingWin.
 */
class OcrInvoiceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Company $yuko;
    private Company $other;
    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->yuko = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->yuko, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
            CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        }
        $this->user = User::factory()->create(['company_id' => $this->yuko->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->app->instance(PingwinService::class, new class extends PingwinService {
            public function __construct() {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
        });
    }

    private function invoice(array $o = [], ?Company $c = null): OcrInvoice
    {
        $c ??= $this->yuko;
        $path = 'ocr-invoices/' . $c->id . '/' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, 'pdf-' . $path);
        $inv = OcrInvoice::create(array_merge(['company_id' => $c->id, 'image_path' => $path, 'image_mime' => 'application/pdf',
            'status' => 'por_validar', 'supplier_nif' => '502811331', 'number' => 'FT ' . uniqid(), 'issue_date' => '2026-05-04',
            'file_sha256' => hash('sha256', 'pdf-' . $path)], $o));
        OcrInvoiceSummary::create(['ocr_invoice_id' => $inv->id, 'company_id' => $c->id, 'total_cents' => 1000]);

        return $inv;
    }

    private function svc(): OcrInvoiceDeleteService
    {
        return app(OcrInvoiceDeleteService::class);
    }

    private function draftDoc(OcrInvoice $inv, string $status, string $writeStatus = 'ok'): void
    {
        PingwinSupplierDocument::create(['company_id' => $inv->company_id, 'docheader_id' => "D{$inv->id}", 'docconfig_id' => '1209', 'document' => 'VFT BOVFT/1077',
            'total_cents' => 1000, 'docstatus_id' => $status]);
        PingwinDocumentWrite::create(['company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'action' => 'launch', 'status' => $writeStatus,
            'docheader_id' => "D{$inv->id}", 'document' => 'VFT BOVFT/1077']);
        if ($writeStatus === 'ok') {
            OcrInvoicePingwinLink::create(['company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => "D{$inv->id}", 'method' => 'xplendor', 'confirmed_at' => now()]);
        }
    }

    // ───────────────────────────────────────────────────────────── regras

    public function test_delete_rules_matrix(): void
    {
        $this->assertNotNull($this->svc()->blockReason($this->invoice(['status' => 'processing'])));

        $draft = $this->invoice();
        $this->draftDoc($draft, '8001');
        $this->assertStringContainsString('Anula primeiro o rascunho/documento no PingWin', $this->svc()->blockReason($draft));

        $closed = $this->invoice();
        $this->draftDoc($closed, '8002');
        $this->assertStringContainsString('Anula primeiro', $this->svc()->blockReason($closed));

        $unconfirmed = $this->invoice();
        $this->draftDoc($unconfirmed, '8001', 'erro_confirmacao');
        $this->assertStringContainsString('por confirmar', $this->svc()->blockReason($unconfirmed));

        $pending = $this->invoice();
        PingwinDocumentWrite::create(['company_id' => $pending->company_id, 'ocr_invoice_id' => $pending->id, 'action' => 'launch', 'status' => 'pendente']);
        $this->assertStringContainsString('em curso', $this->svc()->blockReason($pending));

        $voided = $this->invoice();
        $this->draftDoc($voided, '8003');
        $this->assertNull($this->svc()->blockReason($voided));               // anulada no PingWin → pode apagar

        foreach (['por_validar', 'validada', 'erro', 'nao_desta_empresa'] as $st) {
            $this->assertNull($this->svc()->blockReason($this->invoice(['status' => $st])), $st);
        }
    }

    public function test_delete_manual_pingwin_link_unlinks_and_keeps_learned_map(): void
    {
        $inv = $this->invoice(['link_status' => 'lancada']);
        PingwinSupplierDocument::create(['company_id' => $this->yuko->id, 'docheader_id' => '57', 'docconfig_id' => '1209', 'document' => 'VFT 0VFT/57', 'total_cents' => 1000, 'docstatus_id' => '8002']);
        OcrInvoicePingwinLink::create(['company_id' => $this->yuko->id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => '57', 'method' => 'total_data']);
        $art = PingwinCatalogItem::create(['company_id' => $this->yuko->id, 'pingwin_id' => 'P1', 'code' => '1', 'description' => 'X', 'is_active' => true]);
        SupplierArticleMap::create(['company_id' => $this->yuko->id, 'supplier_nif' => '502811331', 'supplier_code_norm' => '5', 'supplier_code' => '5', 'article_id' => $art->id, 'source' => 'manual']);

        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}")->assertOk();

        $this->assertSoftDeleted('ocr_invoices', ['id' => $inv->id, 'deleted_by' => $this->user->id]);
        $this->assertSame(0, OcrInvoicePingwinLink::count());                 // desligada
        $this->assertDatabaseHas('pingwin_supplier_documents', ['docheader_id' => '57']); // o documento no PingWin fica
        $this->assertSame(1, SupplierArticleMap::count());                    // o aprendido mantém-se
        Storage::disk('local')->assertExists($inv->image_path);               // o ficheiro fica 30 dias
    }

    public function test_api_refuses_xplendor_launch(): void
    {
        $inv = $this->invoice();
        $this->draftDoc($inv, '8001');
        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}")
            ->assertStatus(422)->assertJsonPath('errors.code', 'nao_apagavel');
        $this->assertNotSoftDeleted('ocr_invoices', ['id' => $inv->id]);
        $this->assertSame(1, OcrInvoicePingwinLink::count());
    }

    // ───────────────────────────────────────────────────────────── listas, repor, purga

    public function test_list_show_deleted_and_restore(): void
    {
        $keep = $this->invoice();
        $gone = $this->invoice();
        $this->svc()->delete($gone, $this->user->id);
        $base = "/api/v1/companies/{$this->yuko->id}/ocr/invoices";

        $this->actingAs($this->user, 'sanctum')->getJson($base)->assertJsonCount(1, 'data.invoices.data')->assertJsonPath('data.invoices.data.0.id', $keep->id)
            ->assertJsonPath('data.invoices.data.0.delete_block', null);
        $this->actingAs($this->user, 'sanctum')->getJson("{$base}?deleted=1")->assertJsonCount(1, 'data.invoices.data')
            ->assertJsonPath('data.invoices.data.0.id', $gone->id)->assertJsonPath('data.invoices.data.0.restore_block', null);
        $this->actingAs($this->user, 'sanctum')->getJson("{$base}/{$gone->id}")->assertStatus(404);   // apagada não abre

        $this->actingAs($this->user, 'sanctum')->postJson("{$base}/{$gone->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('ocr_invoices', ['id' => $gone->id]);
        $this->actingAs($this->user, 'sanctum')->getJson($base)->assertJsonCount(2, 'data.invoices.data');
    }

    public function test_purge_after_30_days_and_no_restore_after(): void
    {
        $old = $this->invoice();
        $recent = $this->invoice();
        $this->svc()->delete($old, $this->user->id);
        $this->svc()->delete($recent, $this->user->id);
        OcrInvoice::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);
        OcrInvoice::withTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(10)]);

        $this->assertSame(1, $this->svc()->purgeFiles());
        Storage::disk('local')->assertMissing($old->image_path);
        Storage::disk('local')->assertExists($recent->image_path);
        $this->assertNotNull(OcrInvoice::withTrashed()->find($old->id)->file_purged_at);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$old->id}/restore")
            ->assertStatus(422)->assertJsonPath('errors.code', 'nao_reponivel');
        $this->assertSame(0, $this->svc()->purgeFiles());                     // não repete
    }

    // ───────────────────────────────────────────────────────────── carregamento: hash e teto

    public function test_duplicate_file_refused_before_cap_and_allowed_if_previous_deleted(): void
    {
        Bus::fake([ProcessInvoiceOcrJob::class]);
        config(['services.openai.ocr_monthly_cap' => 2]);
        $url = "/api/v1/companies/{$this->yuko->id}/ocr/invoices";
        $file = fn () => UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4 fatura igual');

        $this->actingAs($this->user, 'sanctum')->post($url, ['file' => $file()], ['Accept' => 'application/json'])->assertOk();
        $first = OcrInvoice::latest('id')->first();
        $this->assertSame(hash('sha256', '%PDF-1.4 fatura igual'), $first->file_sha256);

        $this->actingAs($this->user, 'sanctum')->post($url, ['file' => $file()], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('errors.code', 'ficheiro_duplicado')->assertJsonPath('errors.existing_id', $first->id);
        $this->assertSame(1, OcrInvoice::withTrashed()->count());             // nada criado, nada contado
        Bus::assertDispatchedTimes(ProcessInvoiceOcrJob::class, 1);           // nenhuma IA gasta

        // A outra empresa pode carregar o mesmo ficheiro (o hash é por empresa).
        $this->actingAs($this->otherUser, 'sanctum')->post("/api/v1/companies/{$this->other->id}/ocr/invoices", ['file' => $file()], ['Accept' => 'application/json'])->assertOk();

        // Apagada a anterior → pode carregar outra vez (2.ª leitura do mês na Yuko).
        $first->update(['status' => 'por_validar']);
        $this->svc()->delete($first, $this->user->id);
        $this->actingAs($this->user, 'sanctum')->post($url, ['file' => $file()], ['Accept' => 'application/json'])->assertOk();

        // Teto (2): apagar NÃO devolve a leitura → a 3.ª é recusada (429).
        $last = OcrInvoice::where('company_id', $this->yuko->id)->latest('id')->first();
        $last->update(['status' => 'por_validar']);
        $this->svc()->delete($last, $this->user->id);
        $this->actingAs($this->user, 'sanctum')->post($url, ['file' => UploadedFile::fake()->createWithContent('outra.pdf', '%PDF-1.4 outra')], ['Accept' => 'application/json'])
            ->assertStatus(429);
        $this->actingAs($this->user, 'sanctum')->getJson($url)->assertJsonPath('data.used_this_month', 2);
    }

    // ───────────────────────────────────────────────────────────── apagar várias

    public function test_bulk_delete_with_reasons(): void
    {
        $a = $this->invoice();
        $b = $this->invoice(['status' => 'processing']);
        $c = $this->invoice();
        $this->draftDoc($c, '8001');
        $theirs = $this->invoice([], $this->other);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/bulk-delete", ['ids' => [$a->id, $b->id, $c->id, $theirs->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', [$a->id])
            ->assertJsonCount(3, 'data.skipped')
            ->assertJsonPath('data.skipped.0.id', $b->id)
            ->assertJsonPath('data.skipped.2.reason', 'Fatura não encontrada.');               // de outra empresa
        $this->assertSoftDeleted('ocr_invoices', ['id' => $a->id]);
        $this->assertNotSoftDeleted('ocr_invoices', ['id' => $theirs->id]);
        $this->assertStringContainsString('Anula primeiro', $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices")
            ->json('data.invoices.data.0.delete_block') ?? $this->svc()->blockReason($c));
    }

    // ───────────────────────────────────────────────────────────── duplicadas (F3) e apagar a original

    public function test_duplicate_original_is_the_most_advanced_and_deleting_it_frees_the_other(): void
    {
        $old = $this->invoice(['number' => 'FD M501/1681']);                  // antiga, sem ligação
        $new = $this->invoice(['number' => 'FD M501/1681', 'status' => 'validada']);
        PingwinSupplierDocument::create(['company_id' => $this->yuko->id, 'docheader_id' => '57', 'docconfig_id' => '1209', 'total_cents' => 1000, 'docstatus_id' => '8002']);
        OcrInvoicePingwinLink::create(['company_id' => $this->yuko->id, 'ocr_invoice_id' => $new->id, 'docheader_id' => '57', 'method' => 'total_data', 'confirmed_at' => now()]);
        $links = app(OcrPingwinLinkService::class);

        $this->assertSame('duplicada', $links->link($old->fresh())->fresh()->link_status);   // a antiga é que é a duplicada
        $this->assertSame($new->id, $old->fresh()->duplicate_of_id);
        $this->assertNotSame('duplicada', $links->link($new->fresh())->fresh()->link_status);

        // Apagar a original → a outra deixa de ser duplicada.
        OcrInvoicePingwinLink::query()->delete();
        $this->svc()->delete($new->fresh(), $this->user->id);
        $this->assertNotSame('duplicada', $old->fresh()->link_status);
    }

    // ───────────────────────────────────────────────────────────── reprocessar as antigas

    public function test_reprocess_legacy_dry_run_and_real(): void
    {
        $legacy = $this->invoice(['supplier_nif' => '514148497']);          // sem QR, por validar
        $legacyErr = $this->invoice(['status' => 'erro']);
        $validated = $this->invoice(['status' => 'validada']);              // nunca se toca
        $withQr = $this->invoice(['qr_ok' => true, 'qr_raw' => 'A:1*B:2']);  // já da F2a
        $deleted = $this->invoice();
        $this->svc()->delete($deleted, $this->user->id);
        $test = $this;
        $calls = [];
        $this->app->instance(InvoiceOcrService::class, new class($calls) extends InvoiceOcrService {
            public function __construct(public array &$calls) {}
            public function inspect(string $bytes, string $mime, string $path): array
            {
                return ['pages' => 2, 'text_chars' => 1000, 'qr' => ['ok'], 'lines_source' => 'texto', 'model' => 'gpt-4o-mini'];
            }
            public function process(int $invoiceId): void
            {
                $this->calls[] = $invoiceId;
                OcrInvoice::whereKey($invoiceId)->update(['status' => 'por_validar', 'supplier_nif' => '516182609', 'qr_ok' => true, 'cost_usd' => 0.0013, 'check_status' => 'confere']);
            }
        });
        config(['services.openai.ocr.prices' => 'gpt-4o-mini=0.15/0.60']);

        $this->artisan('ocr:reprocess-legacy', ['company' => $this->yuko->id, '--dry-run' => true])
            ->expectsOutputToContain('2 faturas')->assertExitCode(0);
        $this->assertSame([], $calls);                                        // o dry-run não chama a IA
        $this->assertSame('514148497', $legacy->fresh()->supplier_nif);

        $this->artisan('ocr:reprocess-legacy', ['company' => $this->yuko->id])->expectsOutputToContain('514148497 → 516182609')->assertExitCode(0);
        $this->assertEqualsCanonicalizing([$legacy->id, $legacyErr->id], $calls);
        $this->assertSame('validada', $validated->fresh()->status);
    }

    // ───────────────────────────────────────────────────────────── tenancy

    public function test_tenancy(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->otherUser, 'sanctum')->deleteJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}")->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->deleteJson("/api/v1/companies/{$this->other->id}/ocr/invoices/{$inv->id}")->assertStatus(404);
        $this->svc()->delete($inv, $this->user->id);
        $this->actingAs($this->otherUser, 'sanctum')->postJson("/api/v1/companies/{$this->other->id}/ocr/invoices/{$inv->id}/restore")->assertStatus(404);
        $this->assertSoftDeleted('ocr_invoices', ['id' => $inv->id]);
    }
}
