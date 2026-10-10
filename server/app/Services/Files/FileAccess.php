<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Access\Access;
use App\Models\FileFolder;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Tenancy\CompanyAccess;

/**
 * Ficheiros: quem vê que pasta e que ficheiro. As pastas das pessoas seguem a área "ficheiros";
 * as do sistema seguem a regra da sua área (SystemFolders) e o módulo de origem. Nunca confia
 * na listagem: abrir e descarregar voltam a perguntar aqui.
 */
class FileAccess
{
    /** @var array<string, bool> cache por pedido */
    private array $memo = [];

    public function __construct(
        private readonly Access $access,
        private readonly CompanyAccess $companies,
        private readonly CompanyModuleService $modules,
    ) {}

    public function can(User $user, int $companyId, string $permission): bool
    {
        return $this->memo["{$user->id}:{$companyId}:{$permission}"] ??= $this->access->can($user, $companyId, $permission)->allowed;
    }

    /** A pessoa vê esta pasta do sistema (pela chave)? */
    public function canSeeSystem(User $user, int $companyId, string $systemKey): bool
    {
        $rule = SystemFolders::rule($systemKey);
        if (! $rule) {
            return false;
        }
        if ($rule['module'] && ! $this->modules->isEnabled($companyId, $rule['module'])) {
            return false; // um módulo desligado não tem pasta (nem para o root)
        }
        if ($rule['no_agency'] && $this->companies->kind($user, $companyId) === CompanyAccess::AGENCY) {
            return false;
        }
        foreach ($rule['permissions'] as $p) {
            if (! $this->can($user, $companyId, $p)) {
                return false;
            }
        }

        return true;
    }

    public function canSeeFolder(User $user, FileFolder $folder): bool
    {
        if ($folder->system_key !== null) {
            return $this->canSeeSystem($user, (int) $folder->company_id, $folder->system_key);
        }

        return $this->can($user, (int) $folder->company_id, 'ficheiros.ver');
    }

    /** Um ficheiro vê-se se a pessoa vir a pasta onde ele está (a de origem, para os do sistema). */
    public function canSeeFile(User $user, StoredFile $file): bool
    {
        if ($file->is_system) {
            $folder = $file->folder;

            return $folder !== null && $folder->system_key !== null && $this->canSeeSystem($user, (int) $file->company_id, $folder->system_key);
        }
        if ($file->folder && ! $this->canSeeFolder($user, $file->folder)) {
            return false;
        }

        return $this->can($user, (int) $file->company_id, 'ficheiros.ver');
    }
}
