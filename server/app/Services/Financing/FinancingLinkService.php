<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Car;
use App\Models\CarLead;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\User;
use App\Services\Financing\Antivirus\VirusScanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * O lado do painel: o vendedor cria o link privado para um cliente (na ficha da viatura ou na
 * lead), copia-o, revoga-o; vê os documentos recebidos e o estado do link; descarrega (sempre
 * como anexo, por endereço assinado de curta duração, e cada descarga fica registada) e apaga.
 */
class FinancingLinkService
{
    public function __construct(private readonly VirusScanner $scanner) {}

    /** Em produção, sem antivírus ativo não se pedem documentos ao público. */
    public function antivirusBlocks(): bool
    {
        return (bool) config('financing.require_antivirus') && ! $this->scanner->configured();
    }

    /** @param array{customer_name: string, items: string[], other?: ?string, days?: ?int} $data */
    public function create(User $user, Car $car, ?CarLead $lead, array $data): FinancingLink
    {
        if ($this->antivirusBlocks()) {
            throw ValidationException::withMessages(['items' => ['O envio de documentos fica disponível quando o antivírus estiver ativo. Fale com a equipa XPLENDOR.']]);
        }
        $items = array_values(array_intersect(array_keys(FinancingDocumentTypes::ITEMS), $data['items']));
        $days = (int) ($data['days'] ?? config('financing.link_days_default'));
        [$token, $hash] = FinancingLink::newToken();

        return FinancingLink::create([
            'company_id' => $car->company_id, 'car_id' => $car->id, 'car_lead_id' => $lead?->id, 'created_by_user_id' => $user->id,
            'customer_name' => trim($data['customer_name']),
            'requested' => ['items' => $items, 'other' => in_array('outro', $items, true) ? trim((string) ($data['other'] ?? '')) : null],
            'expires_at' => now()->addDays($days)->endOfDay(),
            'max_files' => (int) config('financing.max_files'), 'max_bytes' => (int) config('financing.max_link_bytes'),
            ...$hash,
        ]);
    }

    /** @return Collection<int, FinancingLink> */
    public function forCar(int $companyId, int $carId): Collection
    {
        return $this->query($companyId)->where('car_id', $carId)->get();
    }

    /** @return Collection<int, FinancingLink> */
    public function forLead(int $companyId, int $leadId): Collection
    {
        return $this->query($companyId)->where('car_lead_id', $leadId)->get();
    }

    public function revoke(User $user, FinancingLink $link): FinancingLink
    {
        if ($link->revoked_at === null && $link->purged_at === null) {
            $link->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $user->id])->save();
        }

        return $link;
    }

    public function delete(FinancingLink $link): void
    {
        FinancingFiles::deleteAll($link);
        $link->delete();
    }

    public function deleteDocument(FinancingDocument $doc): void
    {
        FinancingFiles::deleteFile($doc);
    }

    /** Regista a descarga e devolve o endereço assinado (5 minutos), sempre como anexo. */
    public function downloadUrl(User $user, FinancingDocument $doc): string
    {
        $file = $doc->storedFile;
        if (! $file || ! $doc->downloadable()) {
            throw ValidationException::withMessages(['document' => [$doc->scan_status === FinancingDocument::SCAN_PENDING
                ? 'O documento ainda está a ser analisado pelo antivírus. Tente daqui a pouco.' : 'Este documento não pode ser descarregado.']]);
        }
        FinancingAudit::download($user, (int) $doc->company_id, (int) $doc->id, (int) $doc->financing_link_id);

        return URL::temporarySignedRoute('files.stored', now()->addMinutes(5),
            ['file' => $file->id, 'name' => str_replace(['/', '\\'], '-', (string) $file->name), 'download' => 1], absolute: false);
    }

    public function present(FinancingLink $link): array
    {
        $other = $link->requested['other'] ?? null;
        $items = (array) ($link->requested['items'] ?? []);
        $purgeOn = RetentionDays::purgeOn($link);

        return [
            'id' => $link->id, 'customer_name' => $link->customer_name, 'state' => $link->state(),
            'car_id' => $link->car_id, 'car_lead_id' => $link->car_lead_id,
            'items' => array_map(fn ($i) => ['key' => $i, 'label' => FinancingDocumentTypes::label($i, $i === 'outro' ? $other : null)], $items),
            'expires_at' => $link->expires_at->toIso8601String(), 'created_at' => optional($link->created_at)->toIso8601String(),
            'created_by' => $link->creator?->name, 'revoked_at' => optional($link->revoked_at)->toIso8601String(),
            'consented_at' => optional($link->consented_at)->toIso8601String(), 'last_upload_at' => optional($link->last_upload_at)->toIso8601String(),
            'purge_on' => $purgeOn?->toDateString(), 'purged_at' => optional($link->purged_at)->toIso8601String(),
            'documents' => $link->documents->sortBy('id')->map(fn (FinancingDocument $d) => [
                'id' => $d->id, 'kind' => $d->kind, 'kind_label' => FinancingDocumentTypes::label($d->kind, $d->kind === 'outro' ? $other : null),
                'name' => $d->name, 'mime' => $d->mime, 'size_bytes' => $d->size_bytes, 'created_at' => optional($d->created_at)->toIso8601String(),
                'scan_status' => $d->scan_status, 'downloadable' => $d->downloadable() && $d->stored_file_id !== null,
            ])->values()->all(),
        ];
    }

    private function query(int $companyId)
    {
        return FinancingLink::with(['documents', 'creator:id,name', 'company'])->where('company_id', $companyId)->orderByDesc('id');
    }
}
