<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Car;
use App\Models\CarLead;
use App\Models\FinancingConsent;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\FinancingRequest;
use App\Models\User;
use App\Services\Financing\Antivirus\VirusScanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * O lado do painel dos pedidos de documentos.
 *  · Financiamento: o vendedor cria o pedido (na ficha da viatura ou na lead) com 1 ou 2
 *    titulares, a lista de documentos e o ano do IRS; cada titular tem o seu link (copiar,
 *    revogar), os seus dados, os seus documentos e o seu consentimento.
 *  · Retoma: o link das fotografias e do DUA de uma lead (o do "Pedir proposta", com 24 horas,
 *    ou um pedido do vendedor).
 * Descarregar é sempre como anexo, por endereço assinado de curta duração; nos documentos de
 * financiamento e no DUA, cada descarga fica registada.
 */
class FinancingLinkService
{
    public function __construct(private readonly VirusScanner $scanner) {}

    /** Em produção, sem antivírus ativo não se pedem documentos ao público. */
    public function antivirusBlocks(): bool
    {
        return (bool) config('financing.require_antivirus') && ! $this->scanner->configured();
    }

    private function assertAntivirus(): void
    {
        if ($this->antivirusBlocks()) {
            throw ValidationException::withMessages(['items' => ['O envio de documentos fica disponível quando o antivírus estiver ativo. Fale com a equipa XPLENDOR.']]);
        }
    }

    /**
     * @param array{customer_name: string, holders?: int, second_customer_name?: ?string, relationship?: ?string, relationship_other?: ?string,
     *              items: string[], other?: ?string, irs_year?: ?int, days?: ?int} $data
     */
    public function createRequest(User $user, Car $car, ?CarLead $lead, array $data): FinancingRequest
    {
        $this->assertAntivirus();
        $items = array_values(array_intersect(FinancingDocumentTypes::offered(FinancingRequest::FINANCING), $data['items']));
        $expires = now()->addDays((int) ($data['days'] ?? config('financing.link_days_default')))->endOfDay();

        return DB::transaction(function () use ($user, $car, $lead, $data, $items, $expires) {
            $request = FinancingRequest::create([
                'company_id' => $car->company_id, 'car_id' => $car->id, 'car_lead_id' => $lead?->id, 'created_by_user_id' => $user->id,
                'purpose' => FinancingRequest::FINANCING, 'items' => $items,
                'other_description' => in_array('outro', $items, true) ? trim((string) ($data['other'] ?? '')) : null,
                'irs_year' => (int) ($data['irs_year'] ?? FinancingDocumentTypes::defaultIrsYear()),
            ]);
            $this->newLink($request, 1, trim($data['customer_name']), $expires);
            if ((int) ($data['holders'] ?? 1) === 2) {
                $second = $this->newLink($request, 2, isset($data['second_customer_name']) ? trim((string) $data['second_customer_name']) : null, $expires);
                $this->setRelationship($second, $data['relationship'] ?? null, $data['relationship_other'] ?? null);
            }

            return $request;
        });
    }

    /**
     * O segundo titular (pelo vendedor ou pelo primeiro titular, na página dele): o link ativo
     * que já existir (atualiza a relação) ou um novo, com a validade do link do primeiro titular.
     */
    public function addSecondHolder(FinancingRequest $request, ?string $name = null, ?string $relationship = null, ?string $relationshipOther = null): FinancingLink
    {
        if ($request->isTradeIn()) {
            throw ValidationException::withMessages(['holder' => ['A retoma não tem segundo titular.']]);
        }
        $this->assertAntivirus();
        $existing = FinancingLink::where('financing_request_id', $request->id)->where('holder', 2)->orderByDesc('id')->get()->first(fn (FinancingLink $l) => $l->isOpen());
        $link = $existing ?? $this->newLink($request, 2, $name ? trim($name) : null, $this->holderOneExpiry($request));
        if ($existing && $name) {
            $existing->forceFill(['customer_name' => trim($name)])->save();
        }
        $this->setRelationship($link, $relationship, $relationshipOther);

        return $link->fresh();
    }

    /** O link da retoma de uma lead: 24 horas (pedido de proposta) ou os dias que o vendedor escolher. */
    public function createTradeIn(CarLead $lead, ?User $user, \DateTimeInterface $expiresAt): FinancingRequest
    {
        $this->assertAntivirus();

        return DB::transaction(function () use ($lead, $user, $expiresAt) {
            $request = FinancingRequest::create([
                'company_id' => $lead->company_id, 'car_id' => $lead->car_id, 'car_lead_id' => $lead->id, 'created_by_user_id' => $user?->id,
                'purpose' => FinancingRequest::TRADE_IN, 'items' => FinancingDocumentTypes::TRADE_IN,
            ]);
            $this->newLink($request, 1, $lead->name, $expiresAt);

            return $request;
        });
    }

    private function newLink(FinancingRequest $request, int $holder, ?string $name, \DateTimeInterface $expiresAt): FinancingLink
    {
        [, $hash] = FinancingLink::newToken();

        return FinancingLink::create([
            'company_id' => $request->company_id, 'car_id' => $request->car_id, 'car_lead_id' => $request->car_lead_id,
            'created_by_user_id' => $request->created_by_user_id, 'financing_request_id' => $request->id, 'holder' => $holder,
            'customer_name' => $name ?: null, 'requested' => ['items' => $request->items, 'other' => $request->other_description],
            'expires_at' => $expiresAt,
            'max_files' => (int) ($request->isTradeIn() ? config('financing.trade_in_max_files') : config('financing.max_files')),
            'max_bytes' => (int) config('financing.max_link_bytes'),
            ...$hash,
        ]);
    }

    private function setRelationship(FinancingLink $link, ?string $relationship, ?string $other): void
    {
        if ($relationship && array_key_exists($relationship, FinancingLink::RELATIONSHIPS)) {
            $link->forceFill(['relationship' => $relationship, 'relationship_other' => $relationship === 'outro' ? mb_substr(trim((string) $other), 0, 60) : null])->save();
        }
    }

    private function holderOneExpiry(FinancingRequest $request): \DateTimeInterface
    {
        $first = FinancingLink::where('financing_request_id', $request->id)->where('holder', 1)->orderByDesc('id')->first();

        return $first && $first->isOpen() ? $first->expires_at : now()->addDays((int) config('financing.link_days_default'))->endOfDay();
    }

    /** @return Collection<int, FinancingRequest> */
    public function forCar(int $companyId, int $carId): Collection
    {
        return $this->query($companyId, FinancingRequest::FINANCING)->where('car_id', $carId)->get();
    }

    /** @return Collection<int, FinancingRequest> */
    public function forLead(int $companyId, int $leadId, string $purpose = FinancingRequest::FINANCING): Collection
    {
        return $this->query($companyId, $purpose)->where('car_lead_id', $leadId)->get();
    }

    public function revoke(User $user, FinancingLink $link): FinancingLink
    {
        if ($link->revoked_at === null && $link->purged_at === null) {
            $link->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $user->id])->save();
        }

        return $link;
    }

    /** Apaga um titular (o link e os seus documentos); se era o único, o pedido inteiro. */
    public function deleteLink(FinancingLink $link): void
    {
        $request = $link->request;
        FinancingFiles::deleteLinkFiles($link);
        $link->delete();
        if ($request && ! FinancingLink::where('financing_request_id', $request->id)->exists()) {
            $this->deleteRequest($request);
        }
    }

    public function deleteRequest(FinancingRequest $request): void
    {
        FinancingFiles::deleteAll($request);
        $request->delete(); // os links e os consentimentos saem em cascata
    }

    public function deleteDocument(FinancingDocument $doc): void
    {
        FinancingFiles::deleteFile($doc);
    }

    /** Regista a descarga (documentos de financiamento e DUA) e devolve o endereço assinado (5 minutos), sempre como anexo. */
    public function downloadUrl(User $user, FinancingDocument $doc, bool $log = true): string
    {
        $file = $doc->storedFile;
        if (! $file || ! $doc->downloadable()) {
            throw ValidationException::withMessages(['document' => [$doc->scan_status === FinancingDocument::SCAN_PENDING
                ? 'O documento ainda está a ser analisado pelo antivírus. Tente daqui a pouco.' : 'Este documento não pode ser descarregado.']]);
        }
        if ($log) {
            FinancingAudit::download($user, (int) $doc->company_id, (int) $doc->id, (int) $doc->financing_link_id);
        }

        return URL::temporarySignedRoute('files.stored', now()->addMinutes(5),
            ['file' => $file->id, 'name' => str_replace(['/', '\\'], '-', (string) $file->name), 'download' => 1], absolute: false);
    }

    /** Para as fotografias da retoma (JPG e PNG): o endereço assinado (5 minutos) para a miniatura. */
    public function thumbUrl(FinancingDocument $doc): ?string
    {
        $file = $doc->storedFile;
        if (! $file || ! $doc->downloadable() || ! in_array($doc->mime, ['image/jpeg', 'image/png'], true) || $file->source !== FinancingFiles::SOURCE_TRADE_IN) {
            return null;
        }

        return URL::temporarySignedRoute('files.stored', now()->addMinutes(5), ['file' => $file->id, 'name' => str_replace(['/', '\\'], '-', (string) $file->name)], absolute: false);
    }

    /** O pedido para o painel; $personal = false esconde o DUA (quem não tem a área "Financiamento"). */
    public function present(FinancingRequest $request, bool $personal = true): array
    {
        $links = $request->links;
        $states = $links->map(fn (FinancingLink $l) => $l->state());
        $purgeOn = RetentionDays::purgeOn($request);

        return [
            'id' => $request->id, 'purpose' => $request->purpose, 'car_id' => $request->car_id, 'car_lead_id' => $request->car_lead_id,
            'state' => $request->purged_at ? FinancingLink::PURGED : ($states->contains(FinancingLink::ACTIVE) ? FinancingLink::ACTIVE : ($states->first() ?? FinancingLink::EXPIRED)),
            'items' => array_map(fn ($i) => ['key' => $i, 'label' => FinancingDocumentTypes::label($i, $request)], (array) $request->items),
            'irs_year' => $request->irs_year, 'notes' => $request->notes,
            'created_at' => optional($request->created_at)->toIso8601String(), 'created_by' => $request->creator?->name,
            'last_upload_at' => optional($request->last_upload_at)->toIso8601String(), 'purge_on' => $purgeOn?->toDateString(),
            'purged_at' => optional($request->purged_at)->toIso8601String(),
            'can_add_second_holder' => ! $request->isTradeIn() && ! $request->purged_at && ! $links->contains(fn (FinancingLink $l) => $l->holder === 2 && $l->isOpen()),
            'holders' => $links->map(fn (FinancingLink $l) => $this->presentLink($l, $request, $personal))->values()->all(),
        ];
    }

    private function presentLink(FinancingLink $link, FinancingRequest $request, bool $personal): array
    {
        $docs = $link->documents->sortBy('id');
        $hidden = $personal ? 0 : $docs->filter(fn (FinancingDocument $d) => in_array($d->kind, FinancingDocumentTypes::PERSONAL, true))->count();
        $copy = $link->consents->firstWhere('kind', FinancingConsent::CC_COPY) ?? $link->consents->firstWhere('kind', FinancingConsent::PRIVACY);

        return [
            'link_id' => $link->id, 'holder' => $link->holder, 'customer_name' => $link->customer_name, 'state' => $link->state(),
            'expires_at' => $link->expires_at->toIso8601String(), 'revoked_at' => optional($link->revoked_at)->toIso8601String(),
            'consented_at' => optional($link->consented_at)->toIso8601String(), 'signed_name' => $copy?->signed_name,
            'relationship' => $link->relationship, 'relationship_label' => $link->relationshipLabel(),
            'phone' => $link->phone, 'email' => $link->email, 'housing' => $link->housing,
            'housing_label' => $link->housing ? (FinancingLink::HOUSING[$link->housing] ?? $link->housing) . ($link->housing === 'outra' && $link->housing_other ? " ({$link->housing_other})" : '') : null,
            'housing_monthly_eur' => $link->housing_monthly_eur !== null ? (float) $link->housing_monthly_eur : null,
            'details_at' => optional($link->details_at)->toIso8601String(),
            'hidden_documents' => $hidden,
            'documents' => $docs->reject(fn (FinancingDocument $d) => ! $personal && in_array($d->kind, FinancingDocumentTypes::PERSONAL, true))
                ->map(fn (FinancingDocument $d) => [
                    'id' => $d->id, 'kind' => $d->kind, 'kind_label' => FinancingDocumentTypes::label($d->kind, $request),
                    'name' => $d->name, 'mime' => $d->mime, 'size_bytes' => $d->size_bytes, 'created_at' => optional($d->created_at)->toIso8601String(),
                    'scan_status' => $d->scan_status, 'downloadable' => $d->downloadable() && $d->stored_file_id !== null,
                    'personal' => in_array($d->kind, FinancingDocumentTypes::PERSONAL, true),
                    'thumb_url' => $request->isTradeIn() ? $this->thumbUrl($d) : null,
                ])->values()->all(),
        ];
    }

    private function query(int $companyId, string $purpose)
    {
        return FinancingRequest::with(['links.documents.storedFile', 'links.consents', 'creator:id,name', 'company'])
            ->where('company_id', $companyId)->where('purpose', $purpose)->orderByDesc('id');
    }
}
