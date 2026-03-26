<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\CarInteraction;
use App\Models\CarLead;
use App\Models\CarView;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrackController extends Controller
{
    public function store(Request $request)
    {
        return $this->handleStore($request, false);
    }

    public function storeCarmine(Request $request)
    {
        return $this->handleStore($request, true);
    }

    private function handleStore(Request $request, bool $useCarmineId = false)
    {
        $companyId = data_get($request->input('public_api_company'), 'id');

        if (!$companyId) {
            return response()->json([
                'message' => 'Token inválido (empresa não encontrada).',
            ], 401);
        }

        $interactionTypes = [
            'whatsapp_click',
            'call_click',
            'show_phone',
            'copy_phone',
            'favorite',
            'share',
            'form_open',
            'form_start',
            'location_view',
        ];

        $allowedTypes = array_merge([
            'page_view',
            'car_view',
            'car_view_duration',
            'car_lead',
        ], $interactionTypes);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in($allowedTypes)],

            'data' => ['nullable', 'array'],
            'data.car_id' => ['nullable', 'integer'],
            'data.client_view_key' => ['nullable', 'uuid'],
            'data.view_duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'data.name' => ['nullable', 'string', 'max:255'],
            'data.email' => ['nullable', 'email', 'max:255'],
            'data.phone' => ['nullable', 'string', 'max:30'],
            'data.message' => ['nullable', 'string'],

            'data.interaction_target' => ['nullable', 'string', 'max:50'],
            'data.page_type' => ['nullable', 'string', 'max:50'],
            'data.page_context' => ['nullable', 'string', 'max:100'],
            'data.page_url' => ['nullable', 'string'],
            'data.whatsapp_number' => ['nullable', 'string', 'max:30'],
            'data.meta' => ['nullable', 'array'],

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

        $type = $validated['type'];
        $data = $validated['data'] ?? [];
        $t = $validated['tracking'] ?? [];

        $trackingCols = [
            'referrer'     => $t['referrer'] ?? null,
            'landing_path' => $t['landing_path'] ?? null,
            'channel'      => $t['channel'] ?? null,
            'visitor_id'   => $t['visitor_id'] ?? null,
            'session_id'   => $t['session_id'] ?? null,
            'client_view_key' => $data['client_view_key'] ?? null,
            'utm_source'   => $t['utm_source'] ?? null,
            'utm_medium'   => $t['utm_medium'] ?? null,
            'utm_campaign' => $t['utm_campaign'] ?? null,
            'utm_content'  => $t['utm_content'] ?? null,
            'utm_term'     => $t['utm_term'] ?? null,
        ];

        $carId = $this->resolveCarId(
            companyId: $companyId,
            rawCarId: $data['car_id'] ?? null,
            useCarmineId: $useCarmineId
        );

        if (($data['car_id'] ?? null) && !$carId) {
            return response()->json([
                'message' => $useCarmineId
                    ? 'Carro CARMINE não encontrado para esta empresa.'
                    : 'Carro não encontrado para esta empresa.',
            ], 404);
        }

        if ($type === 'car_view') {
            if (!$carId) {
                return response()->json([
                    'message' => 'data.car_id é obrigatório.',
                ], 422);
            }

            $view = CarView::create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'user_id' => null,
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) ($request->userAgent() ?? ''),
                'view_duration_seconds' => $data['view_duration_seconds'] ?? null,
                ...$trackingCols,
            ]);

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $view->id,
            ], 201);
        }

        if ($type === 'car_view_duration') {
            if (!$carId) {
                return response()->json([
                    'message' => 'data.car_id é obrigatório.',
                ], 422);
            }

            if (empty($data['client_view_key'])) {
                return response()->json([
                    'message' => 'data.client_view_key é obrigatório.',
                ], 422);
            }

            $view = CarView::query()
                ->where('company_id', $companyId)
                ->where('car_id', $carId)
                ->where('visitor_id', $trackingCols['visitor_id'])
                ->where('session_id', $trackingCols['session_id'])
                ->where('client_view_key', $data['client_view_key'])
                ->latest('id')
                ->first();

            if (!$view) {
                return response()->json([
                    'ok' => true,
                    'type' => $type,
                    'updated' => false,
                ], 202);
            }

            $view->update([
                'view_duration_seconds' => max(
                    (int) ($view->view_duration_seconds ?? 0),
                    (int) ($data['view_duration_seconds'] ?? 0)
                ),
            ]);

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $view->id,
                'updated' => true,
            ], 200);
        }

        if ($type === 'car_lead') {
            $leadData = validator($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:30'],
                'message' => ['nullable', 'string'],
                'car_id' => ['required', 'integer'],
            ])->validate();

            if (!$carId) {
                return response()->json([
                    'message' => 'data.car_id é obrigatório.',
                ], 422);
            }

            $lead = CarLead::create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'name' => $leadData['name'],
                'email' => $leadData['email'],
                'phone' => $leadData['phone'] ?? null,
                'message' => $leadData['message'] ?? null,
                ...$trackingCols,
            ]);

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $lead->id,
            ], 201);
        }

        if (in_array($type, $interactionTypes, true)) {
            $interaction = CarInteraction::create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'user_id' => null,

                'interaction_type' => $type,
                'interaction_target' => $data['interaction_target'] ?? null,

                'page_type' => $data['page_type'] ?? null,
                'page_context' => $data['page_context'] ?? null,
                'page_url' => $data['page_url'] ?? null,

                'phone' => $data['phone'] ?? null,
                'whatsapp_number' => $data['whatsapp_number'] ?? null,

                'meta' => $data['meta'] ?? null,

                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) ($request->userAgent() ?? ''),

                ...$trackingCols,
            ]);

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $interaction->id,
            ], 201);
        }

        if ($type === 'page_view') {
            return response()->json([
                'ok' => true,
                'type' => $type,
            ], 201);
        }

        return response()->json([
            'message' => 'Tipo inválido.',
        ], 422);
    }

    private function resolveCarId(int $companyId, ?int $rawCarId, bool $useCarmineId = false): ?int
    {
        if (!$rawCarId) {
            return null;
        }

        $query = Car::query()->where('company_id', $companyId);

        if ($useCarmineId) {
            $car = $query->where('carmine_id', $rawCarId)->first();
        } else {
            $car = $query->where('id', $rawCarId)->first();
        }

        return $car?->id;
    }
}
