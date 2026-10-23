<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Settings\PingwinWriteGate;
use App\Settings\Settings;
use App\Settings\SettingsCatalog;
use Illuminate\Http\Request;

/**
 * XPLENDOR — Admin › Integrações › PingWin (só root): as capacidades provadas (para todas as
 * empresas) e, por empresa, a escrita no PingWin (travão) e o modo de testes, com o histórico.
 * O travão global (PINGWIN_WRITES_ENABLED) vive no .env e só se mostra.
 */
class PingwinSettingsController extends Controller
{
    public function index()
    {
        $companies = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')->pluck('company_id')->unique()->values();

        return ApiResponse::success([
            'global_writes_enabled' => PingwinWriteGate::globalEnabled(),
            // Só as do PingWin (a do Radar muda-se em Admin › Radar).
            'platform' => array_values(array_filter(Settings::describe(null, SettingsCatalog::PLATFORM), fn ($d) => str_starts_with($d['key'], 'pingwin.'))),
            'companies' => Company::whereIn('id', $companies)->orderBy('id')->get(['id', 'fiscal_name', 'trade_name'])->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->trade_name ?: $c->fiscal_name,
                'settings' => Settings::describe($c->id, SettingsCatalog::COMPANY_ROOT),
                'legacy' => PingwinWriteGate::legacy($c->id),
                'block' => PingwinWriteGate::block($c->id),
            ])->values()->all(),
            'history' => array_merge(Settings::history(null, [SettingsCatalog::PLATFORM], 50),
                \App\Models\SettingEvent::with('user:id,name', 'company:id,fiscal_name,trade_name')->where('scope', SettingsCatalog::COMPANY_ROOT)->orderByDesc('id')->limit(50)->get()
                    ->map(fn ($e) => ['key' => $e->key, 'label' => SettingsCatalog::all()[$e->key]['label'] ?? $e->key, 'before' => $e->before, 'after' => $e->after,
                        'user' => $e->user?->name, 'reason' => $e->reason, 'at' => $e->created_at?->toIso8601String(), 'company' => $e->company?->trade_name ?: $e->company?->fiscal_name])->all()),
        ], 'Integração PingWin.');
    }

    public function updatePlatform(Request $request)
    {
        $v = $request->validate(['values' => ['required', 'array', 'min:1'], 'reason' => ['nullable', 'string', 'max:255']]);
        $keys = array_filter(SettingsCatalog::keys(SettingsCatalog::PLATFORM), fn ($k) => str_starts_with($k, 'pingwin.'));
        $values = array_intersect_key($v['values'], array_flip($keys));
        if (count($values) !== count($v['values'])) {
            return ApiResponse::error('Há definições que não são da plataforma.', 422);
        }
        $changed = Settings::setPlatform($values, $request->user(), $v['reason'] ?? null);

        return ApiResponse::success(['changed' => $changed], $changed ? 'Guardado.' : 'Nada mudou.');
    }

    public function updateCompany(Request $request, int $companyId)
    {
        $v = $request->validate(['values' => ['required', 'array', 'min:1'], 'reason' => ['nullable', 'string', 'max:255']]);
        if (! Company::whereKey($companyId)->exists()) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }
        $values = array_intersect_key($v['values'], array_flip(SettingsCatalog::keys(SettingsCatalog::COMPANY_ROOT)));
        if (count($values) !== count($v['values'])) {
            return ApiResponse::error('Só a escrita no PingWin e o modo de testes se mudam aqui.', 422);
        }
        $changed = Settings::setCompany($companyId, $values, $request->user(), $v['reason'] ?? null);

        return ApiResponse::success(['changed' => $changed, 'block' => PingwinWriteGate::block($companyId)], $changed ? 'Guardado.' : 'Nada mudou.');
    }
}
