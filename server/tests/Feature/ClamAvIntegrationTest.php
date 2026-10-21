<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\Antivirus\AntivirusSamples;
use App\Services\Financing\Antivirus\ClamAvScanner;
use App\Services\Financing\Antivirus\ClamdClient;
use App\Services\Financing\Antivirus\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Integração com o ClamAV VERDADEIRO (o contentor xplendor-clamav, CLAMAV_HOST e CLAMAV_PORT).
 * Só corre quando o clamd responde; fora disso, os testes ficam saltados. O EICAR tem de dar
 * infetado e um PDF limpo, limpo; e o fluxo completo pela página pública com um PDF que traz o
 * EICAR anexado (passa a verificação de tipo e o ClamAV recusa-o).
 */
class ClamAvIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ClamdClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new ClamdClient((string) env('CLAMAV_HOST', 'clamav'), (int) env('CLAMAV_PORT', 3310), 30);
        if (! (new ClamdClient((string) env('CLAMAV_HOST', 'clamav'), (int) env('CLAMAV_PORT', 3310), 2))->ping()) {
            $this->markTestSkipped('ClamAV indisponível (o contentor xplendor-clamav não responde): integração saltada.');
        }
    }

    public function test_eicar_is_infected_and_a_clean_pdf_is_clean(): void
    {
        $this->assertStringStartsWith('ClamAV ', $this->client->version());
        [$result, $signature] = $this->client->scanString(AntivirusSamples::eicar());
        $this->assertSame(VirusScanner::INFECTED, $result);
        $this->assertStringContainsStringIgnoringCase('eicar', (string) $signature);
        $this->assertSame([VirusScanner::CLEAN, null], $this->client->scanString(AntivirusSamples::cleanPdf()));
        $this->assertSame(VirusScanner::INFECTED, $this->client->scanString(AntivirusSamples::eicarPdf())[0]);
    }

    public function test_the_full_public_flow_with_the_real_scanner(): void
    {
        Mail::fake();
        Storage::fake('local');
        config(['storage_targets.private_disk' => 'local', 'financing.require_antivirus' => true]);
        $this->app->instance(VirusScanner::class, new ClamAvScanner($this->client));
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::create(['nipc' => '500019341', 'fiscal_name' => 'Stand Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($company->id, 'stock');
        $seller = User::factory()->create(['company_id' => $company->id, 'role' => 'user']);
        \App\Access\CompatibilityMigration::run();
        $car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $company->id, 'seller_user_id' => $seller->id]));
        [$token, $hash] = FinancingLink::newToken();
        FinancingLink::create(['company_id' => $company->id, 'car_id' => $car->id, 'created_by_user_id' => $seller->id, 'customer_name' => 'Cliente Teste',
            'requested' => ['items' => ['cc', 'recibos'], 'other' => null], 'expires_at' => now()->addDays(7), 'max_files' => 20, 'max_bytes' => 104857600,
            'consented_at' => now(), ...$hash]);
        $send = function (string $kind, string $name, string $bytes) use ($token) {
            $path = tempnam(sys_get_temp_dir(), 'av');
            file_put_contents($path, $bytes);

            return $this->withHeaders(['X-Financing-Token' => $token, 'Accept' => 'application/json'])
                ->post('/api/public/financing/documents', ['kind' => $kind, 'file' => new UploadedFile($path, $name, null, null, true)]);
        };

        // A fila de teste é síncrona: a análise corre logo a seguir ao envio.
        $send('recibos', 'recibo.pdf', AntivirusSamples::cleanPdf())->assertCreated();
        $infected = $send('cc', 'cc.pdf', AntivirusSamples::eicarPdf())->assertCreated();

        $clean = FinancingDocument::where('kind', 'recibos')->sole();
        $this->assertSame(FinancingDocument::SCAN_CLEAN, $clean->scan_status);
        $this->assertTrue($clean->downloadable());
        $bad = FinancingDocument::where('kind', 'cc')->sole();
        $this->assertSame(FinancingDocument::SCAN_INFECTED, $bad->scan_status);
        $this->assertNull($bad->stored_file_id, 'o ficheiro infetado é apagado');
        $this->assertSame(1, StoredFile::where('source', 'financiamento')->count());
        $this->assertSame('recusado', $infected->json('data.items.0.sent.0.state'));
    }
}
