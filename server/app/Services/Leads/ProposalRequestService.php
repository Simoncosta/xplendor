<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Mail\AgencyNoticeMail;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use App\Services\AlertService;
use App\Services\AttributionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "Pedir proposta": o pedido do site vira lead na hora (car_leads, tipo "proposta"), ligada à
 * viatura e à empresa, com a origem e os consentimentos (data e texto aceite). Avisa o vendedor
 * da viatura (sem vendedor, os administradores) por email em fila e no sino. Os avisos não levam
 * os dados pessoais do cliente (só a viatura, a origem e o que pediu): esses ficam na XPLENDOR,
 * atrás da ACL. Uma falha no aviso não anula a lead. Nada sobre quem enviou é gravado (sem IP).
 */
class ProposalRequestService
{
    public function __construct(private readonly AlertService $alerts, private readonly AttributionService $attribution) {}

    /** @param array<string, mixed> $data já validado */
    public function store(Company $company, array $data, Request $request): CarLead
    {
        if (! empty($data['event_id']) && ($existing = CarLead::where('company_id', $company->id)->where('event_id', $data['event_id'])->first())) {
            return $existing; // o mesmo envio repetido (duplo clique, nova tentativa): uma só lead
        }
        $car = Car::where('company_id', $company->id)->findOrFail((int) $data['car_id']);
        $now = now();
        $marketing = (bool) ($data['consent_marketing'] ?? false);
        $tradeIn = (bool) $data['has_trade_in'] ? array_filter([
            'brand' => isset($data['trade_in']['brand']) ? trim((string) $data['trade_in']['brand']) : null,
            'model' => isset($data['trade_in']['model']) ? trim((string) $data['trade_in']['model']) : null,
            'year' => isset($data['trade_in']['year']) ? (int) $data['trade_in']['year'] : null,
            'km' => isset($data['trade_in']['km']) ? (int) $data['trade_in']['km'] : null,
        ], fn ($v) => $v !== null && $v !== '') : null;

        $lead = CarLead::create([
            'company_id' => $company->id, 'car_id' => $car->id, 'type' => 'proposta', 'source' => 'website_form', 'status' => 'new',
            'name' => $data['name'], 'email' => $data['email'], 'phone' => trim((string) $data['phone']), 'message' => $data['message'] ?? null,
            'wants_financing' => (bool) $data['wants_financing'], 'trade_in' => $tradeIn ?: null,
            'origin' => mb_strtolower(trim((string) ($data['origem'] ?? ''))) === LeadOrigin::QR ? LeadOrigin::QR : null,
            'consent_contact_at' => $now, 'consent_contact_text' => $data['consent_contact_text'],
            'consent_marketing' => $marketing, 'consent_marketing_at' => $marketing ? $now : null,
            'consent_marketing_text' => $marketing ? ($data['consent_marketing_text'] ?? null) : null,
            'policy_version' => $data['policy_version'], 'event_id' => $data['event_id'] ?? null,
            'referrer' => $data['referrer'] ?? null, 'landing_path' => $data['landing_path'] ?? null, 'channel' => $data['channel'] ?? null,
            'visitor_id' => $data['visitor_id'] ?? null, 'session_id' => $data['session_id'] ?? null,
            'utm_source' => $data['utm_source'] ?? null, 'utm_medium' => $data['utm_medium'] ?? null, 'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_content' => $data['utm_content'] ?? null, 'utm_term' => $data['utm_term'] ?? null,
        ]);

        try {
            $this->attribution->trackLead($car, $request);
        } catch (\Throwable $e) {
            Log::warning('[Leads] Atribuição da proposta falhou (a lead entrou na mesma)', ['lead_id' => $lead->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
        $this->notify($car, $lead);

        return $lead;
    }

    /** O resumo dos avisos, sem dados pessoais: a viatura, a origem e o que o cliente pediu. @return string[] */
    public static function summary(Car $car, CarLead $lead): array
    {
        $lines = ['Viatura: ' . LeadRecipients::carLabel($car) . '.', 'Origem: ' . $lead->originLabel() . '.'];
        $lines[] = 'Financiamento: ' . ($lead->wants_financing ? 'sim' : 'não') . '. Retoma: ' . ($lead->trade_in ? 'sim' : 'não') . '.';

        return $lines;
    }

    private function notify(Car $car, CarLead $lead): void
    {
        $lines = self::summary($car, $lead);
        $path = "/leads?id={$lead->id}";
        try {
            $this->alerts->createSystemAlert((int) $car->company_id, 'opportunity', 'Novo pedido de proposta', implode(' ', $lines), 'high', $path);
        } catch (\Throwable $e) {
            Log::warning('[Leads] Falha ao criar o alerta da proposta', ['lead_id' => $lead->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
        $url = rtrim((string) config('app.frontend_url'), '/') . $path;
        foreach (LeadRecipients::sellerOrAdmins($car) as $user) {
            try {
                Mail::to($user->email)->queue(new AgencyNoticeMail(
                    'Novo pedido de proposta: ' . LeadRecipients::carLabel($car),
                    'Novo pedido de proposta',
                    [...$lines, 'Os dados de contacto do cliente estão na XPLENDOR, para proteger os dados pessoais.'],
                    'Ver o pedido',
                    $url,
                ));
            } catch (\Throwable $e) {
                Log::warning('[Leads] Falha ao enviar o aviso da proposta', ['lead_id' => $lead->id, 'user_id' => $user->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
    }
}
