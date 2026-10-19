<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinLocation;
use App\Models\User;
use App\Settings\Settings;
use App\Settings\SettingsCatalog;
use Illuminate\Http\Request;

/**
 * XPLENDOR — Configurações da empresa › Restauração e Auditoria: as definições que saíram do .env
 * (food cost alvo, recontagem, severidades, transferências, loja dos documentos de stock, leitura
 * dos documentos de caixa, anulações por funcionário, feriado municipal), com o histórico.
 *
 * Ver e mudar: restauracao.configurar (a ACL das rotas: o administrador da empresa, o administrador
 * da agência gestora e o root). Ligar a regra das anulações por funcionário é uma decisão do
 * cliente (o dono autoriza): a XPLENDOR não tem um perfil de dono, por isso só o administrador da
 * própria empresa (ou o root) a liga.
 */
class CompanyRestaurantSettingsController extends Controller
{
    public function show(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success($this->payload($request, $companyId), 'Configurações da restauração e da auditoria.');
    }

    public function update(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $user = $request->user();
        $v = $request->validate(['values' => ['required', 'array', 'min:1'], 'consent' => ['nullable', 'boolean']]);
        $allowed = SettingsCatalog::keys(SettingsCatalog::COMPANY);
        $values = array_intersect_key($v['values'], array_flip($allowed));
        if (count($values) !== count($v['values'])) {
            return ApiResponse::error('Há definições que não se mudam aqui.', 422, ['values' => ['Só as definições da restauração e da auditoria desta empresa.']]);
        }
        // Ligar as anulações por funcionário é uma decisão do cliente: o administrador da própria empresa,
        // com a autorização explícita (o texto do ecrã).
        $turningOn = filter_var($values['audit.voids_by_employee'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! Settings::company($companyId, 'audit.voids_by_employee');
        if ($turningOn && ! self::isAdmin($user, $companyId)) {
            return ApiResponse::error('Só o administrador da própria empresa liga as anulações por funcionário.', 422, ['values' => ['Decisão do cliente.']]);
        }
        if (filter_var($values['audit.voids_by_employee'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! Settings::company($companyId, 'audit.voids_by_employee') && ! ($v['consent'] ?? false)) {
            return ApiResponse::error(SettingsCatalog::VOIDS_CONSENT, 422, ['consent' => [SettingsCatalog::VOIDS_CONSENT]]);
        }
        $changed = Settings::setCompany($companyId, $values, $user, isset($values['audit.voids_by_employee']) && ($v['consent'] ?? false) ? SettingsCatalog::VOIDS_CONSENT : null);

        return ApiResponse::success($this->payload($request, $companyId) + ['changed' => $changed],
            $changed ? 'Configurações guardadas.' : 'Nada mudou.');
    }

    /** O administrador da própria empresa (perfil Administrador) ou o root. */
    public static function isAdmin(?User $user, int $companyId): bool
    {
        return \App\Settings\SettingsAccess::companyAdmin($user, $companyId);
    }

    private function payload(Request $request, int $companyId): array
    {
        return [
            'can_voids' => self::isAdmin($request->user(), $companyId),
            'sections' => SettingsCatalog::SECTIONS,
            'settings' => Settings::describe($companyId, SettingsCatalog::COMPANY),
            'stores' => PingwinLocation::where('company_id', $companyId)->orderBy('id')->get()
                ->map(fn ($l) => ['id' => (string) $l->winrest_store_id, 'name' => $l->display_name ?: ($l->winrest_name ?? $l->winrest_store_id)])->values()->all(),
            'history' => Settings::history($companyId, [SettingsCatalog::COMPANY], 50),
            'owner_note' => 'A XPLENDOR não tem um perfil de dono: a regra das anulações por funcionário é ligada pelo administrador da empresa, com a autorização do dono.',
        ];
    }
}
