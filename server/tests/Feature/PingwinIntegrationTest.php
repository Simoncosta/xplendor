<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Jobs\ValidatePingwinConnectionJob;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin (Incremento 1). Cobre o que importa para a SEGURANÇA e o
 * ciclo: (1) a senha é gravada CIFRADA (nunca em texto simples), (2) o gate do
 * módulo 'pingwin' (empresa sem restauração → 403), (3) o ciclo
 * login→lojas→vendas persiste as lojas + resumo, (4) validação falhada → 422,
 * (5) tenancy. O LOGOUT garantido tem smoke test próprio no lado Python
 * (scraper/tests/test_pingwin_logout.py). Aqui o Python é substituído por um
 * fake que sobrepõe invoke() — não há docker/rede nos testes.
 */
class PingwinIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;   // restauração → tem o módulo pingwin
    private Company $auto;    // automotiva → NÃO tem o módulo pingwin
    private User $restoUser;
    private User $autoUser;

    // Só os 3 que VARIAM por restaurante. Os globais (URLs, versão, report_id…)
    // vêm do .env (config('services.pingwin')), NÃO do cadastro por empresa.
    private array $validConfig = [
        'username' => 'operador',
        'database' => 'REST0001',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Ambas nascem automotivas (observer). A "resto" passa a restauração (tem pingwin).
        $this->resto = Company::create(['nipc' => '500002100', 'fiscal_name' => 'Resto Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500002101', 'fiscal_name' => 'Auto Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');

        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
    }

    /** Fake do serviço: sobrepõe invoke() para nunca tocar docker/rede. */
    private function fakeService(bool $connectionOk = true, array $stores = [], array $sales = []): PingwinService
    {
        return new class($connectionOk, $stores, $sales) extends PingwinService {
            public function __construct(private bool $connectionOk, private array $stores, private array $sales) {}

            protected function invoke(array $payload): array
            {
                // A senha TEM de chegar ao Python (por STDIN, aqui simulado no payload).
                if (($payload['mode'] ?? null) === 'validate') {
                    return $this->connectionOk
                        ? ['ok' => true]
                        : ['ok' => false, 'error' => 'credenciais inválidas'];
                }

                // mode === 'sync'
                return [
                    'ok' => true,
                    'date' => $payload['date'] ?? '2026-09-18',
                    'stores' => $this->stores,
                    'sales' => $this->sales,
                ];
            }
        };
    }

    private function bindFake(PingwinService $fake): void
    {
        $this->app->instance(PingwinService::class, $fake);
    }

    private function url(int $companyId, string $path): string
    {
        return "/api/v1/companies/{$companyId}/{$path}";
    }

    // ── 1. Senha cifrada em repouso ───────────────────────────────────────────

    public function test_password_is_stored_encrypted_never_plaintext(): void
    {
        $fake = $this->fakeService(connectionOk: true);
        $fake->saveCredentials($this->resto->id, $this->validConfig, 'SenhaSuperSecreta123');

        // O valor CRU na BD não é a senha (está cifrado pelo cast EncryptedLegacy).
        $raw = DB::table('company_integrations')
            ->where('company_id', $this->resto->id)->where('platform', 'pingwin')
            ->value('access_token');
        $this->assertNotEmpty($raw);
        $this->assertNotSame('SenhaSuperSecreta123', $raw);
        $this->assertStringNotContainsString('SenhaSuperSecreta123', $raw);

        // Mas o modelo decifra-a de volta corretamente.
        $integration = CompanyIntegration::where('company_id', $this->resto->id)->platform('pingwin')->first();
        $this->assertSame('SenhaSuperSecreta123', (string) $integration->access_token);

        // A config guarda SÓ os 3 por-empresa (username/database) — e NUNCA a senha
        // nem os globais (esses vêm do .env, não se guardam por empresa).
        $this->assertSame('REST0001', $integration->config['database']);
        $this->assertSame('operador', $integration->config['username']);
        $this->assertEqualsCanonicalizing(['username', 'database'], array_keys($integration->config));
        $this->assertArrayNotHasKey('password', $integration->config);
        $this->assertArrayNotHasKey('auth_url', $integration->config);
        $this->assertArrayNotHasKey('report_id', $integration->config);
        $this->assertStringNotContainsString('SenhaSuperSecreta123', json_encode($integration->config));
        // Guarda com estado "a validar" (a validação corre depois pela fila).
        $this->assertSame('validating', $integration->status);
    }

    /** Fake que CAPTURA o payload composto que iria para o Python. */
    private function capturingService(): PingwinService
    {
        return new class extends PingwinService {
            public array $seen = [];
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true];
            }
        };
    }

    public function test_payload_composes_globals_from_env_with_per_company(): void
    {
        // Globais como se viessem do .env (config('services.pingwin')). frontend_url
        // fica VAZIO — deriva do database (ver teste dedicado abaixo).
        config()->set('services.pingwin', [
            'auth_url' => 'https://srv-soa.example:8136',
            'api_url' => 'https://srv-soa.example:8136',
            'frontend_url' => null,
            'app_version' => '9.9',
            'application' => 'pbo_soa_2026.0',
            'app_grupopie' => 'PBOWEB',
            'report_id' => 'RPT-GLOBAL',
            'stores' => '',
            'stores_dataset_id' => '',
            'allowed_hosts' => '',
        ]);

        $fake = $this->capturingService();
        $fake->validateConnection(['username' => 'op', 'database' => 'REST0001'], 'segredo');

        $p = $fake->seen;
        // Globais (do .env/config).
        $this->assertSame('https://srv-soa.example:8136', $p['auth_url']);
        $this->assertSame('RPT-GLOBAL', $p['report_id']);
        $this->assertSame('PBOWEB', $p['app_grupopie']);
        // Os 3 por-empresa + a senha decifrada.
        $this->assertSame('op', $p['username']);
        $this->assertSame('REST0001', $p['database']);
        $this->assertSame('segredo', $p['password']);
        $this->assertSame('validate', $p['mode']);
    }

    public function test_frontend_url_is_derived_from_database(): void
    {
        config()->set('services.pingwin', ['frontend_url' => null]); // sem override → derivar

        // Padrão confirmado no config real: database "yuko" → https://yuko.mycloudpie.com
        $fake = $this->capturingService();
        $fake->validateConnection(['username' => 'op', 'database' => 'yuko'], 'segredo');
        $this->assertSame('https://yuko.mycloudpie.com', $fake->seen['frontend_url']);

        // Outro restaurante → outro host (varia por empresa).
        $fake2 = $this->capturingService();
        $fake2->validateConnection(['username' => 'op', 'database' => 'REST0001'], 'segredo');
        $this->assertSame('https://REST0001.mycloudpie.com', $fake2->seen['frontend_url']);
    }

    public function test_frontend_url_override_wins_over_derivation(): void
    {
        // Override global (raro) tem precedência sobre a derivação.
        config()->set('services.pingwin', ['frontend_url' => 'https://especial.exemplo.com']);

        $fake = $this->capturingService();
        $fake->validateConnection(['username' => 'op', 'database' => 'yuko'], 'segredo');
        $this->assertSame('https://especial.exemplo.com', $fake->seen['frontend_url']);
    }

    public function test_result_is_extracted_from_stdout_even_with_warning_noise(): void
    {
        // Simula o stdout com o AVISO inofensivo do xlrd antes do JSON (o cenário
        // real): o extractJson tem de ir buscar só o objeto JSON e ler o "ok".
        $svc = app(PingwinService::class);
        $ref = new \ReflectionMethod(PingwinService::class, 'extractJson');
        $ref->setAccessible(true);

        $noisy = "WARNING *** file size (4768) not 512 + multiple of sector size (512)\n"
            . '{"ok": true, "date": "2026-09-17", "sales": [{"loja": "Tabern Yuko Baixa", "vendas_brutas": 1694.02}]}';
        $data = $ref->invoke($svc, $noisy);

        $this->assertIsArray($data);
        $this->assertTrue($data['ok']);               // sucesso apesar do aviso
        $this->assertSame('Tabern Yuko Baixa', $data['sales'][0]['loja']);

        // Lixo sem JSON → null (não inventa sucesso).
        $this->assertNull($ref->invoke($svc, 'apenas texto sem json'));
    }

    // ── 2. Gate do módulo ─────────────────────────────────────────────────────

    public function test_company_without_restaurant_module_cannot_connect(): void
    {
        $this->bindFake($this->fakeService(connectionOk: true));

        // Empresa automotiva não tem o módulo 'pingwin' → 403 no gate da rota.
        $this->actingAs($this->autoUser, 'sanctum')
            ->postJson($this->url($this->auto->id, 'integrations/pingwin/connect'), $this->validConfig + ['password' => 'x'])
            ->assertStatus(403);

        // E nada foi gravado.
        $this->assertDatabaseMissing('company_integrations', ['company_id' => $this->auto->id, 'platform' => 'pingwin']);
    }

    public function test_restaurant_company_can_connect_and_queues_validation(): void
    {
        Bus::fake(); // não corre a validação inline — queremos ver o estado "a validar".

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id, 'integrations/pingwin/connect'), $this->validConfig + ['password' => 'segredo'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'validating'); // guardado, a validar (não síncrono)

        // Guarda com estado "a validar" e despacha o job de validação (para o worker).
        $this->assertDatabaseHas('company_integrations', ['company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'validating']);
        Bus::assertDispatched(ValidatePingwinConnectionJob::class, fn (ValidatePingwinConnectionJob $j) => $j->companyId === $this->resto->id);
    }

    // ── 3. Ciclo de sincronização persiste lojas + vendas (cêntimos, UPSERT) ──

    public function test_sync_persists_locations_and_daily_sales_in_cents(): void
    {
        Bus::fake(); // isola: a validação por fila não corre inline neste teste.
        // Primeiro liga (grava credenciais, estado "a validar").
        $this->bindFake($this->fakeService(connectionOk: true));
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id, 'integrations/pingwin/connect'), $this->validConfig + ['password' => 'segredo'])
            ->assertStatus(200);

        // Lojas CADASTRADAS à mão (o sync usa os winrest_store_id no "Stores" e liga
        // as vendas por nome). O match automático/descoberta é fase futura.
        \App\Models\PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => '10', 'winrest_name' => 'Loja Lisboa', 'display_name' => 'Lisboa', 'is_active' => true]);
        \App\Models\PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => '20', 'winrest_name' => 'Loja Porto', 'display_name' => 'Porto', 'is_active' => true]);

        // Vendas por loja (extract_store_data) — campos reais.
        $sales = [
            ['loja' => 'Loja Lisboa', 'vendas_brutas' => 1500.00, 'notas_credito' => 10.00, 'descontos' => 55.50,
             'vendas_liquidas' => 1234.50, 'impostos' => 200.00, 'valor_faturado' => 1434.50, 'num_tickets' => 42, 'pos_num_pessoas' => 60],
            ['loja' => 'Loja Porto', 'vendas_brutas' => 1000.00, 'notas_credito' => 0, 'descontos' => 13.00,
             'vendas_liquidas' => 987.00, 'impostos' => 150.00, 'valor_faturado' => 1137.00, 'num_tickets' => 30, 'pos_num_pessoas' => 40],
        ];
        $this->bindFake($this->fakeService(connectionOk: true, sales: $sales));

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id, 'integrations/pingwin/sync'), ['date' => '2026-09-17'])
            ->assertStatus(200)
            ->assertJsonPath('data.date', '2026-09-17');

        $this->assertDatabaseCount('pingwin_locations', 2);
        $this->assertDatabaseCount('pingwin_daily_sales', 2);
        // Regista o dia sincronizado (portão de honestidade).
        $this->assertTrue(
            \App\Models\PingwinSyncRun::where('company_id', $this->resto->id)
                ->whereDate('business_date', '2026-09-17')->where('status', 'success')->exists()
        );

        $lisboa = \App\Models\PingwinLocation::where('company_id', $this->resto->id)->where('winrest_store_id', '10')->first();
        $this->assertNotNull($lisboa);
        $this->assertSame('Lisboa', $lisboa->display_name);
        $sale = \App\Models\PingwinDailySale::where('location_id', $lisboa->id)->whereDate('business_date', '2026-09-17')->first();
        // Dinheiro em CÊNTIMOS inteiros (€ → int(round(x*100))).
        $this->assertSame(123450, $sale->net_cents);
        $this->assertSame(143450, $sale->invoiced_cents);
        $this->assertSame(150000, $sale->gross_cents);
        $this->assertSame(42, $sale->tickets_count);
        $this->assertSame(60, $sale->covers_count);

        // UPSERT idempotente: re-sincronizar o MESMO dia não duplica.
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id, 'integrations/pingwin/sync'), ['date' => '2026-09-17'])
            ->assertStatus(200);
        $this->assertDatabaseCount('pingwin_daily_sales', 2);
        $this->assertDatabaseCount('pingwin_sync_runs', 1);

        // A integração fica marcada como sincronizada.
        $integration = CompanyIntegration::where('company_id', $this->resto->id)->platform('pingwin')->first();
        $this->assertNotNull($integration->last_synced_at);
        $this->assertSame('active', $integration->status);
    }

    // ── 4. Connect é ASSÍNCRONO: guarda e não valida sincronamente ────────────

    public function test_connect_does_not_validate_synchronously(): void
    {
        // Mesmo com credenciais que virão a falhar, o connect NÃO rejeita já: guarda
        // "a validar" e delega ao job (a validação síncrona no php-fpm falharia por
        // não ter docker socket). O motivo real chega depois, na notificação.
        Bus::fake();

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id, 'integrations/pingwin/connect'), $this->validConfig + ['password' => 'errada'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'validating');

        $this->assertDatabaseHas('company_integrations', [
            'company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'validating',
        ]);
        Bus::assertDispatched(ValidatePingwinConnectionJob::class);
    }

    // ── 4b. O estado (index) expõe config para pré-preencher, NUNCA a senha ────

    public function test_index_exposes_config_but_never_password(): void
    {
        $this->fakeService(connectionOk: true)->saveCredentials($this->resto->id, $this->validConfig, 'SenhaSuperSecreta123');
        // Simula a validação já concluída com sucesso (estado ligado).
        CompanyIntegration::where('company_id', $this->resto->id)->platform('pingwin')->update(['status' => 'active']);

        $res = $this->actingAs($this->restoUser, 'sanctum')
            ->getJson($this->url($this->resto->id, 'integrations/pingwin'))
            ->assertStatus(200)
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.config.database', 'REST0001')
            ->assertJsonPath('data.config.username', 'operador');

        // A senha NUNCA aparece na resposta (nem em config, nem em lado nenhum).
        $this->assertStringNotContainsString('SenhaSuperSecreta123', $res->getContent());
        $this->assertArrayNotHasKey('password', $res->json('data.config'));
        $this->assertArrayNotHasKey('access_token', $res->json('data'));
    }

    // ── 5. Tenancy ────────────────────────────────────────────────────────────

    public function test_user_cannot_connect_for_another_company(): void
    {
        $this->bindFake($this->fakeService(connectionOk: true));

        // Utilizador da resto a tentar cadastrar na auto → 403 (gate do módulo
        // recusa primeiro, pois a auto não tem pingwin; o guard tenant reforça).
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->auto->id, 'integrations/pingwin/connect'), $this->validConfig + ['password' => 'x'])
            ->assertStatus(403);
    }
}
