<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CmReservationChannelDaily;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\RestaurantCompassText;
use App\Models\RestaurantDataQuality;
use App\Models\RestaurantFamilyCategory;
use App\Models\RestaurantSignal;
use App\Models\User;
use App\Services\CompanyModuleService;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bússola comum (parte 2): a restauração não pode mudar de comportamento. Este teste de
 * caracterização grava as respostas da Bússola da restauração ANTES da separação em núcleo
 * comum e fornecedores, e confirma depois que são iguais byte a byte: a página completa, o
 * resumo, só as jogadas (o dashboard), uma loja, sem Finanças, o painel dos sinais e o mapa.
 * Para regravar (só quando a restauração muda de propósito): BUSSOLA_GOLDEN=write.
 */
class RestaurantCompassGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const DIR = __DIR__ . '/../Fixtures/bussola/restauracao';

    private Company $company;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        // Números fixos (a empresa, as lojas e os sinais aparecem nas chaves e nas respostas).
        $this->company = new Company(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'trade_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->company->forceFill(['id' => 990001])->save();
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        app(CompanyModuleService::class)->enable($this->company->id, 'linha_editorial');
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->baixa = (new PingwinLocation(['company_id' => $this->company->id, 'winrest_store_id' => '1', 'display_name' => 'Baixa', 'is_active' => true, 'opened_on' => '2026-01-10']))->forceFill(['id' => 990011]);
        $this->baixa->save();
        $this->costa = (new PingwinLocation(['company_id' => $this->company->id, 'winrest_store_id' => '2', 'display_name' => 'Costa', 'is_active' => true, 'opened_on' => '2026-01-10']))->forceFill(['id' => 990012]);
        $this->costa->save();
        $this->seedScenario();
    }

    private int $nextSignalId = 990100;

    private function signal(PingwinLocation $l, string $key, string $type, string $confidence, array $numbers, string $sentence, array $sample = []): void
    {
        RestaurantSignal::forceCreate(['id' => $this->nextSignalId++,'company_id' => $this->company->id, 'location_id' => $l->id, 'type' => $type, 'signal_key' => $key,
            'kind' => in_array($type, ['weak_period', 'item_up', 'item_down', 'stale_item'], true) ? 'suggestion' : 'info',
            'confidence' => $confidence, 'title' => "Título {$key}", 'sentence' => $sentence, 'numbers' => $numbers, 'sample' => $sample,
            'theme' => "Tema {$key}", 'suggested_date' => '2026-10-01', 'priority' => 0, 'computed_at' => now()]);
    }

    private function sales(PingwinLocation $l, string $product, string $name, string $family, int $before, int $now): void
    {
        foreach (CarbonPeriod::create('2026-08-13', '2026-10-07') as $d) {
            PingwinItemSalesDay::firstOrCreate(['location_id' => $l->id, 'business_date' => $d->toDateString()], ['company_id' => $this->company->id, 'status' => 'ok']);
        }
        foreach ([['2026-08-20', $before], ['2026-09-20', $now]] as [$date, $qty]) {
            PingwinItemSale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'product_pingwin_id' => $product,
                'product_name' => $name, 'family_pingwin_id' => $family, 'family_path' => "Família \\ Comidas \\ {$family}", 'quantity' => $qty, 'net_cents' => $qty * 1000]);
        }
    }

    /** Um cenário com todos os tipos de jogada, os sinais informativos, as categorias e uma frase da IA. */
    private function seedScenario(): void
    {
        $this->sales($this->baixa, 'S1', 'Cheesecake', 'DOCES', 100, 40);
        $this->sales($this->baixa, 'S2', 'Mousse', 'DOCES', 80, 30);
        $this->sales($this->baixa, 'P1', 'Bitoque', 'PRATOS', 50, 90);
        $this->sales($this->costa, 'P1', 'Bitoque', 'PRATOS', 40, 70);
        $this->sales($this->costa, 'V1', 'Vinho da casa', 'VINHOS', 30, 0);
        foreach ([['DOCES', 'sobremesas'], ['PRATOS', 'pratos'], ['VINHOS', 'vinho']] as [$family, $category]) {
            $row = RestaurantFamilyCategory::firstOrNew(['company_id' => $this->company->id, 'family_pingwin_id' => $family]);
            $row->family_path = "Família \\ Comidas \\ {$family}";
            $row->forceFill(['category' => $category, 'confirmed_at' => now()])->save();
        }
        foreach ([[$this->baixa, 3, 'almoco', 'alta', 50], [$this->baixa, 2, 'jantar', 'media', 30], [$this->costa, 4, 'almoco', 'alta', 40], [$this->costa, 6, 'jantar', 'media', 25]] as [$l, $wd, $shift, $conf, $pct]) {
            $this->signal($l, "weak_period:{$l->id}:{$wd}:{$shift}", 'weak_period', $conf,
                ['weekday' => $wd, 'shift' => $shift, 'mode' => 'hours', 'avg_cents' => (100 - $pct) * 900, 'mean_cents' => 90000, 'pct_below' => $pct, 'offset_days' => 1],
                "O turno ficou {$pct}% abaixo da média (média de 450 € contra 900 €, em 8 semanas).",
                ['from' => '2026-08-13', 'to' => '2026-10-07', 'occurrences' => 8, 'special_days_excluded' => ['2026-08-15']]);
        }
        foreach ([[$this->baixa, 'item_down', 'S1', 'Cheesecake', 100, 40], [$this->baixa, 'item_down', 'S2', 'Mousse', 80, 30],
            [$this->baixa, 'item_up', 'P1', 'Bitoque', 50, 90], [$this->costa, 'item_up', 'P1', 'Bitoque', 40, 70]] as [$l, $type, $p, $name, $before, $now]) {
            $var = round(($now - $before) / $before * 100, 1);
            $this->signal($l, "{$type}:{$l->id}:{$p}", $type, 'alta', ['product_id' => $p, 'name' => $name, 'qty_now' => $now, 'qty_before' => $before,
                'net_now_cents' => $now * 1000, 'net_before_cents' => $before * 1000, 'variation_pct' => $var, 'store_variation_pct' => -4.0],
                "{$name}: de {$before} para {$now} unidades (de " . ($before * 10) . " € para " . ($now * 10) . " €).", ['from' => '2026-09-10', 'to' => '2026-10-07']);
        }
        $this->signal($this->costa, "stale_item:{$this->costa->id}:V1", 'stale_item', 'media',
            ['product_id' => 'V1', 'name' => 'Vinho da casa', 'qty_now' => 0, 'qty_before' => 30, 'net_now_cents' => 0, 'net_before_cents' => 30000, 'days' => 35],
            'O Vinho da casa vendia 30 unidades e não vende há 35 dias.', ['from' => '2026-08-13', 'to' => '2026-10-07']);
        $this->signal($this->baixa, "lead_time:{$this->baixa->id}", 'lead_time', 'alta',
            ['total' => 200, 'shares' => ['same_day' => 42.5, 'd1_2' => 30.0, 'd3_7' => 20.0, 'd8_30' => 7.5, 'd31_plus' => 0.0]], '42,5% das reservas são feitas no próprio dia.');
        $this->signal($this->baixa, "channels:{$this->baixa->id}", 'channels', 'alta',
            ['total' => 200, 'channels' => ['software' => ['reservations' => 120, 'share_pct' => 60.0], 'moduloweb' => ['reservations' => 60, 'share_pct' => 30.0],
                'walk in' => ['reservations' => 20, 'share_pct' => 10.0]]], 'A maior parte das reservas é registada pela equipa.');
        CmReservationChannelDaily::query()->exists(); // garante a classe (sem dados: o bloco fica vazio)
        RestaurantCompassText::create(['company_id' => $this->company->id, 'play_key' => 'item_down:cat:sobremesas', 'text' => 'Dê destaque às sobremesas da casa nas redes sociais.', 'generated_at' => now()]);
        RestaurantDataQuality::updateOrCreate(['company_id' => $this->company->id], ['signals_computed_at' => now(), 'signals_availability' => []]);
    }

    private function users(): array
    {
        $admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $profile = \App\Models\PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Marketing sem Finanças', 'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions(['bussola.ver', 'bussola.criar', 'bussola.editar', 'editorial.ver']);
        $marketing = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);

        return ['admin' => $admin, 'sem_financas' => $marketing];
    }

    public function test_the_restaurant_compass_answers_exactly_as_before(): void
    {
        $base = "/api/v1/companies/{$this->company->id}";
        $cases = [
            'pagina' => '/marketing/bussola',
            'resumo' => '/marketing/bussola?summary=1',
            'jogadas' => '/marketing/bussola?plays_only=1',
            'loja' => "/marketing/bussola?location_id={$this->baixa->id}",
            'sinais' => '/integrations/pingwin/signals',
            'mapa' => '/marketing/bussola/heatmap?weeks=8',
        ];
        $write = getenv('BUSSOLA_GOLDEN') === 'write';
        foreach ($this->users() as $who => $user) {
            foreach ($cases as $name => $path) {
                $json = json_encode($this->actingAs($user, 'sanctum')->getJson($base . $path)->assertOk()->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                $file = self::DIR . "/{$who}-{$name}.json";
                if ($write) {
                    @mkdir(self::DIR, 0775, true);
                    file_put_contents($file, $json);

                    continue;
                }
                $this->assertFileExists($file, 'Corra uma vez com BUSSOLA_GOLDEN=write antes da separação.');
                $this->assertSame(file_get_contents($file), $json, "A resposta {$who}-{$name} da Bússola da restauração mudou.");
            }
        }
        if ($write) {
            $this->markTestSkipped('Respostas gravadas.');
        }
    }
}
