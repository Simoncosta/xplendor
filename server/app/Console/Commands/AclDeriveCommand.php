<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Access\CompatibilityProfiles;
use App\Access\RoutePermissions;
use Illuminate\Console\Command;

/**
 * ACL: deriva os perfis de compatibilidade da fotografia do varrimento
 * (tests/Fixtures/acl/fotografia) e do catálogo das rotas, e grava
 * database/data/acl/perfis-compatibilidade.json. Recusa gravar se houver conflitos.
 *   php artisan acl:derive            → grava
 *   php artisan acl:derive --check    → só confirma que o ficheiro está em dia
 */
class AclDeriveCommand extends Command
{
    protected $signature = 'acl:derive {--check : Só confirma que o ficheiro gravado é igual ao derivado}';

    protected $description = 'Deriva os perfis de compatibilidade do ACL a partir da fotografia do varrimento.';

    public function handle(): int
    {
        $derived = CompatibilityProfiles::derive(CompatibilityProfiles::snapshots(), RoutePermissions::MAP);
        foreach ($derived['conflitos'] as $c) {
            $this->error("Conflito: {$c['ator']} / {$c['permissao']} (403: " . implode(', ', $c['com_403']) . '; 2xx: ' . implode(', ', $c['com_2xx']) . ')');
        }
        if ($derived['conflitos'] !== []) {
            return self::FAILURE;
        }
        unset($derived['conflitos']);
        $json = json_encode($derived, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $file = base_path(CompatibilityProfiles::FILE);

        if ($this->option('check')) {
            if (! is_file($file) || file_get_contents($file) !== $json) {
                $this->error('O ficheiro dos perfis de compatibilidade não está em dia: corra php artisan acl:derive.');

                return self::FAILURE;
            }
            $this->info('Em dia.');

            return self::SUCCESS;
        }
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $json);
        foreach ($derived['perfis'] as $key => $p) {
            $this->line("{$p['nome']} ({$key}): " . count($p['negadas']) . ' permissões negadas');
        }

        return self::SUCCESS;
    }
}
