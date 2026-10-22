<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Jobs\ScanFinancingDocumentJob;
use App\Mail\AgencyNoticeMail;
use App\Models\FinancingConsent;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\FinancingRequest;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ContentReview\ContentReviewPresenter;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Leads\LeadRecipients;
use App\Services\Media\MediaService;
use App\Services\Storage\CompanyStorageUsage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A página de envio (sem conta) e a API que o site usa com o mesmo código: o pedido (financiamento
 * de um titular, ou a retoma de uma lead), a marca da empresa, os itens pedidos (com o máximo, os
 * tipos e o tamanho), os ficheiros já enviados NESTE link (id, nome, tamanho e estado, sem
 * endereços: o cliente nunca vê nem descarrega ficheiros), o aviso de proteção de dados, os dados
 * do titular, as observações da retoma e o segundo titular.
 *
 * Erros: 404 sempre igual (código inválido, expirado, revogado, apagado ou de outra lead), 409 com
 * o código "consent_required" (o aviso por aceitar) e 422 com um código e o motivo.
 */
class FinancingPublicService
{
    public const GONE = 'Este link já não está disponível.';
    public const SECOND_HOLDER_NOTICE = 'Esta página destina-se ao segundo titular. Se não é a pessoa indicada, não continue.';
    public const SECOND_HOLDER_HELP = 'A inclusão de um segundo titular pode ajudar na aprovação do financiamento, porque permite considerar os rendimentos de ambos.';

    public function __construct(
        private readonly VirusScanner $scanner,
        private readonly AlertService $alerts,
        private readonly FinancingLinkService $links,
    ) {}

    public function resolve(string $token): FinancingLink
    {
        $link = FinancingLink::findByToken($token);
        if (! $link || ! $link->isOpen() || ! $link->financing_request_id) {
            self::fail(404, 'not_found', self::GONE);
        }

        return $link->loadMissing(['company', 'documents', 'request']);
    }

    /** Uma resposta de erro com o código para o site. */
    public static function fail(int $status, string $code, string $message, ?string $field = null): never
    {
        $body = ['success' => false, 'message' => $message, 'code' => $code];
        if ($field) {
            $body['errors'] = [$field => [$message]];
        }
        throw new HttpResponseException(response()->json($body, $status, [
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'private, no-store',
        ]));
    }

    public function payload(FinancingLink $link): array
    {
        $request = $link->request;
        $company = $link->company;
        $docs = $link->documents->sortBy('id');
        $items = (array) $request->items;
        $maxBytes = (int) config('financing.max_file_bytes');
        $privacy = FinancingNotice::privacy($company, $request->purpose);
        $needsId = $this->needsIdCopy($request);
        $out = [
            'purpose' => $request->purpose,
            'holder' => $link->holder,
            'holder_notice' => $link->holder === 2 ? self::SECOND_HOLDER_NOTICE : null,
            'company' => ContentReviewPresenter::companyIdentity($company) + ['fiscal_name' => $company->fiscal_name, 'nipc' => $company->nipc],
            'customer_first_name' => $link->customer_name ? explode(' ', trim($link->customer_name))[0] : null,
            'expires_at' => $link->expires_at->toIso8601String(),
            'items' => array_map(fn ($i) => [
                'kind' => $i, 'name' => FinancingDocumentTypes::label($i, $request), 'hint' => FinancingDocumentTypes::hint($i, $request),
                'required' => FinancingDocumentTypes::required($i), 'max_files' => FinancingDocumentTypes::maxFiles($i),
                'accepted_types' => FinancingDocumentTypes::types($i), 'max_file_bytes' => $maxBytes,
                'sent_count' => $docs->where('kind', $i)->where('scan_status', '!=', FinancingDocument::SCAN_INFECTED)->count(),
            ], $items),
            'files' => $docs->map(fn (FinancingDocument $d) => self::file($d))->values()->all(),
            'notice' => [
                'title' => $privacy['title'], 'paragraphs' => $privacy['paragraphs'], 'text' => implode("\n", $privacy['paragraphs']),
                'version' => FinancingNotice::version(), 'accepted' => $link->consented_at !== null,
                'id_copy' => $needsId ? FinancingNotice::idCopy($company) : null,
                'name_required' => ! $request->isTradeIn(),
            ],
            'notes' => $request->isTradeIn() ? $request->notes : null,
            'limits' => ['max_files' => $link->max_files, 'remaining_files' => max(0, $link->max_files - $docs->count()), 'max_file_bytes' => $maxBytes],
            'details' => null,
            'second_holder' => null,
        ];
        if (! $request->isTradeIn()) {
            $out['details'] = [
                'phone' => $link->phone, 'email' => $link->email, 'housing' => $link->housing, 'housing_other' => $link->housing_other,
                'housing_monthly_eur' => $link->housing_monthly_eur !== null ? (float) $link->housing_monthly_eur : null,
                'completed' => $link->details_at !== null,
                'housing_options' => collect(FinancingLink::HOUSING)->map(fn ($label, $value) => [
                    'value' => $value, 'label' => $value === 'outra' ? 'Outra (por exemplo, a viver com familiares)' : $label,
                    'needs_amount' => in_array($value, FinancingLink::HOUSING_WITH_AMOUNT, true),
                ])->values()->all(),
            ];
        }
        if (! $request->isTradeIn() && $link->holder === 1) {
            $second = FinancingLink::where('financing_request_id', $request->id)->where('holder', 2)->orderByDesc('id')->get()->first(fn (FinancingLink $l) => $l->isOpen());
            $out['second_holder'] = [
                'question' => 'Vai incluir um segundo titular?', 'help' => self::SECOND_HOLDER_HELP,
                'relationship_options' => collect(FinancingLink::RELATIONSHIPS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
                'included' => $second !== null,
                'relationship' => $second?->relationship, 'relationship_other' => $second?->relationship_other,
                'url' => $second?->url(),
            ];
        }

        return $out;
    }

    /** Um ficheiro enviado, como o site o vê. */
    public static function file(FinancingDocument $d): array
    {
        $state = match ($d->scan_status) {
            FinancingDocument::SCAN_PENDING => 'em_analise',
            FinancingDocument::SCAN_INFECTED => 'recusado',
            default => 'recebido',
        };

        return [
            'id' => $d->id, 'kind' => $d->kind, 'name' => $d->name, 'size_bytes' => (int) $d->size_bytes, 'state' => $state,
            'reason' => $state === 'recusado' ? 'antivirus' : null,
            'reason_text' => $state === 'recusado' ? 'A verificação de segurança recusou este ficheiro. Envie-o de novo, de preferência uma fotografia ou um PDF tirado diretamente do original.' : null,
            'sent_at' => optional($d->created_at)->toIso8601String(),
        ];
    }

    private function needsIdCopy(FinancingRequest $request): bool
    {
        return ! $request->isTradeIn() && array_intersect(FinancingDocumentTypes::ID_DOCUMENTS, (array) $request->items) !== [];
    }

    /** O aviso de proteção de dados e, quando o documento de identificação é pedido, o consentimento para a cópia; no financiamento, com o nome completo. */
    public function consent(FinancingLink $link, bool $privacy, bool $idCopy, ?string $name): void
    {
        $request = $link->request;
        $needsId = $this->needsIdCopy($request);
        $name = trim((string) $name);
        if (! $privacy || ($needsId && ! $idCopy)) {
            self::fail(422, 'consent_incomplete', $needsId
                ? 'Para enviar os documentos, aceite o aviso de proteção de dados e autorize a cópia do documento de identificação.'
                : 'Para enviar, aceite o aviso de proteção de dados.', 'consent');
        }
        if (! $request->isTradeIn() && (mb_strlen($name) < 3 || mb_strlen($name) > 160)) {
            self::fail(422, 'name_required', 'Escreva o seu nome completo.', 'name');
        }
        if ($link->consented_at) {
            return;
        }
        $now = now();
        $signed = $request->isTradeIn() ? null : $name;
        DB::transaction(function () use ($link, $request, $needsId, $now, $signed) {
            FinancingConsent::create(['financing_link_id' => $link->id, 'kind' => FinancingConsent::PRIVACY, 'signed_name' => $signed,
                'text' => FinancingNotice::privacyText($link->company, $request->purpose), 'version' => FinancingNotice::version(), 'accepted_at' => $now]);
            if ($needsId) {
                FinancingConsent::create(['financing_link_id' => $link->id, 'kind' => FinancingConsent::CC_COPY, 'signed_name' => $signed,
                    'text' => FinancingNotice::idCopy($link->company), 'version' => FinancingNotice::version(), 'accepted_at' => $now]);
            }
            $link->forceFill(['consented_at' => $now])->save();
        });
    }

    /** Os dados do titular (só no financiamento). @param array<string, mixed> $data */
    public function details(FinancingLink $link, array $data): void
    {
        if ($link->request->isTradeIn()) {
            self::fail(422, 'not_available', 'Este pedido não tem dados para preencher.');
        }
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $housing = (string) ($data['housing'] ?? '');
        if (! preg_match('/^\+?[0-9 ()-]{9,20}$/', $phone)) {
            self::fail(422, 'invalid_phone', 'O telemóvel não parece válido.', 'phone');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            self::fail(422, 'invalid_email', 'O email não parece válido.', 'email');
        }
        if (! array_key_exists($housing, FinancingLink::HOUSING)) {
            self::fail(422, 'invalid_housing', 'Escolha a sua situação de habitação.', 'housing');
        }
        $other = $housing === 'outra' ? mb_substr(trim((string) ($data['housing_other'] ?? '')), 0, 120) : null;
        $amount = null;
        if (in_array($housing, FinancingLink::HOUSING_WITH_AMOUNT, true)) {
            $raw = str_replace([' ', ','], ['', '.'], (string) ($data['housing_monthly_eur'] ?? ''));
            if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100000) {
                self::fail(422, 'invalid_amount', $housing === 'arrendada' ? 'Indique o valor mensal da renda.' : 'Indique o valor mensal da prestação.', 'housing_monthly_eur');
            }
            $amount = round((float) $raw, 2);
        }
        $link->forceFill(['phone' => $phone, 'email' => $email, 'housing' => $housing, 'housing_other' => $other ?: null,
            'housing_monthly_eur' => $amount, 'details_at' => now()])->save();
    }

    /** As observações da retoma (até 2000 caracteres). */
    public function notes(FinancingLink $link, ?string $notes): void
    {
        $request = $link->request;
        if (! $request->isTradeIn()) {
            self::fail(422, 'not_available', 'Este pedido não tem observações.');
        }
        $notes = trim((string) $notes);
        if (mb_strlen($notes) > 2000) {
            self::fail(422, 'too_long', 'As observações podem ter até 2000 caracteres.', 'notes');
        }
        $request->forceFill(['notes' => $notes !== '' ? $notes : null])->save();
    }

    /** O primeiro titular inclui um segundo titular: o link próprio do segundo (para copiar) e o aviso ao vendedor. */
    public function secondHolder(FinancingLink $link, ?string $relationship, ?string $relationshipOther): FinancingLink
    {
        $request = $link->request;
        if ($request->isTradeIn() || $link->holder !== 1) {
            self::fail(422, 'not_available', 'Só o primeiro titular pode incluir um segundo titular.');
        }
        if (! array_key_exists((string) $relationship, FinancingLink::RELATIONSHIPS)) {
            self::fail(422, 'invalid_relationship', 'Escolha a relação ou o grau de parentesco do segundo titular.', 'relationship');
        }
        if ($relationship === 'outro' && trim((string) $relationshipOther) === '') {
            self::fail(422, 'invalid_relationship', 'Indique qual é a relação.', 'relationship_other');
        }
        $had = FinancingLink::where('financing_request_id', $request->id)->where('holder', 2)->get()->contains(fn (FinancingLink $l) => $l->isOpen());
        $second = $this->links->addSecondHolder($request, null, $relationship, $relationshipOther);
        if (! $had) {
            $this->notifyPeople($request, 'Segundo titular incluído', [
                'O cliente do ' . ($request->car ? LeadRecipients::carLabel($request->car) : 'pedido') . " (pedido n.º {$request->id}) incluiu um segundo titular ({$second->relationshipLabel()}).",
                'O segundo titular tem o seu próprio link, os seus dados e o seu consentimento.',
            ]);
        }

        return $second;
    }

    public function upload(FinancingLink $link, string $item, UploadedFile $upload): FinancingDocument
    {
        $request = $link->request;
        if ((bool) config('financing.require_antivirus') && ! $this->scanner->configured()) {
            self::fail(503, 'unavailable', 'De momento não é possível enviar documentos. Tente mais tarde.');
        }
        if (! $link->consented_at) {
            self::fail(409, 'consent_required', 'Aceite primeiro o aviso de proteção de dados.', 'consent');
        }
        if (! in_array($item, (array) $request->items, true)) {
            self::fail(422, 'kind_not_requested', $request->isTradeIn() ? 'Este item não faz parte da retoma.' : 'Este documento não foi pedido.', 'kind');
        }
        $size = (int) $upload->getSize();
        $maxBytes = (int) config('financing.max_file_bytes');
        if ($size <= 0 || $size > $maxBytes) {
            self::fail(422, 'too_large', 'Cada ficheiro pode ter até ' . intdiv($maxBytes, 1048576) . ' MB.', 'file');
        }
        $docs = FinancingDocument::where('financing_link_id', $link->id)->where('scan_status', '!=', FinancingDocument::SCAN_INFECTED);
        if ((clone $docs)->count() >= $link->max_files) {
            self::fail(422, 'limit_reached', "Já chegou ao número máximo de ficheiros ({$link->max_files}).", 'file');
        }
        if ((clone $docs)->where('kind', $item)->count() >= FinancingDocumentTypes::maxFiles($item)) {
            self::fail(422, 'limit_reached', 'Já enviou o número máximo de ficheiros para "' . FinancingDocumentTypes::label($item, $request) . '" (' . FinancingDocumentTypes::maxFiles($item) . ').', 'file');
        }
        if ((int) (clone $docs)->sum('size_bytes') + $size > $link->max_bytes) {
            self::fail(422, 'limit_reached', 'Já chegou ao tamanho máximo deste link.', 'file');
        }
        $type = FinancingDocumentTypes::detect((string) $upload->getRealPath());
        if (! $type || ! FinancingDocumentTypes::accepts($item, $type[0])) {
            self::fail(422, 'invalid_type', 'Aqui só se aceitam ficheiros ' . implode(', ', array_map('strtoupper', FinancingDocumentTypes::types($item))) . '.', 'file');
        }
        if (CompanyStorageUsage::totalBytes((int) $link->company_id) + $size > MediaService::quotaBytes()) {
            $this->quotaFull($link);
            self::fail(422, 'unavailable', 'De momento não é possível receber este ficheiro. Fale com ' . ContentReviewPresenter::companyIdentity($link->company)['name'] . '.', 'file');
        }

        $doc = FinancingFiles::store($link, $item, $upload, $type[0], $type[1]);
        if ($this->scanner->configured()) {
            $doc->forceFill(['scan_status' => FinancingDocument::SCAN_PENDING])->save();
            ScanFinancingDocumentJob::dispatch($doc->id);
        }
        $now = now();
        $link->forceFill(['last_upload_at' => $now])->save();
        $request->forceFill(['last_upload_at' => $now, 'purge_warned_at' => null])->save();
        $this->notifyArrival($request);

        return $doc->fresh();
    }

    /** O aviso ao vendedor quando chegam ficheiros, no máximo um por hora em cada pedido. */
    private function notifyArrival(FinancingRequest $request): void
    {
        if ($request->last_notified_at && $request->last_notified_at->gt(now()->subHour())) {
            return;
        }
        $request->forceFill(['last_notified_at' => now()])->save();
        $car = $request->car;
        if ($request->isTradeIn()) {
            $this->notifyPeople($request, 'Fotografias da retoma recebidas', [
                'Chegaram fotografias ou o DUA da retoma do pedido de proposta para ' . LeadRecipients::carLabel($car) . " (lead n.º {$request->car_lead_id}).",
                'Estão no detalhe da lead, em "Retoma".',
            ]);

            return;
        }
        $this->notifyPeople($request, 'Documentos para financiamento recebidos', [
            'Chegaram documentos para ' . LeadRecipients::carLabel($car) . " (pedido n.º {$request->id}).", 'Estão na ficha da viatura, em "Documentos para financiamento".',
        ]);
    }

    /** O sino da empresa e um email a quem criou o pedido (sem quem, ao vendedor da viatura ou aos administradores). @param string[] $lines */
    private function notifyPeople(FinancingRequest $request, string $title, array $lines): void
    {
        $car = $request->car;
        $path = $request->isTradeIn() && $request->car_lead_id ? "/leads?id={$request->car_lead_id}" : ($car ? "/cars/{$car->id}/ficha" : '/ficheiros');
        try {
            $this->alerts->createSystemAlert((int) $request->company_id, 'opportunity', $title, implode(' ', $lines), 'medium', $path, ownOnly: ! $request->isTradeIn());
        } catch (\Throwable $e) {
            Log::warning('[Financiamento] Falha ao criar o alerta', ['request_id' => $request->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
        $creator = $request->created_by_user_id ? User::where('id', $request->created_by_user_id)->where('company_id', $request->company_id)
            ->whereNull('deactivated_at')->whereNotNull('email')->first() : null;
        $people = $creator ? collect([$creator]) : ($car ? LeadRecipients::sellerOrAdmins($car) : LeadRecipients::admins((int) $request->company_id));
        $url = rtrim((string) config('app.frontend_url'), '/') . $path;
        foreach ($people as $user) {
            try {
                Mail::to($user->email)->queue(new AgencyNoticeMail($title, $title, $lines, 'Abrir na XPLENDOR', $url));
            } catch (\Throwable $e) {
                Log::warning('[Financiamento] Falha ao enviar o aviso', ['request_id' => $request->id, 'user_id' => $user->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
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
            'O espaço de ficheiros da empresa está cheio e um envio de documentos foi recusado. Apague ficheiros ou contacte a equipa XPLENDOR.', 'high', '/ficheiros', ownOnly: true);
    }
}
