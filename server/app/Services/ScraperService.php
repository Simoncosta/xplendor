<?php

namespace App\Services;

use App\Jobs\RunScraperJob;
use App\Models\ScraperExecution;
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
     * Validate source, create the execution record and dispatch the job.
     */
    public function run(string $source, string $mode, array $filters = [], ?int $companyId = null): ScraperExecution
    {
        if (!in_array($source, self::SUPPORTED_SOURCES, true)) {
            throw ValidationException::withMessages([
                'source' => ['Source inválida. Sources suportadas: ' . implode(', ', self::SUPPORTED_SOURCES)],
            ]);
        }

        $execution = ScraperExecution::create([
            'company_id' => $companyId,
            'source'     => $source,
            'mode'       => $mode,
            'filters'    => $filters,
            'status'     => 'pending',
        ]);

        RunScraperJob::dispatch($source, $mode, $filters, $execution->id);

        return $execution;
    }

    public static function supportedSources(): array
    {
        return self::SUPPORTED_SOURCES;
    }
}
