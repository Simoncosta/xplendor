<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Mailbox\MailRetention;
use App\Settings\Settings;
use App\Settings\SettingsAccess;
use App\Settings\SettingsCatalog;
use Illuminate\Http\Request;

/**
 * E-mail 8 · Perfil da empresa › Correio: o prazo de retenção das mensagens guardadas (12 meses por
 * omissão, de 3 a 24). Vê quem vê a empresa; muda só o administrador da própria empresa ou o root
 * (como as outras Configurações). O histórico das mudanças fica em setting_events.
 *   GET /companies/{id}/mail-settings
 *   PUT /companies/{id}/mail-settings   {retention_months} (JSON)
 */
class CompanyMailSettingsController extends Controller
{
    public function show(Request $request, int $companyId)
    {
        return ApiResponse::success($this->payload($request, $companyId), 'Definições do correio.');
    }

    public function update(Request $request, int $companyId)
    {
        $data = $request->validate(['retention_months' => ['required']]);
        if (! SettingsAccess::companyAdmin($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da própria empresa muda o prazo de retenção.', 422, ['retention_months' => ['Só o administrador da empresa.']]);
        }
        $changed = Settings::setCompany($companyId, [MailRetention::SETTING => $data['retention_months']], $request->user());

        return ApiResponse::success($this->payload($request, $companyId) + ['changed' => $changed], $changed ? 'Prazo de retenção guardado.' : 'Nada mudou.');
    }

    private function payload(Request $request, int $companyId): array
    {
        $def = SettingsCatalog::get(MailRetention::SETTING);

        return [
            'retention_months' => MailRetention::months($companyId),
            'default' => $def['default'], 'min' => $def['min'], 'max' => $def['max'], 'info' => $def['info'],
            'can_edit' => SettingsAccess::companyAdmin($request->user(), $companyId),
            'history' => Settings::history($companyId, [SettingsCatalog::COMPANY_MAIL], 20),
        ];
    }
}
