<?php

declare(strict_types=1);

namespace App\Services\Media;

/** Ocupação do disco onde vivem os media (isolada para os testes a simularem). */
class DiskUsage
{
    /** Percentagem ocupada (0 a 100) do sistema de ficheiros do caminho indicado. */
    public function percentUsed(string $path): ?float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (! $total || $free === false) {
            return null;
        }

        return round(($total - $free) * 100 / $total, 1);
    }
}
