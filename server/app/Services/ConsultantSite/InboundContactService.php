<?php

declare(strict_types=1);

namespace App\Services\ConsultantSite;

use App\Access\Access;
use App\Mail\SiteContactReceivedMail;
use App\Models\Company;
use App\Models\ConsultantSite;
use App\Models\Parish;
use App\Models\InboundContact;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * A central de contactos recebidos: grava um contacto vindo do site (canal "site") e avisa já o
 * consultor (o sino da empresa e um email em fila a quem pode ver os contactos). O aviso NUNCA leva
 * os dados pessoais do visitante (só o tipo, a zona ou a referência e a ligação para a app); esses
 * ficam na XPLENDOR, atrás da ACL. Uma falha no aviso não anula o contacto. A seguir, as contagens
 * de interesse do imóvel (contacts_30, visits_30) atualizam-se.
 */
class InboundContactService
{
    public function __construct(private readonly AlertService $alerts, private readonly Access $access, private readonly PropertyInterestSync $interest) {}

    /** @param array<string, mixed> $data já validado */
    public function storeFromSite(ConsultantSite $site, array $data): InboundContact
    {
        $now = now();
        $eventId = $data['event_id'] ?? (string) Str::uuid();
        $subject = ! empty($data['property_id']) ? [InboundContact::SUBJECT_PROPERTY, (int) $data['property_id']]
            : (! empty($data['development_id']) ? [InboundContact::SUBJECT_DEVELOPMENT, (int) $data['development_id']] : [null, null]);
        $contact = InboundContact::create([
            'company_id' => $site->company_id, 'channel' => 'site', 'source_ref' => $eventId, 'consultant_site_id' => $site->id, 'type' => $data['type'],
            'subject_type' => $subject[0], 'subject_id' => $subject[1],
            'name' => trim((string) $data['name']), 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'message' => $data['message'] ?? null,
            'details' => $data['details'] ?? null,
            'consent_privacy_at' => $now,
            'consent_marketing' => (bool) ($data['consent_marketing'] ?? false), 'consent_marketing_at' => ($data['consent_marketing'] ?? false) ? $now : null,
            'consent_credit_share' => (bool) ($data['consent_credit_share'] ?? false), 'consent_credit_share_at' => ($data['consent_credit_share'] ?? false) ? $now : null,
            'policy_version' => $data['policy_version'],
            'utm_source' => $data['origin']['utm_source'] ?? null, 'utm_medium' => $data['origin']['utm_medium'] ?? null,
            'utm_campaign' => $data['origin']['utm_campaign'] ?? null, 'utm_content' => $data['origin']['utm_content'] ?? null, 'utm_term' => $data['origin']['utm_term'] ?? null,
            'gclid' => $data['origin']['gclid'] ?? null, 'fbclid' => $data['origin']['fbclid'] ?? null,
            'landing_path' => $data['origin']['landing_path'] ?? null, 'page_path' => $data['origin']['page_path'] ?? null,
            'referrer_host' => $data['origin']['referrer_host'] ?? null,
            'event_id' => $eventId,
            'status' => 'novo',
            'retain_until' => $now->copy()->addMonths(max(1, (int) config('consultor_site.contact_retention_months')))->toDateString(),
        ]);
        $this->notify($site, $contact);
        if ($contact->subject_type === InboundContact::SUBJECT_PROPERTY) {
            $this->interest->refreshProperties((int) $site->company_id, [(int) $contact->subject_id]);
        }

        return $contact;
    }

    /** O resumo do aviso, sem dados pessoais: o tipo e a zona, a referência ou o empreendimento. */
    public static function summary(InboundContact $c): string
    {
        $type = InboundContact::TYPES[$c->type] ?? $c->type;
        $parishId = $c->details['parish_id'] ?? null;
        $property = $c->subjectProperty();
        $development = $c->subjectDevelopment();
        $where = $property ? 'imóvel ' . ($property->chave_nova_reference ?: $property->reference)
            : ($development ? "empreendimento {$development->name}" : ($parishId ? (Parish::find($parishId)?->name ?? '') : ''));

        return $where !== '' ? "{$type}: {$where}" : $type;
    }

    private function notify(ConsultantSite $site, InboundContact $contact): void
    {
        $summary = self::summary($contact);
        try {
            $this->alerts->createSystemAlert(
                companyId: (int) $site->company_id,
                type: 'opportunity',
                title: 'Novo contacto do site',
                message: $summary,
                severity: 'high',
                detailPath: "/contactos?id={$contact->id}",
            );
        } catch (\Throwable $e) {
            Log::warning('[Site do consultor] Falha ao criar o alerta do contacto', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);
        }

        $company = Company::find($site->company_id);
        $url = rtrim((string) config('app.frontend_url'), '/') . "/contactos?id={$contact->id}";
        $people = User::where('company_id', $site->company_id)->whereNull('deactivated_at')->whereNotNull('email')->get();
        foreach ($people as $user) {
            if (! $this->access->can($user, (int) $site->company_id, 'contactos.ver')->allowed) {
                continue;
            }
            try {
                Mail::to($user->email)->queue(new SiteContactReceivedMail(
                    contactId: (int) $contact->id,
                    summary: $summary,
                    companyName: (string) ($company?->trade_name ?: $company?->fiscal_name ?: ''),
                    url: $url,
                ));
            } catch (\Throwable $e) {
                Log::warning('[Site do consultor] Falha ao enviar o aviso do contacto', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
