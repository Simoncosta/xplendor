<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinLocation;
use App\Services\Restaurant\Menu\MenuEngineeringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Fatia 3 (SÓ LEITURA do espelho): calcula os snapshots da engenharia de menu. Corre à noite
 * depois do sync das fichas, a pedido ("Recalcular"), e quando se grava uma ficha, se confirma um fator
 * ou um rendimento, ou se mudam as exclusões. Sem período: o por omissão (últimos 90 dias), para a
 * empresa toda e cada loja, nos dois modos de preço.
 */
class ComputeMenuEngineeringJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1200;
    public int $uniqueFor = 900;

    /**
     * @param  list<int|null>|null  $locations  null = a empresa toda e cada loja
     * @param  list<string>  $modes
     */
    public function __construct(public int $companyId, public ?string $from = null, public ?string $to = null, public ?array $locations = null,
        public array $modes = ['media', 'ultimo']) {}

    public static function statusKey(int $companyId): string
    {
        return "menu-engineering:running:{$companyId}";
    }

    public function uniqueId(): string
    {
        return $this->companyId . ':' . md5(json_encode([$this->from, $this->to, $this->locations, $this->modes]));
    }

    /** Recalcula o por omissão (para usar depois de gravar uma ficha ou confirmar um fator). */
    public static function forDefaults(int $companyId): void
    {
        Cache::put(self::statusKey($companyId), ['status' => 'queued', 'at' => now()->toIso8601String()], now()->addHour());
        self::dispatch($companyId);
    }

    public function handle(): void
    {
        $service = new MenuEngineeringService($this->companyId);
        [$defFrom, $defTo] = $service->defaultPeriod();
        $from = $this->from ?? $defFrom;
        $to = $this->to ?? $defTo;
        $locations = $this->locations ?? array_merge([null], PingwinLocation::where('company_id', $this->companyId)->orderBy('id')->pluck('id')->all());
        Cache::put(self::statusKey($this->companyId), ['status' => 'running', 'at' => now()->toIso8601String()], now()->addHour());
        try {
            foreach ($locations as $loc) {
                foreach ($this->modes as $mode) {
                    $service->snapshot($from, $to, $loc !== null ? (int) $loc : null, $mode);
                }
            }
            Cache::put(self::statusKey($this->companyId), ['status' => 'done', 'at' => now()->toIso8601String()], now()->addHour());
        } catch (\Throwable $e) {
            Log::warning('[Engenharia de menu] cálculo falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            Cache::put(self::statusKey($this->companyId), ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'at' => now()->toIso8601String()], now()->addHour());
        }
    }
}
