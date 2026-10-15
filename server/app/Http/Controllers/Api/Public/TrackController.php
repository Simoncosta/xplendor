<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\CarInteraction;
use App\Models\CarLead;
use App\Models\CarView;
use App\Services\AttributionService;
use App\Support\Tracking\WhatsappContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrackController extends Controller
{
    public function __construct(protected AttributionService $attributionService) {}

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
            ...WhatsappContact::TYPES, // whatsapp_click e whatsapp_send
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
            // 'scroll_depth'
        ], $interactionTypes);

        try {
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
                'data.meta.page_context' => ['nullable', 'string', 'max:100'],

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
                'tracking.utm_id' => ['nullable', 'string', 'max:255'],
                'tracking.ad_id' => ['nullable', 'string', 'max:255'],
                'tracking.click_id' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            $this->logRefusal($request, (int) $companyId, 422, 'validação: ' . implode(', ', array_keys($e->errors())));
            throw $e;
        }

        $type = $validated['type'];
        $data = $validated['data'] ?? [];
        $t = $validated['tracking'] ?? [];

        // O page_context vem em data.page_context; se só vier em data.meta.page_context, usa-se esse.
        $pageContext = $data['page_context'] ?? ($data['meta']['page_context'] ?? null);

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

        $attributionContext = [
            ...$trackingCols,
            'utm_id' => $t['utm_id'] ?? null,
            'ad_id' => $t['ad_id'] ?? null,
            'click_id' => $t['click_id'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'page_context' => $pageContext,
        ];

        $carId = $this->resolveCarId(
            companyId: $companyId,
            rawCarId: $data['car_id'] ?? null,
            useCarmineId: $useCarmineId
        );

        // Viatura indicada mas não encontrada nesta empresa (apagada, ou de outra empresa).
        $carUnresolved = ($data['car_id'] ?? null) && !$carId;
        $isInteraction = in_array($type, $interactionTypes, true);

        // Numa interação, o clique não se perde: grava-se sem viatura (ver abaixo). Nos outros tipos, 404.
        if ($carUnresolved && !$isInteraction) {
            $this->logRefusal($request, (int) $companyId, 404, 'viatura não encontrada nesta empresa', $type);

            return response()->json([
                'message' => $useCarmineId
                    ? 'Veículo CARMINE não encontrado para esta empresa.'
                    : 'Veículo não encontrado para esta empresa.',
            ], 404);
        }

        if ($type === 'car_view') {
            if (!$carId) {
                return $this->refuse($request, (int) $companyId, $type, 'data.car_id em falta', 'data.car_id é obrigatório.');
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

            $car = Car::query()->where('company_id', $companyId)->find($carId);
            if ($car) {
                $this->attributionService->trackVisit($car, $request);
            }

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $view->id,
            ], 201);
        }

        if ($type === 'car_view_duration') {
            if (!$carId) {
                return $this->refuse($request, (int) $companyId, $type, 'data.car_id em falta', 'data.car_id é obrigatório.');
            }

            if (empty($data['client_view_key'])) {
                return $this->refuse($request, (int) $companyId, $type, 'data.client_view_key em falta', 'data.client_view_key é obrigatório.');
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
            try {
                $leadData = validator($data, [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:30'],
                    'message' => ['nullable', 'string'],
                    'car_id' => ['required', 'integer'],
                ])->validate();
            } catch (ValidationException $e) {
                $this->logRefusal($request, (int) $companyId, 422, 'validação: ' . implode(', ', array_keys($e->errors())), $type);
                throw $e;
            }

            if (!$carId) {
                return $this->refuse($request, (int) $companyId, $type, 'data.car_id em falta', 'data.car_id é obrigatório.');
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

            $car = Car::query()->where('company_id', $companyId)->find($carId);
            if ($car) {
                $this->attributionService->trackLead($car, $request);
            }

            return response()->json([
                'ok' => true,
                'type' => $type,
                'id' => $lead->id,
            ], 201);
        }

        if ($isInteraction) {
            $meta = $data['meta'] ?? null;
            if ($carUnresolved) {
                // Nunca ligar a uma viatura que não é desta empresa: o clique conta como da empresa.
                $meta = [...(is_array($meta) ? $meta : []), 'car_id_unresolved' => true];
            }

            // ── Dedup no ponto de entrada (defesa em profundidade) ──────────────
            // O site externo pode disparar o mesmo evento 2x em 0-3s (duplo-disparo
            // técnico). Se já existe uma interação IDÊNTICA (mesma empresa + carro +
            // tipo + visitante + page_context) nos últimos 5 SEGUNDOS, NÃO gravamos de
            // novo e devolvemos "ok" (o site não recebe erro). Janela curta de propósito:
            // cliques humanos reais acontecem a 11s+, nunca a <5s. O page_context entra
            // na chave para que dois botões diferentes nunca se fundam. Só se aplica
            // quando há visitor_id (sem ele não há como identificar o repetido).
            $visitorId = $trackingCols['visitor_id'] ?? null;
            if ($visitorId) {
                $isDuplicate = CarInteraction::query()
                    ->where('company_id', $companyId)
                    ->where('interaction_type', $type)
                    ->where('visitor_id', $visitorId)
                    ->where('created_at', '>=', now()->subSeconds(5))
                    ->when(
                        $carId,
                        fn ($q) => $q->where('car_id', $carId),
                        fn ($q) => $q->whereNull('car_id')
                    )
                    ->when(
                        $pageContext !== null,
                        fn ($q) => $q->where('page_context', $pageContext),
                        fn ($q) => $q->whereNull('page_context')
                    )
                    ->exists();

                if ($isDuplicate) {
                    return response()->json([
                        'ok' => true,
                        'type' => $type,
                        'deduped' => true,
                    ], 200);
                }
            }

            $interaction = CarInteraction::create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'user_id' => null,

                'interaction_type' => $type,
                'interaction_target' => $data['interaction_target'] ?? null,

                'page_type' => $data['page_type'] ?? null,
                'page_context' => $pageContext,
                'page_url' => $data['page_url'] ?? null,

                'phone' => $data['phone'] ?? null,
                'whatsapp_number' => $data['whatsapp_number'] ?? null,

                'meta' => $meta,

                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) ($request->userAgent() ?? ''),

                ...$trackingCols,
            ]);

            if ($carId) {
                $car = Car::query()->where('company_id', $companyId)->find($carId);
                if ($car) {
                    $this->attributionService->trackInteraction($car, $type, [
                        ...$attributionContext,
                        'request' => $request,
                    ]);
                }
            }

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

        return $this->refuse($request, (int) $companyId, $type, 'tipo inválido', 'Tipo inválido.');
    }

    /** Resposta 422 com o registo da recusa (ver logRefusal). */
    private function refuse(Request $request, int $companyId, ?string $type, string $reason, string $message)
    {
        $this->logRefusal($request, $companyId, 422, $reason, $type);

        return response()->json(['message' => $message], 422);
    }

    /**
     * Cada pedido recusado no /track fica no log (info): a empresa, o tipo, o motivo e o
     * domínio de origem. Nunca o token, o IP, o visitante nem os dados do formulário; o motivo
     * da validação leva só os nomes dos campos.
     */
    private function logRefusal(Request $request, int $companyId, int $status, string $reason, ?string $type = null): void
    {
        $type ??= $request->input('type');
        $type = is_string($type) && preg_match('/^[a-z0-9_]{1,40}$/', $type) ? $type : '(inválido)';
        $source = $request->headers->get('origin') ?: $request->headers->get('referer');
        $host = is_string($source) ? parse_url($source, PHP_URL_HOST) : null;

        Log::info('[Track] pedido recusado', [
            'company_id' => $companyId,
            'type' => $type,
            'status' => $status,
            'motivo' => $reason,
            'origem' => is_string($host) && $host !== '' ? strtolower($host) : null,
        ]);
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
