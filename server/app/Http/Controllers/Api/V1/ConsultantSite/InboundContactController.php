<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\ConsultantSite;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Parish;
use App\Models\Development;
use App\Models\InboundContact;
use App\Models\Property;
use App\Services\ConsultantSite\InboundContactService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A central de contactos recebidos (área contactos: dados pessoais, nunca a agência). Hoje só o
 * canal "site". Lista simples, detalhe, estado (novo, tratado, arquivado) e apagar.
 */
class InboundContactController extends Controller
{
    public function index(Request $request, int $companyId)
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(InboundContact::STATUSES))], 'type' => ['nullable', Rule::in(array_keys(InboundContact::TYPES))]]);
        $rows = InboundContact::where('company_id', $companyId)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(500)->get();
        $properties = Property::withTrashed()->whereIn('id', $rows->where('subject_type', InboundContact::SUBJECT_PROPERTY)->pluck('subject_id'))->get()->keyBy('id');
        $developments = Development::withTrashed()->whereIn('id', $rows->where('subject_type', InboundContact::SUBJECT_DEVELOPMENT)->pluck('subject_id'))->get()->keyBy('id');
        $rows = $rows->map(fn (InboundContact $c) => $this->present($c, false, $properties, $developments))->all();

        return ApiResponse::success(['contacts' => $rows, 'options' => ['types' => InboundContact::TYPES, 'statuses' => InboundContact::STATUSES, 'channels' => InboundContact::CHANNELS]], 'Contactos recebidos.');
    }

    public function show(int $companyId, int $contactId)
    {
        return ApiResponse::success($this->present($this->find($companyId, $contactId), true), 'Contacto.');
    }

    public function update(Request $request, int $companyId, int $contactId)
    {
        $c = $this->find($companyId, $contactId);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(InboundContact::STATUSES))]]);
        $c->forceFill(['status' => $data['status'], 'handled_at' => $data['status'] === 'novo' ? null : ($c->handled_at ?? now())])->save();

        return ApiResponse::success($this->present($c->fresh(), true), 'Contacto atualizado.');
    }

    public function destroy(int $companyId, int $contactId)
    {
        $this->find($companyId, $contactId)->delete();

        return ApiResponse::success(null, 'Contacto apagado.');
    }

    private function find(int $companyId, int $contactId): InboundContact
    {
        $c = InboundContact::where('company_id', $companyId)->find($contactId);
        if (! $c) {
            abort(404, 'Contacto não encontrado.');
        }

        return $c;
    }

    private function present(InboundContact $c, bool $detail, $properties = null, $developments = null): array
    {
        $property = $c->subject_type === InboundContact::SUBJECT_PROPERTY ? ($properties ? $properties->get($c->subject_id) : $c->subjectProperty()) : null;
        $development = $c->subject_type === InboundContact::SUBJECT_DEVELOPMENT ? ($developments ? $developments->get($c->subject_id) : $c->subjectDevelopment()) : null;
        $out = [
            'id' => $c->id, 'channel' => $c->channel, 'channel_label' => InboundContact::CHANNELS[$c->channel] ?? $c->channel,
            'type' => $c->type, 'type_label' => InboundContact::TYPES[$c->type] ?? $c->type,
            'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'status' => $c->status, 'created_at' => $c->created_at?->toIso8601String(),
            'property' => $property ? ['id' => $property->id, 'reference' => $property->reference, 'title' => $property->title] : null,
            'development' => $development ? ['id' => $development->id, 'name' => $development->name] : null,
            'consent_marketing' => (bool) $c->consent_marketing, 'utm_source' => $c->utm_source, 'utm_campaign' => $c->utm_campaign,
        ];
        if ($detail) {
            $out['summary'] = InboundContactService::summary($c);
            $details = (array) $c->details;
            $parishIds = array_filter(array_merge([$details['parish_id'] ?? null], (array) ($details['parish_ids'] ?? [])));
            $out += [
                'message' => $c->message, 'details' => $details,
                'parishes' => Parish::whereIn('id', $parishIds)->pluck('name', 'id')->all(),
                'consent_privacy_at' => $c->consent_privacy_at?->toIso8601String(), 'consent_marketing_at' => $c->consent_marketing_at?->toIso8601String(),
                'consent_credit_share' => (bool) $c->consent_credit_share, 'consent_credit_share_at' => $c->consent_credit_share_at?->toIso8601String(),
                'policy_version' => $c->policy_version,
                'origin' => ['utm_source' => $c->utm_source, 'utm_medium' => $c->utm_medium, 'utm_campaign' => $c->utm_campaign, 'utm_content' => $c->utm_content,
                    'utm_term' => $c->utm_term, 'gclid' => $c->gclid ? true : false, 'fbclid' => $c->fbclid ? true : false,
                    'landing_path' => $c->landing_path, 'page_path' => $c->page_path, 'referrer_host' => $c->referrer_host],
                'handled_at' => $c->handled_at?->toIso8601String(), 'retain_until' => $c->retain_until?->toDateString(),
            ];
        }

        return $out;
    }
}
