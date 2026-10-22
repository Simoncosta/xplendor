<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScanFinancingDocumentJob;
use App\Mail\AgencyNoticeMail;
use App\Models\Alert;
use App\Models\Car;
use App\Models\Company;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\Antivirus\AntivirusHealth;
use App\Services\Financing\Antivirus\ClamdUnavailable;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Financing\FinancingFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O antivírus dos documentos de financiamento, com um analisador simulado: com o clamd em baixo
 * a análise volta para a fila com espera crescente, o ficheiro fica e não se descarrega; uma
 * resposta inesperada nunca dá "limpo"; o aviso ao root aos 15 minutos (uma vez, sem dados
 * pessoais) e quando volta; as análises em atraso voltam para a fila; o cliente vê "a verificar".
 */
class AntivirusTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $seller;
    private User $root;
    private FinancingLink $link;
    private TestScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        Cache::flush();
        config(['storage_targets.private_disk' => 'local', 'financing.require_antivirus' => true]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019331', 'fiscal_name' => 'Stand Exemplo Lda', 'trade_name' => 'Exemplo', 'plan_id' => $plan, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($this->company->id, 'stock');
        $team = Company::create(['nipc' => '500019332', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'email' => 'vendedor@exemplo.pt']);
        $this->root = User::factory()->create(['company_id' => $team->id, 'role' => 'root', 'email' => 'root@xplendor.invalid']);
        \App\Access\CompatibilityMigration::run();
        $car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id, 'seller_user_id' => $this->seller->id]));
        $this->scanner = new TestScanner();
        $this->app->instance(VirusScanner::class, $this->scanner);
        [, $hash] = FinancingLink::newToken();
        $request = \App\Models\FinancingRequest::create(['company_id' => $this->company->id, 'car_id' => $car->id, 'created_by_user_id' => $this->seller->id,
            'purpose' => 'financiamento', 'items' => ['cc']]);
        $this->link = FinancingLink::create(['financing_request_id' => $request->id, 'company_id' => $this->company->id, 'car_id' => $car->id, 'created_by_user_id' => $this->seller->id,
            'customer_name' => 'Cliente Teste', 'requested' => ['items' => ['cc'], 'other' => null], 'expires_at' => now()->addDays(7),
            'max_files' => 20, 'max_bytes' => 100 * 1024 * 1024, 'consented_at' => now(), ...$hash]);
    }

    private function pendingDocument(): FinancingDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $doc = FinancingFiles::store($this->link, 'cc', new UploadedFile($path, 'cc.pdf', null, null, true), 'pdf', 'application/pdf');
        $doc->forceFill(['scan_status' => FinancingDocument::SCAN_PENDING])->save();

        return $doc;
    }

    public function test_with_clamd_down_the_scan_goes_back_to_the_queue_with_a_growing_wait_and_the_file_stays(): void
    {
        $doc = $this->pendingDocument();
        $this->scanner->mode = 'down';
        foreach ([1 => 30, 2 => 60, 3 => 120, 4 => 300, 5 => 600, 6 => 900, 9 => 900] as $attempt => $delay) {
            $job = (new ScanFinancingDocumentJob($doc->id))->withFakeQueueInteractions();
            $job->job->attempts = $attempt;
            $job->handle($this->scanner, app(\App\Services\AlertService::class));
            $job->assertReleased(delay: $delay);
        }
        $fresh = $doc->fresh();
        $this->assertSame(FinancingDocument::SCAN_PENDING, $fresh->scan_status);
        $this->assertFalse($fresh->downloadable());
        Storage::disk('local')->assertExists(StoredFile::findOrFail($fresh->stored_file_id)->path);
        $this->assertNotNull(AntivirusHealth::downSince());
        $this->assertTrue((new ScanFinancingDocumentJob($doc->id))->retryUntil() > now()->addDays(6), 'tenta durante dias, não só 3 vezes');
    }

    public function test_an_unexpected_reply_is_never_clean_and_gives_up_after_10_attempts(): void
    {
        $doc = $this->pendingDocument();
        $this->scanner->mode = 'weird';
        $job = (new ScanFinancingDocumentJob($doc->id))->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle($this->scanner, app(\App\Services\AlertService::class));
        $job->assertReleased(delay: 30);
        $job = (new ScanFinancingDocumentJob($doc->id))->withFakeQueueInteractions();
        $job->job->attempts = 10;
        $job->handle($this->scanner, app(\App\Services\AlertService::class));
        $job->assertFailed();
        $this->assertSame(FinancingDocument::SCAN_PENDING, $doc->fresh()->scan_status);
    }

    public function test_when_clamd_answers_clean_the_document_can_be_downloaded(): void
    {
        $doc = $this->pendingDocument();
        AntivirusHealth::markDown();
        (new ScanFinancingDocumentJob($doc->id))->handle($this->scanner, app(\App\Services\AlertService::class));
        $this->assertSame(FinancingDocument::SCAN_CLEAN, $doc->fresh()->scan_status);
        $this->assertTrue($doc->fresh()->downloadable());
        $this->assertNull(AntivirusHealth::downSince());
    }

    public function test_an_infected_file_notifies_the_seller_by_email_and_bell(): void
    {
        config(['scheduler.external_jobs' => true]);
        $doc = $this->pendingDocument();
        $this->scanner->mode = 'infected';
        (new ScanFinancingDocumentJob($doc->id))->handle($this->scanner, app(\App\Services\AlertService::class));
        $this->assertSame(FinancingDocument::SCAN_INFECTED, $doc->fresh()->scan_status);
        $this->assertSame(1, Alert::where('title', 'Documento recusado pelo antivírus')->count());
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('vendedor@exemplo.pt') && ! str_contains(implode(' ', $m->lines), 'Cliente Teste'));
    }

    public function test_with_external_calls_off_the_notices_stay_in_the_bell_without_email(): void
    {
        config(['scheduler.external_jobs' => false]);
        $doc = $this->pendingDocument();
        $this->scanner->mode = 'infected';
        (new ScanFinancingDocumentJob($doc->id))->handle($this->scanner, app(\App\Services\AlertService::class));
        $this->assertSame(1, Alert::where('title', 'Documento recusado pelo antivírus')->count());

        $this->pendingDocument();
        $this->scanner->mode = 'down';
        $health = app(AntivirusHealth::class);
        $health->watch();
        $this->travel(16)->minutes();
        $this->assertTrue($health->watch()['alerted']);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_the_root_is_warned_once_after_15_minutes_down_and_when_it_comes_back(): void
    {
        config(['scheduler.external_jobs' => true]);
        $this->pendingDocument();
        $this->scanner->mode = 'down';
        $health = app(AntivirusHealth::class);
        $this->assertFalse($health->watch()['alerted']);
        $this->travel(14)->minutes();
        $this->assertFalse($health->watch()['alerted']);
        $this->travel(2)->minutes();
        $this->assertTrue($health->watch()['alerted']);
        $this->assertFalse($health->watch()['alerted'], 'uma só vez');
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('root@xplendor.invalid') && $m->title === 'Antivírus indisponível'
            && str_contains(implode(' ', $m->lines), 'Documentos à espera de análise: 1') && ! str_contains(implode(' ', $m->lines), 'Cliente Teste'));

        Queue::fake();
        $this->scanner->mode = 'clean';
        $this->travel(40)->minutes();
        $r = $health->watch();
        $this->assertTrue($r['up'] && $r['recovered']);
        $this->assertSame(1, $r['requeued'], 'a análise em atraso volta para a fila');
        Queue::assertPushed(ScanFinancingDocumentJob::class, 1);
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('root@xplendor.invalid') && $m->title === 'Antivírus disponível de novo');
        $this->assertNull(AntivirusHealth::downSince());
    }

    public function test_documents_that_arrived_without_a_scanner_are_scanned_when_it_answers(): void
    {
        $doc = $this->pendingDocument();
        $doc->forceFill(['scan_status' => FinancingDocument::SCAN_NONE])->save();
        Queue::fake();
        $this->assertSame(1, app(AntivirusHealth::class)->watch()['requeued']);
        $this->assertSame(FinancingDocument::SCAN_PENDING, $doc->fresh()->scan_status);
        $this->assertFalse($doc->fresh()->downloadable(), 'até estar limpo');
    }

    public function test_the_client_sees_the_file_being_checked_until_the_antivirus_answers(): void
    {
        Queue::fake();
        [$token] = [$this->tokenFor($this->link)];
        $path = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $res = $this->withHeaders(['X-Financing-Token' => $token, 'Accept' => 'application/json'])
            ->post('/api/public/financing/documents', ['kind' => 'cc', 'file' => new UploadedFile($path, 'cc.pdf', null, null, true)])->assertCreated();
        $this->assertSame('em_analise', $res->json('data.state'));
        $this->assertIsInt($res->json('data.id'));
        Queue::assertPushed(ScanFinancingDocumentJob::class);
        $this->assertFalse(FinancingDocument::sole()->downloadable());
    }

    private function tokenFor(FinancingLink $link): string
    {
        [$token, $hash] = FinancingLink::newToken();
        $link->forceFill($hash)->save();

        return $token;
    }
}

/** Analisador simulado: limpo, infetado, em baixo ou com uma resposta inesperada. */
final class TestScanner implements VirusScanner
{
    public string $mode = 'clean';

    public function configured(): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return $this->mode !== 'down';
    }

    public function scan(string $disk, string $path): string
    {
        return match ($this->mode) {
            'down' => throw new ClamdUnavailable('em baixo'),
            'weird' => throw new \RuntimeException('Resposta inesperada do antivírus: INSTREAM size limit exceeded. ERROR'),
            'infected' => self::INFECTED,
            default => self::CLEAN,
        };
    }

    public function lastSignature(): ?string
    {
        return $this->mode === 'infected' ? 'Teste-Simulado' : null;
    }
}
