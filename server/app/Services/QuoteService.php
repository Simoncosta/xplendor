<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\QuoteCreatedForCompanyMail;
use App\Mail\QuoteDecisionMail;
use App\Models\Company;
use App\Models\Quote;
use App\Repositories\Contracts\QuoteRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Orçamentos avulsos (gestão comercial, super-admin). Molde fino
 * sobre o BaseService/BaseRepository. Sem lógica de tenancy (é transversal; o
 * acesso é garantido pelo portão EnsureSuperAdmin no lado /admin).
 *
 * Fluxo de aprovação alinhado com a "Alteração ao site": o Simon cria (na /admin);
 * quando o orçamento está ligado a uma empresa, ELA aprova/rejeita (no painel
 * dela); o Simon marca pago/concluído. Fatura e pagamento acontecem fora do
 * software. Emails via queue, fail-safe (nunca bloqueiam a operação).
 */
class QuoteService extends BaseService
{
    private const NOTIFY_RECIPIENT = 'simonfrtd@gmail.com';

    public function __construct(protected QuoteRepositoryInterface $quoteRepository)
    {
        parent::__construct($quoteRepository);
    }

    /**
     * Cria o orçamento. Se ligado a uma empresa, o client_name é denormalizado a
     * partir do nome da empresa (para exibição consistente) e a empresa é
     * notificada de que tem um orçamento novo para aprovar.
     */
    public function create(array $data): Quote
    {
        $companyId = $data['company_id'] ?? null;

        if ($companyId) {
            $company = Company::find($companyId);
            // Nome de exibição vem da empresa (o texto livre é ignorado quando há empresa).
            $data['client_name'] = $company?->trade_name ?: $company?->fiscal_name ?: ($data['client_name'] ?? 'Empresa');
        } else {
            $data['company_id'] = null;
        }

        $data['status'] = 'pending';

        /** @var Quote $quote */
        $quote = $this->quoteRepository->store($data);

        if ($quote->company_id) {
            $this->notifyCompanyOfNewQuote($quote);
        }

        return $quote;
    }

    /**
     * EMPRESA — aprova/rejeita o orçamento (só a partir de 'pending'). Avisa o
     * Simon. Só se aplica a orçamentos ligados a uma empresa.
     */
    public function companyDecision(Quote $quote, bool $approved): Quote
    {
        if (! $quote->isLinkedToCompany()) {
            $this->reject('Este orçamento não está ligado a uma empresa.');
        }
        if ($quote->status !== 'pending') {
            $this->reject('Este orçamento já não está em validação.');
        }

        $quote->update(['status' => $approved ? 'approved' : 'rejected']);
        $this->notifyDecision($quote, $approved);

        return $quote->fresh();
    }

    /** ADMIN — marca pago (fora do software). Só a partir de 'approved'. */
    public function markPaid(Quote $quote): Quote
    {
        if ($quote->status !== 'approved') {
            $this->reject('Só se marca pago um orçamento aprovado.');
        }
        $quote->update(['status' => 'paid']);

        return $quote->fresh();
    }

    /** ADMIN — marca concluído. Só a partir de 'paid'. */
    public function markCompleted(Quote $quote): Quote
    {
        if ($quote->status !== 'paid') {
            $this->reject('Só se conclui um orçamento pago.');
        }
        $quote->update(['status' => 'completed']);

        return $quote->fresh();
    }

    /** Erro de transição inválida → 422 (pt-PT). */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['status' => [$message]]);
    }

    private function notifyCompanyOfNewQuote(Quote $quote): void
    {
        try {
            $quote->loadMissing('company');
            $to = $quote->company?->email;
            if (! $to) {
                return; // sem email da empresa não há a quem notificar
            }
            Mail::to($to)->queue(new QuoteCreatedForCompanyMail(
                quoteId: (int) $quote->id,
                description: (string) $quote->description,
                amount: (float) $quote->amount,
            ));
        } catch (\Throwable $e) {
            Log::error('[Quotes] Falha ao enfileirar email de orçamento novo', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyDecision(Quote $quote, bool $approved): void
    {
        try {
            $quote->loadMissing('company');
            Mail::to(self::NOTIFY_RECIPIENT)->queue(new QuoteDecisionMail(
                quoteId: (int) $quote->id,
                companyName: $quote->company?->fiscal_name ?? $quote->client_name ?? '—',
                description: (string) $quote->description,
                approved: $approved,
                amount: (float) $quote->amount,
            ));
        } catch (\Throwable $e) {
            Log::error('[Quotes] Falha ao enfileirar email de decisão', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }
    }
}
