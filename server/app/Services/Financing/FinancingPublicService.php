<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Jobs\ScanFinancingDocumentJob;
use App\Mail\AgencyNoticeMail;
use App\Models\FinancingConsent;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ContentReview\ContentReviewPresenter;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Leads\LeadRecipients;
use App\Services\Media\MediaService;
use App\Services\Storage\CompanyStorageUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A página de envio (sem conta): mostra a marca da empresa, a lista pedida e o que já foi enviado
 * NESTE link (o documento e a data, sem endereços: o cliente nunca vê nem descarrega ficheiros);
 * guarda os consentimentos e recebe cada ficheiro, confirmado pelo conteúdo e dentro dos limites
 * do link. Um link expirado, revogado, apagado ou desconhecido dá sempre o mesmo 404.
 */
class FinancingPublicService
{
    public const GONE = 'Este link já não está disponível.';

    public function __construct(private readonly VirusScanner $scanner, private readonly AlertService $alerts) {}

    public function resolve(string $token): FinancingLink
    {
        $link = FinancingLink::findByToken($token);
        if (! $link || ! $link->isOpen()) {
            throw new HttpException(404, self::GONE);
        }

        return $link->loadMissing(['company', 'documents']);
    }

    public function payload(FinancingLink $link): array
    {
        $company = $link->company;
        $other = $link->requested['other'] ?? null;
        $items = (array) ($link->requested['items'] ?? []);
        $docs = $link->documents->sortBy('id');
        $privacy = FinancingNotice::privacy($company);

        return [
            'company' => ContentReviewPresenter::companyIdentity($company) + ['fiscal_name' => $company->fiscal_name, 'nipc' => $company->nipc],
            'customer_first_name' => $link->customer_name ? explode(' ', trim($link->customer_name))[0] : null,
            'expires_at' => $link->expires_at->toIso8601String(),
            'items' => array_map(fn ($i) => [
                'key' => $i, 'label' => FinancingDocumentTypes::label($i, $i === 'outro' ? $other : null), 'hint' => FinancingDocumentTypes::ITEMS[$i][1] ?? '',
                'sent' => $docs->where('kind', $i)->map(fn (FinancingDocument $d) => [
                    'name' => $d->name, 'sent_at' => optional($d->created_at)->toIso8601String(),
                    'refused' => $d->scan_status === FinancingDocument::SCAN_INFECTED,
                ])->values()->all(),
            ], $items),
            'limits' => [
                'max_file_bytes' => (int) config('financing.max_file_bytes'), 'max_files' => $link->max_files,
                'remaining_files' => max(0, $link->max_files - $docs->count()), 'types' => ['PDF', 'JPG', 'PNG', 'HEIC'],
            ],
            'notice' => [
                'version' => FinancingNotice::version(), 'privacy' => $privacy,
                'cc_copy' => in_array('cc', $items, true) ? FinancingNotice::ccCopy($company) : null,
                'accepted' => $link->consented_at !== null,
            ],
        ];
    }

    /** O aviso de proteção de dados e, quando o CC é pedido, o consentimento para a cópia. */
    public function consent(FinancingLink $link, bool $privacy, bool $ccCopy): void
    {
        $needsCc = in_array('cc', (array) ($link->requested['items'] ?? []), true);
        if (! $privacy || ($needsCc && ! $ccCopy)) {
            throw ValidationException::withMessages(['consent' => [$needsCc
                ? 'Para enviar os documentos, aceite o aviso de proteção de dados e autorize a cópia do Cartão de Cidadão.'
                : 'Para enviar os documentos, aceite o aviso de proteção de dados.']]);
        }
        if ($link->consented_at) {
            return;
        }
        $now = now();
        DB::transaction(function () use ($link, $needsCc, $now) {
            FinancingConsent::create(['financing_link_id' => $link->id, 'kind' => FinancingConsent::PRIVACY,
                'text' => FinancingNotice::privacyText($link->company), 'version' => FinancingNotice::version(), 'accepted_at' => $now]);
            if ($needsCc) {
                FinancingConsent::create(['financing_link_id' => $link->id, 'kind' => FinancingConsent::CC_COPY,
                    'text' => FinancingNotice::ccCopy($link->company), 'version' => FinancingNotice::version(), 'accepted_at' => $now]);
            }
            $link->forceFill(['consented_at' => $now])->save();
        });
    }

    public function upload(FinancingLink $link, string $item, UploadedFile $upload): FinancingDocument
    {
        if ((bool) config('financing.require_antivirus') && ! $this->scanner->configured()) {
            throw new HttpException(503, 'De momento não é possível enviar documentos. Tente mais tarde.');
        }
        if (! $link->consented_at) {
            throw ValidationException::withMessages(['consent' => ['Aceite primeiro o aviso de proteção de dados.']]);
        }
        if (! in_array($item, (array) ($link->requested['items'] ?? []), true)) {
            throw ValidationException::withMessages(['kind' => ['Este documento não foi pedido.']]);
        }
        $size = (int) $upload->getSize();
        if ($size <= 0 || $size > (int) config('financing.max_file_bytes')) {
            throw ValidationException::withMessages(['file' => ['Cada ficheiro pode ter até ' . intdiv((int) config('financing.max_file_bytes'), 1048576) . ' MB.']]);
        }
        $docs = FinancingDocument::where('financing_link_id', $link->id);
        if ((clone $docs)->count() >= $link->max_files || (clone $docs)->where('kind', $item)->count() >= (int) config('financing.max_files_per_item')) {
            throw ValidationException::withMessages(['file' => ['Já chegou ao número máximo de ficheiros deste link.']]);
        }
        if ((int) (clone $docs)->sum('size_bytes') + $size > $link->max_bytes) {
            throw ValidationException::withMessages(['file' => ['Já chegou ao tamanho máximo deste link.']]);
        }
        $type = FinancingDocumentTypes::detect((string) $upload->getRealPath());
        if (! $type) {
            throw ValidationException::withMessages(['file' => ['Só se aceitam ficheiros PDF, JPG, PNG ou HEIC.']]);
        }
        if (CompanyStorageUsage::totalBytes((int) $link->company_id) + $size > MediaService::quotaBytes()) {
            $this->quotaFull($link);
            throw ValidationException::withMessages(['file' => ['De momento não é possível receber este ficheiro. Fale com ' . ContentReviewPresenter::companyIdentity($link->company)['name'] . '.']]);
        }

        $doc = FinancingFiles::store($link, $item, $upload, $type[0], $type[1]);
        if ($this->scanner->configured()) {
            $doc->forceFill(['scan_status' => FinancingDocument::SCAN_PENDING])->save();
            ScanFinancingDocumentJob::dispatch($doc->id);
        }
        $link->forceFill(['last_upload_at' => now(), 'purge_warned_at' => null])->save();
        $this->notify($link);

        return $doc;
    }

    /** O aviso ao vendedor (no sino e por email), no máximo um por hora em cada link. */
    private function notify(FinancingLink $link): void
    {
        if ($link->last_notified_at && $link->last_notified_at->gt(now()->subHour())) {
            return;
        }
        $link->forceFill(['last_notified_at' => now()])->save();
        $car = $link->car;
        $title = 'Documentos para financiamento recebidos';
        $lines = ['Chegaram documentos para ' . LeadRecipients::carLabel($car) . " (pedido n.º {$link->id}).", 'Estão na ficha da viatura, em "Documentos para financiamento".'];
        $path = $car ? "/cars/{$car->id}/ficha" : '/ficheiros';
        try {
            $this->alerts->createSystemAlert((int) $link->company_id, 'opportunity', $title, implode(' ', $lines), 'medium', $path, ownOnly: true);
        } catch (\Throwable $e) {
            Log::warning('[Financiamento] Falha ao criar o alerta', ['link_id' => $link->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
        $creator = $link->created_by_user_id ? User::where('id', $link->created_by_user_id)->where('company_id', $link->company_id)
            ->whereNull('deactivated_at')->whereNotNull('email')->first() : null;
        $people = $creator ? collect([$creator]) : ($car ? LeadRecipients::sellerOrAdmins($car) : LeadRecipients::admins((int) $link->company_id));
        $url = rtrim((string) config('app.frontend_url'), '/') . $path;
        foreach ($people as $user) {
            try {
                Mail::to($user->email)->queue(new AgencyNoticeMail($title, $title, $lines, 'Ver os documentos', $url));
            } catch (\Throwable $e) {
                Log::warning('[Financiamento] Falha ao enviar o aviso', ['link_id' => $link->id, 'user_id' => $user->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
    }

    /** O espaço da empresa está cheio: os administradores sabem (uma vez por dia). */
    private function quotaFull(FinancingLink $link): void
    {
        if (! Cache::add('financing-quota-full:' . $link->company_id, 1, now()->endOfDay())) {
            return;
        }
        Log::warning('[Financiamento] Espaço da empresa cheio: um envio foi recusado', ['company_id' => $link->company_id, 'link_id' => $link->id]);
        $this->alerts->createSystemAlert((int) $link->company_id, 'urgent', 'Espaço cheio: um cliente não conseguiu enviar documentos',
            'O espaço de ficheiros da empresa está cheio e um envio de documentos para financiamento foi recusado. Apague ficheiros ou contacte a equipa XPLENDOR.', 'high', '/ficheiros', ownOnly: true);
    }
}
