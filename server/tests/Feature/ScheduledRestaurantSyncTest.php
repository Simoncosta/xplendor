<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScheduledRestaurantSyncJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Services\AlertService;
use App\Services\CoverManagerService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * XPLENDOR — sync automático diário de restauração (fan-out em série). Testa: a seleção de
 * empresas com integração ativa; que uma falha não pára as outras + gera resumo ao dono;
 * e que zero falhas = silêncio. Os serviços de sync são mockados (não bate no PingWin real).
 */
class ScheduledRestaurantSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $planId]);
    }

    private function integration(int $companyId, string $platform, string $status): void
    {
        CompanyIntegration::create([
            'company_id' => $companyId, 'platform' => $platform, 'status' => $status,
            'access_token' => 'x', 'config' => ['username' => 'u', 'database' => 'd'],
        ]);
    }

    /** CoverManager sempre "sem lojas" → o ramo cover é saltado no SyncRestaurantJob. */
    private function mockCoverEmpty(): void
    {
        $this->mock(CoverManagerService::class, function ($m) {
            $m->shouldReceive('companyToken')->andReturn(null);
            $m->shouldReceive('syncableLocations')->andReturn(collect());
        });
    }

    public function test_seleciona_so_integracoes_nao_revogadas(): void
    {
        $a = $this->makeCompany('A'); $this->integration($a->id, 'pingwin', 'active');
        $b = $this->makeCompany('B'); $this->integration($b->id, 'covermanager', 'active');
        $c = $this->makeCompany('C'); $this->integration($c->id, 'pingwin', 'revoked');

        $ids = ScheduledRestaurantSyncJob::activeCompanyIds();
        sort($ids);
        $this->assertSame([$a->id, $b->id], $ids); // C (revoked) fica de fora
    }

    public function test_falha_de_uma_nao_para_as_outras_e_cria_resumo_ao_dono(): void
    {
        $owner = $this->makeCompany('XPLENDOR');
        User::create(['name' => 'Dona', 'email' => 'root@x.pt', 'password' => Hash::make('x'), 'role' => 'root', 'company_id' => $owner->id]);

        $r1 = $this->makeCompany('QUEBOM'); $this->integration($r1->id, 'pingwin', 'active');
        $r2 = $this->makeCompany('OUTRO'); $this->integration($r2->id, 'pingwin', 'active');

        // PingWin falha SEMPRE → cada empresa falha (mas o fan-out continua).
        $this->mock(PingwinService::class, function ($m) {
            $m->shouldReceive('sync')->andThrow(new \RuntimeException('boom'));
        });
        $this->mockCoverEmpty();

        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));

        // Sino por empresa (as duas falharam, ambas processadas → não parou na 1.ª).
        $this->assertDatabaseHas('alerts', ['company_id' => $r1->id, 'title' => 'Falha ao atualizar dados']);
        $this->assertDatabaseHas('alerts', ['company_id' => $r2->id, 'title' => 'Falha ao atualizar dados']);
        // Resumo ao dono (empresa do root), listando as que falharam.
        $this->assertDatabaseHas('alerts', ['company_id' => $owner->id, 'title' => 'Sync automático: falhas']);
    }

    public function test_zero_falhas_nao_cria_resumo(): void
    {
        $owner = $this->makeCompany('XPLENDOR');
        User::create(['name' => 'Dona', 'email' => 'root@x.pt', 'password' => Hash::make('x'), 'role' => 'root', 'company_id' => $owner->id]);

        $r1 = $this->makeCompany('QUEBOM'); $this->integration($r1->id, 'pingwin', 'active');

        // PingWin OK (não lança) → sem problemas → notify=false devolve em silêncio.
        $this->mock(PingwinService::class, function ($m) {
            $m->shouldReceive('sync')->andReturn([]);
        });
        $this->mockCoverEmpty();

        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));

        // Silêncio: nem resumo ao dono, nem sino de falha na empresa.
        $this->assertDatabaseMissing('alerts', ['title' => 'Sync automático: falhas']);
        $this->assertDatabaseMissing('alerts', ['title' => 'Falha ao atualizar dados']);
    }
}
