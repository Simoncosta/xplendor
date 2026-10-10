<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Access\CompatibilityMigration;
use Illuminate\Console\Command;

/**
 * ACL, F2: passa os dados de hoje para os perfis de compatibilidade (a mesma operação da
 * migração 2026_12_20_100000_create_permission_profiles). Idempotente: só preenche o que
 * está vazio e repõe as permissões dos perfis de sistema a partir do ficheiro derivado.
 */
class AclMigrateProfilesCommand extends Command
{
    protected $signature = 'acl:migrate-profiles';

    protected $description = 'Atribui os perfis de compatibilidade do ACL aos utilizadores e às relações de gestão sem perfil.';

    public function handle(): int
    {
        $r = CompatibilityMigration::run();
        \App\Access\ProfileSuggestions::ensure();
        $this->info("Perfis de sistema: {$r['perfis']}. Utilizadores com perfil novo: {$r['utilizadores']}. Perfis dentro dos clientes: {$r['agencia']}. Relações de gestão com teto: {$r['relacoes']}.");

        return self::SUCCESS;
    }
}
