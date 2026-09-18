<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyModule;
use App\Modules\ModuleRegistry;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Módulos por empresa (Incremento 1: estrutura). Lê/escreve os módulos
 * ativos e faz cumprir a TEIA DE DEPENDÊNCIAS:
 *  · desligar um módulo é BLOQUEADO se algum módulo ATIVO depender dele (cadeia);
 *  · ligar um módulo LIGA em cascata as suas dependências;
 *  · aplicar um preset de ramo substitui o conjunto (já fechado sobre dependências).
 *
 * Não faz tenancy nem auth — isso é do controller (só super-admin gere).
 */
class CompanyModuleService
{
    /** @return string[] chaves dos módulos ativos da empresa. */
    public function enabledKeys(int $companyId): array
    {
        return CompanyModule::where('company_id', $companyId)
            ->pluck('module_key')
            ->all();
    }

    public function isEnabled(int $companyId, string $key): bool
    {
        return in_array($key, $this->enabledKeys($companyId), true);
    }

    /**
     * Estado de TODOS os módulos para a UI de gestão: registry + flag enabled +
     * (quando não se pode desligar) o motivo/bloqueadores ativos.
     * @return array<int, array<string, mixed>>
     */
    public function overview(int $companyId): array
    {
        $enabled = $this->enabledKeys($companyId);

        return array_map(function (string $key) use ($enabled) {
            $activeDependents = array_values(array_intersect(ModuleRegistry::dependents($key), $enabled));

            return [
                'key' => $key,
                'label' => ModuleRegistry::label($key),
                'car_specific' => ModuleRegistry::MODULES[$key]['car_specific'],
                'depends_on' => ModuleRegistry::directDependencies($key),
                'enabled' => in_array($key, $enabled, true),
                // Se ligado E tiver dependentes ativos, não se pode desligar já.
                'can_disable' => in_array($key, $enabled, true) ? count($activeDependents) === 0 : true,
                'blocking_dependents' => array_map(fn ($k) => ModuleRegistry::label($k), $activeDependents),
            ];
        }, ModuleRegistry::keys());
    }

    /** Liga um módulo + (em cascata) as suas dependências. */
    public function enable(int $companyId, string $key): void
    {
        $this->assertModule($key);

        $toEnable = array_merge([$key], ModuleRegistry::dependencies($key));
        $existing = $this->enabledKeys($companyId);

        foreach ($toEnable as $k) {
            if (! in_array($k, $existing, true)) {
                CompanyModule::create(['company_id' => $companyId, 'module_key' => $k]);
            }
        }
    }

    /**
     * Desliga um módulo. BLOQUEIA (422) se algum módulo ATIVO depender dele
     * (transitivamente) — segue toda a cadeia. Ex.: não deixa desligar Stock se
     * Comercial/CRM ou Pós-venda estiverem ligados.
     */
    public function disable(int $companyId, string $key): void
    {
        $this->assertModule($key);

        $enabled = $this->enabledKeys($companyId);
        $activeDependents = array_values(array_intersect(ModuleRegistry::dependents($key), $enabled));

        if (! empty($activeDependents)) {
            $labels = implode(', ', array_map(fn ($k) => ModuleRegistry::label($k), $activeDependents));
            throw ValidationException::withMessages([
                'module_key' => ["Não podes desligar \"" . ModuleRegistry::label($key) . "\" porque {$labels} depende(m) dele. Desliga primeiro {$labels}."],
            ]);
        }

        CompanyModule::where('company_id', $companyId)->where('module_key', $key)->delete();
    }

    /**
     * Aplica um preset de ramo: passa a ter EXATAMENTE os módulos do preset (já
     * fechado sobre dependências). É um atalho — depois ajusta-se individualmente.
     */
    public function applyPreset(int $companyId, string $preset): void
    {
        if (! ModuleRegistry::presetExists($preset)) {
            throw ValidationException::withMessages(['preset' => ["Ramo inválido: {$preset}."]]);
        }

        $target = ModuleRegistry::presetKeys($preset);
        $current = $this->enabledKeys($companyId);

        // Remove os que saem.
        $toRemove = array_diff($current, $target);
        if (! empty($toRemove)) {
            CompanyModule::where('company_id', $companyId)->whereIn('module_key', $toRemove)->delete();
        }

        // Adiciona os que entram.
        foreach (array_diff($target, $current) as $k) {
            CompanyModule::create(['company_id' => $companyId, 'module_key' => $k]);
        }
    }

    private function assertModule(string $key): void
    {
        if (! ModuleRegistry::exists($key)) {
            throw ValidationException::withMessages(['module_key' => ["Módulo inválido: {$key}."]]);
        }
    }
}
