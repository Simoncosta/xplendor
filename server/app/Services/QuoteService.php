<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\QuoteCreatedForCompanyMail;
use App\Mail\QuoteDecisionMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Models\User;
use App\Repositories\Contracts\QuoteRepositoryInterface;
use App\Services\Quotes\QuoteCalculator;
use App\Services\Quotes\QuoteNumberAllocator;
use App\Services\Quotes\QuotePdfRenderer;
use App\Services\Quotes\QuoteSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Orçamentos de serviços (só a equipa XPLENDOR; o acesso é garantido
 * no grupo /admin). Regras:
 *  · rascunho → enviado (número no primeiro envio, validade de 30 dias, versão
 *    congelada com o PDF; só aqui se avisa a empresa ligada) → aceite | recusado |
 *    expirado (job diário; rascunhos nunca expiram);
 *  · alterar um enviado, recusado ou expirado cria a versão seguinte em rascunho;
 *    um aceite não se altera (duplica-se);
 *  · totais MENSAL e VALOR ÚNICO separados, sem IVA (QuoteCalculator);
 *  · o cliente vem do módulo Clientes da empresa da equipa, ou é criado ali.
 * Emails via queue, fail-safe (nunca bloqueiam a operação).
 */
class QuoteService extends BaseService
{
    private const NOTIFY_RECIPIENT = 'simonfrtd@gmail.com';

    public function __construct(
        protected QuoteRepositoryInterface $quoteRepository,
        private readonly QuoteNumberAllocator $numbers,
        private readonly QuotePdfRenderer $pdf,
    ) {
        parent::__construct($quoteRepository);
    }

    // ── Empresa da equipa e clientes ─────────────────────────────────────────

    /** Empresa dona dos Clientes da XPLENDOR: XPLENDOR_COMPANY_ID ou a empresa do root. */
    public function teamCompanyId(User $actor): int
    {
        $id = (int) (config('quotes.team_company_id') ?: $actor->company_id);
        if ($id <= 0 || ! Company::whereKey($id)->exists()) {
            $this->reject('customer_id', 'A empresa da equipa XPLENDOR não está configurada (XPLENDOR_COMPANY_ID).');
        }

        return $id;
    }

    /** Clientes da XPLENDOR (não arquivados), com pesquisa por nome, email ou telefone. */
    public function searchCustomers(User $actor, ?string $search): array
    {
        return Customer::where('company_id', $this->teamCompanyId($actor))
            ->where('archived', false)
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')->limit(30)
            ->get(['id', 'name', 'email', 'phone', 'nif'])->toArray();
    }

    /** Cria um cliente no módulo Clientes da empresa da equipa. */
    public function createCustomer(User $actor, array $data): Customer
    {
        return Customer::create([
            'company_id' => $this->teamCompanyId($actor),
            'name'       => trim($data['name']),
            'phone'      => $data['phone'] ?? null,
            'email'      => $data['email'] ?? null,
        ]);
    }

    // ── Criar, alterar, versões ──────────────────────────────────────────────

    public function createQuote(array $data, User $actor): Quote
    {
        return DB::transaction(function () use ($data, $actor) {
            $quote = new Quote([
                'status' => 'draft', 'version' => 1, 'created_by_user_id' => $actor->id,
                'minimum_contract_months' => config('quotes.defaults.minimum_contract_months'),
                'payment_terms' => config('quotes.defaults.payment_terms'),
            ]);
            // Condições por omissão quando o formulário as traz vazias (editáveis depois).
            foreach (['payment_terms', 'minimum_contract_months'] as $field) {
                if (array_key_exists($field, $data) && ($data[$field] === null || $data[$field] === '')) {
                    unset($data[$field]);
                }
            }
            $this->fill($quote, $data, $actor);

            return $quote->fresh(['lines']);
        });
    }

    /** Valores por omissão de um orçamento novo (para o formulário). */
    public function defaults(): array
    {
        return config('quotes.defaults') + ['validity_days' => (int) config('quotes.validity_days')];
    }

    /**
     * Rascunho: altera. Enviado, recusado ou expirado: cria a versão seguinte em
     * rascunho (a anterior fica congelada com o PDF que o cliente tem). Aceite: não.
     */
    public function updateQuote(Quote $quote, array $data, User $actor): Quote
    {
        return DB::transaction(function () use ($quote, $data, $actor) {
            $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($quote->status === 'accepted') {
                $this->reject('status', 'Um orçamento aceite não se altera. Duplique-o para fazer um novo.');
            }
            if (in_array($quote->status, Quote::REOPENABLE, true)) {
                $quote->fill([
                    'status' => 'draft', 'version' => $quote->version + 1,
                    'sent_at' => null, 'valid_until' => null, 'decided_at' => null, 'expired_at' => null,
                ]);
            }
            $this->fill($quote, $data, $actor);

            return $quote->fresh(['lines']);
        });
    }

    /** Copia um orçamento para um rascunho novo (sem número; o número vem no envio). */
    public function duplicate(Quote $source, User $actor): Quote
    {
        return DB::transaction(function () use ($source, $actor) {
            $source->loadMissing('lines');
            $copy = $source->replicate([
                'number', 'number_year', 'number_seq', 'sent_at', 'valid_until', 'decided_at', 'expired_at', 'legacy_status',
            ]);
            $copy->fill(['status' => 'draft', 'version' => 1, 'created_by_user_id' => $actor->id]);
            $copy->save();
            foreach ($source->lines as $line) {
                $copy->lines()->create($line->only([
                    'position', 'catalog_item_id', 'name', 'description', 'unit', 'billing_type', 'quantity', 'unit_price', 'discount_type', 'discount_value', 'line_total',
                ]));
            }

            return $copy->fresh(['lines']);
        });
    }

    /** Só se apagam rascunhos que nunca foram enviados (os outros ficam no histórico). */
    public function deleteQuote(Quote $quote): void
    {
        if ($quote->status !== 'draft' || $quote->number !== null) {
            $this->reject('status', 'Só se apagam rascunhos que nunca foram enviados.');
        }
        $quote->delete();
    }

    /** Aplica os dados do formulário: cliente, ligação à empresa, textos, condições, linhas e totais. */
    private function fill(Quote $quote, array $data, User $actor): void
    {
        if (! empty($data['new_customer'])) {
            $customer = $this->createCustomer($actor, $data['new_customer']);
        } elseif (array_key_exists('customer_id', $data) && $data['customer_id']) {
            $customer = Customer::where('company_id', $this->teamCompanyId($actor))->find($data['customer_id']);
            if (! $customer) {
                $this->reject('customer_id', 'Cliente não encontrado nos Clientes da XPLENDOR.');
            }
        } else {
            $customer = null;
        }
        if ($customer) {
            $quote->fill([
                'customer_id'    => $customer->id,
                'client_name'    => $customer->name,
                'client_email'   => $customer->email,
                'client_phone'   => $customer->phone,
                'client_contact' => $customer->email ?: $customer->phone,
            ]);
        }
        if (! $quote->client_name) {
            $this->reject('customer_id', 'Escolha um cliente ou crie um novo.');
        }

        foreach (['company_id', 'title', 'intro', 'notes', 'minimum_contract_months', 'payment_terms',
            'global_discount_type', 'global_discount_value', 'global_discount_target', 'global_discount_label'] as $field) {
            if (array_key_exists($field, $data)) {
                $quote->{$field} = $data[$field] === '' ? null : $data[$field];
            }
        }
        if ($quote->global_discount_type !== 'amount') {
            $quote->global_discount_target = null;   // em % aplica-se aos dois totais
        }
        if (! $quote->global_discount_type || ! (float) $quote->global_discount_value) {
            $quote->global_discount_type = null;
            $quote->global_discount_value = null;
            $quote->global_discount_target = null;
        }

        $lines = array_key_exists('lines', $data)
            ? array_values($data['lines'])
            : ($quote->exists ? $quote->lines()->get()->map->only(['catalog_item_id', 'name', 'description', 'unit', 'billing_type', 'quantity', 'unit_price', 'discount_type', 'discount_value'])->all() : []);
        $calc = QuoteCalculator::compute($lines, [
            'type' => $quote->global_discount_type, 'value' => $quote->global_discount_value, 'target' => $quote->global_discount_target,
        ]);

        $quote->total_monthly = $calc['buckets']['monthly']['total'];
        $quote->total_one_off = $calc['buckets']['one_off']['total'];
        $quote->amount = $quote->total_one_off;   // coluna antiga: espelha o valor único
        $quote->description = $quote->title ?: ($lines[0]['name'] ?? '');
        $quote->save();

        if (array_key_exists('lines', $data)) {
            $quote->lines()->delete();
            foreach ($calc['lines'] as $i => $line) {
                $quote->lines()->create([
                    'position'        => $i,
                    'catalog_item_id' => $line['catalog_item_id'] ?? null,
                    'name'            => $line['name'],
                    'description'     => $line['description'] ?? null,
                    'unit'            => $line['unit'],
                    'billing_type'    => $line['billing_type'],
                    'quantity'        => $line['quantity'],
                    'unit_price'      => $line['unit_price'],
                    'discount_type'   => ($line['discount_value'] ?? null) ? ($line['discount_type'] ?? null) : null,
                    'discount_value'  => ($line['discount_value'] ?? null) ?: null,
                    'line_total'      => $line['line_total'],
                ]);
            }
        }
    }

    // ── Enviar, decidir, expirar ─────────────────────────────────────────────

    /**
     * Marca como enviado (o PDF foi enviado ao cliente por fora): número no primeiro
     * envio, validade de 30 dias (hora de Lisboa), versão congelada com o PDF. Só aqui
     * se avisa a empresa ligada.
     */
    public function send(Quote $quote, User $actor): Quote
    {
        $quote = DB::transaction(function () use ($quote, $actor) {
            $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($quote->status !== 'draft') {
                $this->reject('status', 'Só se envia um rascunho.');
            }
            if ($quote->lines()->count() === 0) {
                $this->reject('lines', 'Acrescente pelo menos uma linha antes de enviar.');
            }

            $now = CarbonImmutable::now(Quote::TIMEZONE);
            if ($quote->number === null) {
                $n = $this->numbers->next((int) $now->format('Y'));
                $quote->fill(['number' => $n['number'], 'number_year' => $n['year'], 'number_seq' => $n['seq']]);
            }
            $quote->fill([
                'status'      => 'sent',
                'sent_at'     => $now->utc(),
                'valid_until' => $now->addDays((int) config('quotes.validity_days'))->toDateString(),
            ]);
            $quote->save();

            $this->freezeVersion($quote, $actor);

            return $quote;
        });

        if ($quote->isLinkedToCompany()) {
            $this->notifyCompanyOfNewQuote($quote);
        }

        return $quote->fresh(['lines', 'versions']);
    }

    /** Congela a versão enviada: snapshot e PDF (disco local, privado). */
    private function freezeVersion(Quote $quote, ?User $actor): QuoteVersion
    {
        $snapshot = QuoteSnapshot::fromQuote($quote->fresh(['lines', 'customer']));
        $bytes = $this->pdf->render($snapshot);
        $path = "quotes/{$quote->id}/{$quote->number}-v{$quote->version}.pdf";
        Storage::disk('local')->put($path, $bytes);

        return QuoteVersion::create([
            'quote_id'        => $quote->id,
            'version'         => $quote->version,
            'number'          => $quote->number,
            'snapshot'        => $snapshot,
            'pdf_path'        => $path,
            'pdf_sha256'      => hash('sha256', $bytes),
            'sent_at'         => $quote->sent_at,
            'valid_until'     => $quote->valid_until,
            'sent_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * Aceite ou recusado, só a partir de enviado. Nos ligados a uma empresa decide a
     * empresa (no painel dela); nos restantes, a equipa regista a resposta do cliente.
     */
    public function decide(Quote $quote, bool $accepted, bool $byCompany): Quote
    {
        $quote = DB::transaction(function () use ($quote, $accepted, $byCompany) {
            $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($byCompany && ! $quote->isLinkedToCompany()) {
                $this->reject('status', 'Este orçamento não está ligado a uma empresa.');
            }
            if (! $byCompany && $quote->isLinkedToCompany()) {
                $this->reject('status', 'Este orçamento está ligado a uma empresa: a decisão é feita por ela, no painel dela.');
            }
            if ($quote->status !== 'sent') {
                $this->reject('status', 'Só se aceita ou recusa um orçamento enviado e em aberto.');
            }
            $quote->update(['status' => $accepted ? 'accepted' : 'refused', 'decided_at' => now()]);

            return $quote;
        });

        if ($byCompany) {
            $this->notifyDecision($quote, $accepted);
        }

        return $quote->fresh(['lines', 'versions']);
    }

    /** Expira os enviados cuja validade já passou (dia de Lisboa). Rascunhos nunca expiram. */
    public function expireDue(?CarbonInterface $now = null): int
    {
        $today = CarbonImmutable::instance($now ?? now())->setTimezone(Quote::TIMEZONE)->toDateString();
        $ids = Quote::where('status', 'sent')->whereNotNull('valid_until')->whereDate('valid_until', '<', $today)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $q = Quote::whereKey($id)->lockForUpdate()->first();
                if ($q && $q->status === 'sent') {
                    $q->update(['status' => 'expired', 'expired_at' => now()]);
                }
            });
        }

        return $ids->count();
    }

    // ── PDF ──────────────────────────────────────────────────────────────────

    /** PDF de uma versão enviada: o ficheiro congelado (gera e guarda se ainda não existir). */
    public function versionPdf(QuoteVersion $version): string
    {
        $disk = Storage::disk('local');
        if ($version->pdf_path && $disk->exists($version->pdf_path)) {
            return $disk->get($version->pdf_path);
        }
        $bytes = $this->pdf->render($version->snapshot);
        $path = "quotes/{$version->quote_id}/{$version->number}-v{$version->version}.pdf";
        $disk->put($path, $bytes);
        $version->forceFill(['pdf_path' => $path, 'pdf_sha256' => hash('sha256', $bytes)])->save();

        return $bytes;
    }

    /** Pré-visualização do estado atual (rascunho incluído), sem guardar nada. */
    public function previewPdf(Quote $quote): string
    {
        return $this->pdf->render(QuoteSnapshot::fromQuote($quote->fresh(['lines', 'customer'])));
    }

    // ── Dashboard root ───────────────────────────────────────────────────────

    /**
     * Valores separados (mensal e único, nunca somados), sem IVA: em aberto, aceites
     * no ano corrente (Lisboa) e aceites desde sempre.
     */
    public function summary(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->setTimezone(Quote::TIMEZONE);
        $sum = fn ($q) => [
            'count'   => (int) (clone $q)->count(),
            'monthly' => round((float) (clone $q)->sum('total_monthly'), 2),
            'one_off' => round((float) (clone $q)->sum('total_one_off'), 2),
        ];
        $yearStart = $now->startOfYear()->utc();
        $yearEnd = $now->endOfYear()->utc();
        $counts = Quote::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'open' => $sum(Quote::where('status', 'sent')) + [
                'expiring_7d' => Quote::where('status', 'sent')->whereNotNull('valid_until')
                    ->whereDate('valid_until', '<=', $now->addDays(7)->toDateString())->count(),
            ],
            'accepted_year' => $sum(Quote::where('status', 'accepted')->whereBetween('decided_at', [$yearStart, $yearEnd])) + ['year' => (int) $now->format('Y')],
            'accepted_all'  => $sum(Quote::where('status', 'accepted')),
            'by_status'     => collect(Quote::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'total'         => (int) $counts->sum(),
        ];
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function notifyCompanyOfNewQuote(Quote $quote): void
    {
        try {
            $quote->loadMissing('company');
            $to = $quote->company?->email;
            if (! $to) {
                return;
            }
            Mail::to($to)->queue(new QuoteCreatedForCompanyMail(
                quoteId: (int) $quote->id,
                number: (string) $quote->number,
                title: (string) ($quote->title ?: $quote->description),
                totalMonthly: (float) $quote->total_monthly,
                totalOneOff: (float) $quote->total_one_off,
                validUntil: $quote->valid_until ? \App\Services\Quotes\QuotePdfPresenter::longDate($quote->valid_until->toDateString()) : '',
            ));
        } catch (\Throwable $e) {
            Log::error('[Quotes] Falha ao enfileirar email de orçamento enviado', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyDecision(Quote $quote, bool $accepted): void
    {
        try {
            $quote->loadMissing('company');
            Mail::to(self::NOTIFY_RECIPIENT)->queue(new QuoteDecisionMail(
                quoteId: (int) $quote->id,
                number: (string) $quote->number,
                companyName: $quote->company?->fiscal_name ?? $quote->client_name ?? '',
                title: (string) ($quote->title ?: $quote->description),
                accepted: $accepted,
                totalMonthly: (float) $quote->total_monthly,
                totalOneOff: (float) $quote->total_one_off,
            ));
        } catch (\Throwable $e) {
            Log::error('[Quotes] Falha ao enfileirar email de decisão', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }
    }
}
