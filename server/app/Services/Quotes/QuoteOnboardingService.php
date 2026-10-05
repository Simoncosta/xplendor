<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Models\Company;
use App\Models\Quote;
use App\Models\ServiceCatalogItem;
use App\Models\SupportTicket;
use App\Models\SupportTicketTask;
use App\Models\User;
use App\Services\AlertService;
use App\Services\SupportTicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * O que acontece quando um orçamento é aceite (pelo link público, pelo painel da empresa
 * ligada ou registado pela equipa) e os avisos à equipa no sino.
 *
 *  · Aviso no sino da empresa da equipa (XPLENDOR_COMPANY_ID ou a empresa do root).
 *  · Ticket de arranque "Arranque: [Cliente], [serviços aceites]" com a lista de tarefas
 *    de cada serviço aceite (copiada do catálogo). Fica na empresa ligada, se houver;
 *    senão, na empresa da equipa. Um só por orçamento.
 * Nunca falha a aceitação: um erro aqui fica registado e a aceitação mantém-se.
 */
class QuoteOnboardingService
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly SupportTicketService $tickets,
    ) {}

    /** Empresa da equipa XPLENDOR (onde está o sino da equipa). */
    public static function teamCompanyId(Quote $quote): ?int
    {
        $configured = (int) config('quotes.team_company_id');
        if ($configured > 0 && Company::whereKey($configured)->exists()) {
            return $configured;
        }
        $creatorCompany = $quote->created_by_user_id ? User::whereKey($quote->created_by_user_id)->value('company_id') : null;
        if ($creatorCompany) {
            return (int) $creatorCompany;
        }
        $rootCompany = User::where('role', 'root')->whereNotNull('company_id')->orderBy('id')->value('company_id');

        return $rootCompany ? (int) $rootCompany : null;
    }

    /** Aviso no sino da equipa, a apontar para o orçamento. */
    public function alertTeam(Quote $quote, string $type, string $title, string $message, string $severity = 'medium'): void
    {
        try {
            $companyId = self::teamCompanyId($quote);
            if ($companyId) {
                $this->alerts->createSystemAlert($companyId, $type, $title, $message, $severity, "/admin/quotes/{$quote->id}");
            }
        } catch (\Throwable $e) {
            Log::error('[Orçamentos] Falha ao criar o aviso da equipa', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Aceitação: aviso à equipa (destacado se veio depois de um pedido de alterações) e
     * ticket de arranque com as linhas aceites.
     *
     * @param  array<int, array{name: string, catalog_item_id?: ?int}>  $acceptedLines
     */
    public function onAccepted(Quote $quote, array $acceptedLines, array $totals, bool $afterChangesRequest, int $totalLines): void
    {
        $number = $quote->displayNumber();
        $count = count($acceptedLines);
        $scope = $count === $totalLines ? 'todos os serviços' : "{$count} de {$totalLines} serviços";
        $values = 'Mensal ' . QuotePdfPresenter::money((float) $totals['monthly']) . '; valor único ' . QuotePdfPresenter::money((float) $totals['one_off']) . '.';

        if ($afterChangesRequest) {
            $this->alertTeam($quote, 'urgent', "Aceite depois de um pedido de alterações: {$number}",
                "{$quote->client_name} aceitou o orçamento depois de ter pedido alterações ({$scope}). Confirme se as alterações pedidas ainda são precisas. {$values}", 'high');
        } else {
            $this->alertTeam($quote, 'opportunity', "Orçamento aceite: {$number}", "{$quote->client_name} aceitou {$scope}. {$values}", 'high');
        }

        $this->start($quote, $acceptedLines);
    }

    /**
     * Ticket de arranque com a lista de tarefas de cada serviço aceite. Idempotente: se o
     * orçamento já tem ticket de arranque, devolve-o.
     */
    public function start(Quote $quote, array $acceptedLines): ?SupportTicket
    {
        try {
            return DB::transaction(function () use ($quote, $acceptedLines) {
                $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
                if ($quote->onboarding_ticket_id) {
                    return SupportTicket::find($quote->onboarding_ticket_id);
                }
                $companyId = $quote->company_id ?: self::teamCompanyId($quote);
                $userId = $quote->created_by_user_id ?: User::where('role', 'root')->orderBy('id')->value('id');
                if (! $companyId || ! $userId || $acceptedLines === []) {
                    Log::warning('[Orçamentos] Arranque sem empresa, autor ou linhas', ['quote_id' => $quote->id]);

                    return null;
                }

                $names = array_map(fn ($l) => (string) $l['name'], $acceptedLines);
                $ticket = $this->tickets->createTicket($companyId, (int) $userId, [
                    'type' => SupportTicket::TYPE_ONBOARDING,
                    'title' => mb_substr("Arranque: {$quote->client_name}, " . implode(', ', $names), 0, 255),
                    'description' => mb_substr($this->description($quote, $acceptedLines), 0, 5000),
                ], null);

                $catalog = ServiceCatalogItem::whereIn('id', array_filter(array_map(fn ($l) => $l['catalog_item_id'] ?? null, $acceptedLines)))
                    ->get()->keyBy('id');
                $position = 0;
                foreach ($acceptedLines as $line) {
                    $item = isset($line['catalog_item_id']) ? $catalog->get($line['catalog_item_id']) : null;
                    $tasks = array_values(array_filter((array) ($item?->onboarding_checklist ?? []), fn ($t) => is_string($t) && trim($t) !== ''));
                    if ($tasks === []) {
                        $tasks = ['Arrancar o serviço ' . $line['name']];
                    }
                    foreach ($tasks as $title) {
                        SupportTicketTask::create([
                            'support_ticket_id' => $ticket->id,
                            'position' => $position++,
                            'group_label' => mb_substr((string) $line['name'], 0, 255),
                            'title' => mb_substr(trim($title), 0, 255),
                            'catalog_item_id' => $item?->id,
                        ]);
                    }
                }
                $quote->forceFill(['onboarding_ticket_id' => $ticket->id])->save();

                return $ticket;
            });
        } catch (\Throwable $e) {
            Log::error('[Orçamentos] Falha ao criar o ticket de arranque', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function description(Quote $quote, array $acceptedLines): string
    {
        $lines = array_map(function ($l) {
            $total = isset($l['line_total']) ? ' (' . QuotePdfPresenter::money((float) $l['line_total']) . (($l['billing_type'] ?? '') === 'monthly' ? '/mês' : '') . ')' : '';

            return '• ' . $l['name'] . $total;
        }, $acceptedLines);

        return implode("\n", array_filter([
            "Orçamento {$quote->displayNumber()} (versão {$quote->version}) aceite por {$quote->client_name}.",
            $quote->company_id ? null : 'Cliente sem empresa na plataforma: o arranque é acompanhado pela equipa.',
            '',
            'Serviços aceites:',
            ...$lines,
            '',
            'A lista de tarefas de cada serviço está neste ticket.',
        ], fn ($v) => $v !== null));
    }
}
