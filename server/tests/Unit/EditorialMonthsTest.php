<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Models\EditorialMonth;
use App\Services\EditorialAnchorResolver;
use App\Services\EditorialLineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * XPLENDOR — Linha Editorial (B2): máquina de estados dos meses. Testa a SEQUÊNCIA
 * ESTRITA (só abre o corrente ou o seguinte a um aberto), o FECHO EM CASCATA (fecha
 * também os abertos posteriores, sem apagar) e a JANELA DESLIZANTE (um mês que cai no
 * passado deixa de ser abrível/fechável, mas a linha persiste no histórico).
 * Tempo congelado (setTestNow) para a janela ser determinística.
 */
class EditorialMonthsTest extends TestCase
{
    use RefreshDatabase;

    private EditorialLineService $service;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Congela o "agora" a meio de Janeiro/2026 (meio-dia evita fronteiras de fuso).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 12:00:00', 'Europe/Lisbon'));

        $this->service = new EditorialLineService(new EditorialAnchorResolver());

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc' => '500000001', 'fiscal_name' => 'Test Company Lda', 'plan_id' => $planId,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(); // limpa
        parent::tearDown();
    }

    /** Atalho: encontra o mês pela chave 'Y-m' no payload do monthsState. */
    private function month(array $months, string $key): array
    {
        foreach ($months as $m) {
            if ($m['month_key'] === $key) {
                return $m;
            }
        }
        $this->fail("Mês {$key} não está na janela.");
    }

    public function test_janela_tem_12_meses_a_partir_do_corrente(): void
    {
        $months = $this->service->monthsState($this->company);
        $this->assertCount(12, $months);
        $this->assertSame('2026-01', $months[0]['month_key']);
        $this->assertTrue($months[0]['is_current']);
        $this->assertSame('2026-12', $months[11]['month_key']);
    }

    public function test_estado_inicial_so_o_corrente_e_abrivel(): void
    {
        $months = $this->service->monthsState($this->company);
        $this->assertTrue($this->month($months, '2026-01')['can_open']);   // corrente abrível
        $this->assertFalse($this->month($months, '2026-02')['can_open']);  // seguinte ainda não
        $this->assertFalse($this->month($months, '2026-03')['can_open']);
        $this->assertSame('closed', $months[0]['state']);
    }

    public function test_abrir_corrente_depois_seguinte_em_sequencia(): void
    {
        $m = $this->service->openMonth($this->company, 2026, 1);
        $this->assertSame('open', $this->month($m, '2026-01')['state']);
        $this->assertTrue($this->month($m, '2026-02')['can_open']);  // agora o seguinte é abrível
        $this->assertFalse($this->month($m, '2026-03')['can_open']); // o de depois ainda não

        $m = $this->service->openMonth($this->company, 2026, 2);
        $this->assertSame('open', $this->month($m, '2026-02')['state']);
        $this->assertTrue($this->month($m, '2026-03')['can_open']);
    }

    public function test_recusa_abrir_a_frente_sem_os_do_meio(): void
    {
        $this->service->openMonth($this->company, 2026, 1);

        $this->expectException(ValidationException::class);
        // Tenta saltar directo para Abril sem Fev/Mar → sequência estrita recusa.
        $this->service->openMonth($this->company, 2026, 4);
    }

    public function test_fechar_em_cascata_fecha_os_seguintes_e_persiste(): void
    {
        $this->service->openMonth($this->company, 2026, 1);
        $this->service->openMonth($this->company, 2026, 2);
        $m = $this->service->openMonth($this->company, 2026, 3);

        // Antes de fechar: Fev avisa que fecha também Mar; Mar (último aberto) não avisa.
        $this->assertSame(['2026-03'], $this->month($m, '2026-02')['closes_also']);
        $this->assertSame([], $this->month($m, '2026-03')['closes_also']);

        // Fecha Fev → cascata fecha Mar; Jan (anterior) fica aberto.
        $m = $this->service->closeMonth($this->company, 2026, 2);
        $this->assertSame('open', $this->month($m, '2026-01')['state']);
        $this->assertSame('closed', $this->month($m, '2026-02')['state']);
        $this->assertSame('closed', $this->month($m, '2026-03')['state']);

        // Persiste após re-leitura (não confia no retorno em memória).
        $again = $this->service->monthsState($this->company);
        $this->assertSame('open', $this->month($again, '2026-01')['state']);
        $this->assertSame('closed', $this->month($again, '2026-03')['state']);

        // Fechar não apaga: a linha de Mar existe com estado 'closed' (trabalho da B3 sobrevive).
        $this->assertDatabaseHas('editorial_months', [
            'company_id' => $this->company->id, 'year' => 2026, 'month' => 3, 'state' => 'closed',
        ]);
    }

    public function test_reabrir_mantem_a_sequencia(): void
    {
        $this->service->openMonth($this->company, 2026, 1);
        $this->service->openMonth($this->company, 2026, 2);
        $this->service->closeMonth($this->company, 2026, 2);

        // Depois de fechar Fev, o abrível volta a ser Fev (o 1.º fechado a seguir a Jan).
        $m = $this->service->monthsState($this->company);
        $this->assertTrue($this->month($m, '2026-02')['can_open']);

        $m = $this->service->openMonth($this->company, 2026, 2); // reabre sem erro
        $this->assertSame('open', $this->month($m, '2026-02')['state']);
    }

    public function test_janela_deslizante_passado_deixa_de_ser_editavel_mas_persiste(): void
    {
        // Em Jan/2026 abre Janeiro.
        $this->service->openMonth($this->company, 2026, 1);
        $this->assertDatabaseHas('editorial_months', [
            'company_id' => $this->company->id, 'year' => 2026, 'month' => 1, 'state' => 'open',
        ]);

        // O tempo avança para Fev/2026 → a janela passa a 2026-02..2027-01; Jan cai no passado.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-15 12:00:00', 'Europe/Lisbon'));

        $months = $this->service->monthsState($this->company);
        $this->assertSame('2026-02', $months[0]['month_key']); // janela deslizou
        // Janeiro já não aparece na janela ativa.
        foreach ($months as $m) {
            $this->assertNotSame('2026-01', $m['month_key']);
        }

        // A linha de Janeiro PERSISTE no histórico (não foi apagada pelo deslize).
        $this->assertDatabaseHas('editorial_months', [
            'company_id' => $this->company->id, 'year' => 2026, 'month' => 1, 'state' => 'open',
        ]);

        // E deixa de ser abrível/fechável (fora da janela → 422 humano).
        try {
            $this->service->closeMonth($this->company, 2026, 1);
            $this->fail('Devia recusar fechar um mês do passado (fora da janela).');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('janela de meses mudou', $e->validator->errors()->first());
        }
    }

    public function test_recusa_mes_fora_da_janela_futura(): void
    {
        $this->expectException(ValidationException::class);
        // 2028 está muito além dos 12 meses → fora da janela.
        $this->service->openMonth($this->company, 2028, 6);
    }
}
