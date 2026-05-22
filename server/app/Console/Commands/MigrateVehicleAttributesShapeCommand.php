<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VehicleAttribute;
use Illuminate\Console\Command;

class MigrateVehicleAttributesShapeCommand extends Command
{
    protected $signature = 'vehicle-attributes:migrate-shape
                                {--force : Escrever alterações na base de dados (por omissão é dry-run)}';

    protected $description = 'Migra registos de vehicle_attributes do formato plano antigo para o formato B1 aninhado e normaliza slugs de tipos de cama';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (!$force) {
            $this->warn('[DRY-RUN] Nenhuma alteração será escrita. Usa --force para aplicar.');
        }

        $total    = 0;
        $migrated = 0;
        $skipped  = 0;

        VehicleAttribute::query()->cursor()->each(function (VehicleAttribute $record) use (&$total, &$migrated, &$skipped, $force) {
            $total++;
            $raw       = $record->getAttribute('attributes');
            $normalized = VehicleAttribute::normalizeShape(is_array($raw) ? $raw : null);

            if ($normalized === $raw) {
                $skipped++;
                return;
            }

            $migrated++;
            $this->line("  car_id={$record->car_id} — a migrar" . ($force ? '' : ' (dry-run)'));

            if ($force) {
                $record->update(['attributes' => $normalized]);
            }
        });

        $this->info("Total: {$total} | Migrados: {$migrated} | Já no novo formato: {$skipped}");

        return self::SUCCESS;
    }
}
