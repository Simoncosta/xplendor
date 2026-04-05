<?php

namespace App\Services;

use App\Jobs\RunScraperJob;
use App\Models\ScraperExecution;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ScraperService
{
    /**
     * Supported data sources.
     * Add new sources here — each key maps to a recognized source name.
     */
    private const SUPPORTED_SOURCES = [
        'standvirtual',
    ];

    /**
     * Frontend sends English keys/values; the Python scraper expects its own
     * canonical values (read from scraper/config.py _normalize_fuel / _normalize_gearbox).
     *
     * Key rename:  fuel_type → fuel | transmission → gearbox
     * Value remap: only 'gasoline' needs changing — the Python normalizer does not
     *              recognise it and would pass it through to Standvirtual as-is.
     *              All other frontend values (diesel, electric, hybrid, plug_in_hybrid,
     *              manual, automatic) are already in the Python aliases.
     */
    private const FUEL_MAP = [
        'gasoline'       => 'Gasolina',
        'diesel'         => 'Gasóleo',
        'electric'       => 'Eléctrico',
        'hybrid'         => 'Híbrido',
        'plug_in_hybrid' => 'Híbrido Plug-in',
    ];

    private const GEARBOX_MAP = [
        'manual'    => 'Manual',
        'automatic' => 'Automática',
    ];

    /**
     * Normalise filters before storing and dispatching:
     * - rename frontend keys to scraper arg names (fuel_type→fuel, transmission→gearbox)
     * - remap values that the Python normalizer would not recognise
     */
    private function normalizeFilters(array $filters): array
    {
        $out = $filters;

        if (isset($out['fuel_type'])) {
            $out['fuel'] = $out['fuel_type'];
            unset($out['fuel_type']);
        }

        if (isset($out['transmission'])) {
            $out['gearbox'] = $out['transmission'];
            unset($out['transmission']);
        }

        return $out;
    }

    /**
     * Validate source, create the execution record and dispatch the job.
     */
    public function run(string $source, string $mode, array $filters = [], ?int $companyId = null): ScraperExecution
    {
        if (!in_array($source, self::SUPPORTED_SOURCES, true)) {
            throw ValidationException::withMessages([
                'source' => ['Source inválida. Sources suportadas: ' . implode(', ', self::SUPPORTED_SOURCES)],
            ]);
        }

        $normalized = $this->normalizeFilters($filters);

        $execution = ScraperExecution::create([
            'company_id' => $companyId,
            'source'     => $source,
            'mode'       => $mode,
            'filters'    => $normalized,
            'status'     => 'pending',
        ]);

        RunScraperJob::dispatch($source, $mode, $normalized, $execution->id);

        return $execution;
    }

    public static function supportedSources(): array
    {
        return self::SUPPORTED_SOURCES;
    }
}
