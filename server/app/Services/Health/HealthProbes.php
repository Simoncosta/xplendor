<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Media\DiskUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * As sondas da monitorização, uma por serviço (isoladas para os testes as simularem). Cada uma
 * devolve null quando está bem, ou o motivo da falha em linguagem simples. SKIPPED quando o
 * serviço não é usado nesta instalação.
 */
class HealthProbes
{
    public const SKIPPED = 'não usado';

    public function __construct(private readonly VirusScanner $scanner, private readonly DiskUsage $disk) {}

    public function database(): ?string
    {
        try {
            DB::select('select 1');

            return null;
        } catch (\Throwable $e) {
            return 'A base de dados não responde.';
        }
    }

    /** Só conta quando a fila, a cache ou as sessões estão no Redis. */
    public function redis(): ?string
    {
        if (! in_array('redis', [config('queue.default'), config('cache.default'), config('session.driver')], true)) {
            return self::SKIPPED;
        }
        try {
            Redis::connection()->ping();

            return null;
        } catch (\Throwable $e) {
            return 'O Redis não responde (fila e cache).';
        }
    }

    public function antivirus(): ?string
    {
        if (config('financing.antivirus.driver') !== 'clamav' || ! $this->scanner->configured()) {
            return self::SKIPPED;
        }

        return $this->scanner->ping() ? null : 'O antivírus (ClamAV) não responde.';
    }

    /** Percentagem ocupada do disco do storage (null se não se conseguir ler). */
    public function diskPercent(): ?float
    {
        return $this->disk->percentUsed(storage_path());
    }
}
