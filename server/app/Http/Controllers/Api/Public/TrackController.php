<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\CarView;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrackController extends Controller
{
    public function store(Request $request)
    {
        // company_id vem do middleware via token:
        // $data['company_id'] = $request->input('public_api_company')->id;
        $companyId = data_get($request->input('public_api_company'), 'id');

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['page_view', 'car_view', 'car_lead'])],

            'data' => ['nullable', 'array'],
            'data.car_id' => ['nullable', 'integer'],
            'data.name' => ['nullable', 'string', 'max:255'],
            'data.email' => ['nullable', 'email', 'max:255'],
            'data.phone' => ['nullable', 'string', 'max:20'],
            'data.message' => ['nullable', 'string'],

            'tracking' => ['nullable', 'array'],
            'tracking.visitor_id' => ['nullable', 'uuid'],
            'tracking.session_id' => ['nullable', 'uuid'],
            'tracking.referrer' => ['nullable', 'string'],
            'tracking.landing_path' => ['nullable', 'string', 'max:2048'],
            'tracking.channel' => ['nullable', 'string', 'max:50'],
            'tracking.utm_source' => ['nullable', 'string', 'max:255'],
            'tracking.utm_medium' => ['nullable', 'string', 'max:255'],
            'tracking.utm_campaign' => ['nullable', 'string', 'max:255'],
            'tracking.utm_content' => ['nullable', 'string', 'max:255'],
            'tracking.utm_term' => ['nullable', 'string', 'max:255'],
        ]);

        if (!$companyId) {
            return response()->json(['message' => 'Token inválido (empresa não encontrada).'], 401);
        }

        $type = $validated['type'];
        $data = $validated['data'] ?? [];
        $t = $validated['tracking'] ?? [];

        // validar car_id quando necessário
        if (in_array($type, ['car_view', 'car_lead'], true)) {
            if (empty($data['car_id'])) {
                return response()->json(['message' => 'data.car_id é obrigatório.'], 422);
            }

            $carExistsForCompany = Car::query()
                ->where('id', $data['car_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$carExistsForCompany) {
                return response()->json(['message' => 'Carro não encontrado para esta empresa.'], 404);
            }
        }

        // helpers de tracking (mapeia "tracking.*" -> colunas diretas)
        $trackingCols = [
            'referrer'     => $t['referrer'] ?? null,
            'landing_path' => $t['landing_path'] ?? null,
            'channel'      => $t['channel'] ?? null,
            'visitor_id'   => $t['visitor_id'] ?? null,
            'session_id'   => $t['session_id'] ?? null,
            'utm_source'   => $t['utm_source'] ?? null,
            'utm_medium'   => $t['utm_medium'] ?? null,
            'utm_campaign' => $t['utm_campaign'] ?? null,
            'utm_content'  => $t['utm_content'] ?? null,
            'utm_term'     => $t['utm_term'] ?? null,
        ];

        if ($type === 'car_view') {
            $view = CarView::create([
                'company_id' => $companyId,
                'car_id' => (int) $data['car_id'],
                'user_id' => null, // público
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) ($request->userAgent() ?? ''),

                // tracking
                ...$trackingCols,
            ]);

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $view->id,
            ], 201);
        }

        if ($type === 'car_lead') {
            // regras extra só para lead
            $leadData = validator($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'message' => ['nullable', 'string'],
                'car_id' => ['required', 'integer'],
            ])->validate();

            $lead = CarLead::create([
                'company_id' => $companyId,
                'car_id' => (int) $leadData['car_id'],
                'name' => $leadData['name'],
                'email' => $leadData['email'],
                'phone' => $leadData['phone'] ?? null,
                'message' => $leadData['message'] ?? null,

                // defaults do teu schema (status/source/etc) ficam a cargo do BD
                // tracking
                ...$trackingCols,
            ]);

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $lead->id,
            ], 201);
        }

        // page_view (não tens tabela própria; mantém “ok” para já)
        if ($type === 'page_view') {
            // se quiseres, podes gravar numa tabela própria depois.
            return response()->json([
                'ok' => true,
                'type' => $type,
            ], 201);
        }

        return response()->json(['message' => 'Tipo inválido.'], 422);
    }
}
