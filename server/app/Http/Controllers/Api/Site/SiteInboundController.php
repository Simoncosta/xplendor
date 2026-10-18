<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Controller;
use App\Models\ConsultantSite;
use App\Models\Development;
use App\Models\Property;
use App\Models\InboundContact;
use App\Models\SiteDailyCount;
use App\Services\ConsultantSite\InboundContactService;
use App\Services\ConsultantSite\PropertyInterestSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API do site do consultor (escrita): os contactos e as contagens agregadas, vindos do servidor
 * do site (nunca do navegador). Os contactos guardam só os dados mínimos de cada tipo, os
 * consentimentos separados e a origem da visita; o honeypot ("website") preenchido responde
 * como sucesso e não grava nada. As contagens não têm IP, cookies nem identificador.
 */
class SiteInboundController extends Controller
{
    private const KINDS_ZONE = ['apartamento', 'moradia', 'terreno', 'loja', 'escritorio', 'armazem', 'outro'];
    private const BEST_TIMES = ['manha', 'tarde', 'fim_do_dia', 'qualquer'];
    private const TIMEFRAMES = ['ate_3_meses', '3_a_6_meses', '6_a_12_meses', 'mais_de_12_meses'];

    public function __construct(private readonly InboundContactService $contacts, private readonly PropertyInterestSync $interest) {}

    public function contact(Request $request): JsonResponse
    {
        $site = $this->site($request);
        if ($request->attributes->get('site_preview')) {
            return response()->json(['success' => true, 'message' => 'Pré-visualização: nada foi enviado.', 'data' => ['preview' => true]], 202);
        }
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['success' => true, 'message' => 'Recebido.', 'data' => null], 201); // honeypot: nada é gravado
        }
        $type = (string) $request->input('type');
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(InboundContact::TYPES))],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{9,20}$/'],
            'message' => ['nullable', 'string', 'max:2000'],
            'consent_privacy' => ['accepted'],
            'consent_marketing' => ['nullable', 'boolean'],
            'consent_credit_share' => $type === 'credito' ? ['accepted'] : ['nullable', 'boolean'],
            'policy_version' => ['required', 'string', 'max:20'],
            'property_slug' => [$type === 'visita' ? 'required_without:development_slug' : 'nullable', 'string', 'max:160'],
            'development_slug' => ['nullable', 'string', 'max:160'],
            'event_id' => ['nullable', 'uuid', Rule::unique('inbound_contacts', 'event_id')],
            'details' => ['nullable', 'array'],
            ...$this->detailRules($type),
            'origin' => ['nullable', 'array'],
            'origin.utm_source' => ['nullable', 'string', 'max:120'], 'origin.utm_medium' => ['nullable', 'string', 'max:120'],
            'origin.utm_campaign' => ['nullable', 'string', 'max:120'], 'origin.utm_content' => ['nullable', 'string', 'max:120'],
            'origin.utm_term' => ['nullable', 'string', 'max:120'], 'origin.gclid' => ['nullable', 'string', 'max:255'], 'origin.fbclid' => ['nullable', 'string', 'max:255'],
            'origin.landing_path' => ['nullable', 'string', 'max:255'], 'origin.page_path' => ['nullable', 'string', 'max:255'],
            'origin.referrer_host' => ['nullable', 'string', 'max:120'],
        ], [
            'consent_privacy.accepted' => 'É preciso aceitar a política de privacidade.',
            'consent_credit_share.accepted' => 'Para o pedido de crédito, é preciso autorizar a partilha dos dados com o parceiro de crédito.',
            'email.required_without' => 'Indique o email ou o telefone.',
            'phone.required_without' => 'Indique o telefone ou o email.',
            'phone.regex' => 'O telefone não parece válido.',
        ]);

        $data['property_id'] = null;
        $data['development_id'] = null;
        if (! empty($data['property_slug'])) {
            $data['property_id'] = Property::where('company_id', $site->company_id)->visibleOnSite()->where('slug', $data['property_slug'])->value('id');
        }
        if (! empty($data['development_slug'])) {
            $data['development_id'] = Development::where('company_id', $site->company_id)->visibleOnSite()->where('slug', $data['development_slug'])->value('id');
        }
        if ($type === 'visita' && ! $data['property_id'] && ! $data['development_id']) {
            throw ValidationException::withMessages(['property_slug' => 'O imóvel já não está disponível no site.']);
        }
        // A partilha com o parceiro de crédito só existe no pedido de crédito.
        $data['consent_credit_share'] = $type === 'credito';
        $data['details'] = $this->onlyDetails($type, (array) ($data['details'] ?? []));
        $contact = $this->contacts->storeFromSite($site, $data);

        return response()->json(['success' => true, 'message' => 'Recebido.', 'data' => ['event_id' => $contact->event_id]], 201);
    }

    public function events(Request $request): JsonResponse
    {
        $site = $this->site($request);
        if ($request->attributes->get('site_preview')) {
            return response()->json(['success' => true, 'message' => 'Pré-visualização: sem contagens.', 'data' => ['counted' => 0]], 202);
        }
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.event' => ['required', Rule::in(SiteDailyCount::EVENTS)],
            'events.*.subject' => ['required', Rule::in(SiteDailyCount::SUBJECTS)],
            'events.*.slug' => ['nullable', 'string', 'max:160'],
            'events.*.count' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $day = now('Europe/Lisbon')->toDateString();
        $tally = [];
        foreach ($data['events'] as $e) {
            $subjectId = match ($e['subject']) {
                'site' => (int) $site->id,
                'property' => (int) Property::where('company_id', $site->company_id)->visibleOnSite()->where('slug', (string) ($e['slug'] ?? ''))->value('id'),
                'development' => (int) Development::where('company_id', $site->company_id)->visibleOnSite()->where('slug', (string) ($e['slug'] ?? ''))->value('id'),
            };
            if ($subjectId > 0) {
                $key = "{$e['subject']}|{$subjectId}|{$e['event']}";
                $tally[$key] = ($tally[$key] ?? 0) + (int) ($e['count'] ?? 1);
            }
        }
        $touched = [];
        DB::transaction(function () use ($tally, $site, $day, &$touched) {
            foreach ($tally as $key => $n) {
                [$subject, $id, $event] = explode('|', $key);
                $row = SiteDailyCount::firstOrCreate(
                    ['subject_type' => $subject, 'subject_id' => (int) $id, 'day' => $day, 'event' => $event],
                    ['company_id' => $site->company_id, 'count' => 0],
                );
                SiteDailyCount::whereKey($row->id)->increment('count', $n);
                if ($subject === 'property' && $event === 'whatsapp_click') {
                    $touched[] = (int) $id;
                }
            }
        });
        // Os cliques no WhatsApp de um imóvel contam como contactos (contacts_30) desse imóvel.
        if ($touched !== []) {
            $this->interest->refreshProperties((int) $site->company_id, $touched);
        }

        return response()->json(['success' => true, 'message' => 'Contado.', 'data' => ['counted' => array_sum($tally)]], 202);
    }

    /** @return array<string, array<int, mixed>> */
    private function detailRules(string $type): array
    {
        $parish = ['details.parish_id' => ['required', 'integer', 'exists:parishes,id']];
        $kind = ['details.property_kind' => ['required', Rule::in(self::KINDS_ZONE)]];

        return match ($type) {
            'estudo_zona' => $parish + $kind,
            'avaliacao' => $parish + $kind + ['details.best_time' => ['nullable', Rule::in(self::BEST_TIMES)]],
            'registo_procura' => [
                'details.typologies' => ['nullable', 'array', 'max:7'], 'details.typologies.*' => [Rule::in(Property::TYPOLOGIES)],
                'details.parish_ids' => ['nullable', 'array', 'max:20'], 'details.parish_ids.*' => ['integer', 'exists:parishes,id'],
                'details.zones_text' => ['nullable', 'string', 'max:200'],
                'details.budget_max' => ['nullable', 'integer', 'min:0', 'max:999999999'],
                'details.timeframe' => ['nullable', Rule::in(self::TIMEFRAMES)],
                'details.needs_credit' => ['nullable', 'boolean'],
            ],
            'credito' => ['details.property_value' => ['nullable', 'integer', 'min:0', 'max:999999999'], 'details.timeframe' => ['nullable', Rule::in(self::TIMEFRAMES)]],
            'visita' => ['details.preferred_time' => ['nullable', 'string', 'max:120']],
            default => [],
        };
    }

    /** Só os campos previstos para o tipo (nada mais entra nos detalhes). @return array<string, mixed>|null */
    private function onlyDetails(string $type, array $details): ?array
    {
        $keys = match ($type) {
            'estudo_zona' => ['parish_id', 'property_kind'],
            'avaliacao' => ['parish_id', 'property_kind', 'best_time'],
            'registo_procura' => ['typologies', 'parish_ids', 'zones_text', 'budget_max', 'timeframe', 'needs_credit'],
            'credito' => ['property_value', 'timeframe'],
            'visita' => ['preferred_time'],
            default => [],
        };
        $out = array_intersect_key($details, array_flip($keys));

        return $out === [] ? null : $out;
    }

    private function site(Request $request): ConsultantSite
    {
        return $request->attributes->get('consultant_site');
    }
}
