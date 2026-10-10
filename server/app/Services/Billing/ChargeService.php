<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Mail\ChargeClientMail;
use App\Mail\ChargeTeamMail;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCharge;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cobranças da XPLENDOR. A XPLENDOR não emite faturas: recebe a fatura em PDF, mostra-a ao
 * cliente, gere o estado e os lembretes.
 *  · só o root cria, marca como paga, anula, confirma ou recusa o pagamento indicado;
 *  · o cliente (utilizadores da própria empresa, ou o link do email) indica "Já paguei";
 *  · emails ao cliente só com "Enviar lembretes de cobrança" ligado (o root pode sempre
 *    "Enviar agora"); destino: email de faturação, senão os admins, senão aviso ao root;
 *  · lembretes às 09:00 de Lisboa: no dia do vencimento e depois às segundas-feiras, até
 *    estar paga, anulada ou com pagamento indicado; nunca dois emails no mesmo dia.
 */
class ChargeService
{
    /** R2: o disco dos PDFs e comprovativos (config storage_targets.private_disk: "local" ou "r2"). */
    public static function disk(): string
    {
        return (string) config('storage_targets.private_disk', 'local');
    }
    public const TIMEZONE = 'Europe/Lisbon';
    public const INVOICE_MAX_KB = 10240;
    public const PROOF_MAX_KB = 10240;
    public const PROOF_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly AlertService $alerts) {}

    public static function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    // ── Root ─────────────────────────────────────────────────────────────────

    public function create(Company $company, array $data, UploadedFile $invoice, User $root): ExpenseCharge
    {
        $path = $invoice->storeAs('charges/company_' . $company->id, Str::uuid() . '.pdf', self::disk());

        $charge = DB::transaction(function () use ($company, $data, $invoice, $root, $path) {
            $category = ExpenseCategory::ensureXplendor($company->id);
            $expense = new Expense([
                'company_id' => $company->id, 'description' => $data['description'], 'amount' => $data['amount'],
                'date' => $data['invoice_date'] ?? self::today()->toDateString(), 'expense_category_id' => $category->id,
                'is_paid' => false, 'archived' => false,
            ]);
            $expense->forceFill(['source' => Expense::SOURCE_XPLENDOR])->save();

            $charge = new ExpenseCharge([
                'expense_id' => $expense->id, 'company_id' => $company->id, 'status' => ExpenseCharge::OPEN,
                'due_date' => $data['due_date'], 'invoice_path' => $path, 'invoice_name' => mb_substr($invoice->getClientOriginalName(), 0, 190),
                'created_by_user_id' => $root->id,
            ]);
            $charge->issueToken();
            $charge->save();

            return $charge;
        });

        // "Nova fatura": só com os lembretes ligados (o root pode sempre "Enviar agora").
        if ($company->billing_reminders_enabled) {
            $this->sendToClient($charge, 'new');
        }

        return $charge->fresh();
    }

    public function markPaid(ExpenseCharge $charge, User $root): ExpenseCharge
    {
        $this->assertStatus($charge, [ExpenseCharge::OPEN, ExpenseCharge::PAYMENT_INDICATED], 'Só uma cobrança em aberto ou com pagamento indicado pode ser marcada como paga.');
        DB::transaction(function () use ($charge, $root) {
            $charge->update(['status' => ExpenseCharge::PAID, 'paid_at' => now(), 'paid_by_user_id' => $root->id]);
            $charge->expense->forceFill(['is_paid' => true, 'paid_at' => self::today()->toDateString()])->save();
        });
        if ($charge->company->billing_reminders_enabled) {
            $this->sendToClient($charge, 'paid', countsAsReminder: false);
        }

        return $charge->fresh();
    }

    public function cancel(ExpenseCharge $charge, string $reason, User $root): ExpenseCharge
    {
        $this->assertStatus($charge, [ExpenseCharge::OPEN, ExpenseCharge::PAYMENT_INDICATED], 'Uma cobrança paga ou já anulada não pode ser anulada.');
        DB::transaction(function () use ($charge, $reason, $root) {
            $charge->update(['status' => ExpenseCharge::CANCELLED, 'cancel_reason' => mb_substr($reason, 0, 500), 'cancelled_at' => now(),
                'cancelled_by_user_id' => $root->id, 'link_revoked_at' => now()]);
            $charge->expense->forceFill(['archived' => true])->save();
        });

        return $charge->fresh();
    }

    /** Recusa o pagamento indicado: volta a "em aberto" e os lembretes retomam. */
    public function refuse(ExpenseCharge $charge, string $note, User $root): ExpenseCharge
    {
        $this->assertStatus($charge, [ExpenseCharge::PAYMENT_INDICATED], 'Só um pagamento indicado pode ser recusado.');
        $charge->update(['status' => ExpenseCharge::OPEN, 'refused_at' => now(), 'refuse_note' => mb_substr($note, 0, 1000)]);
        if ($charge->company->billing_reminders_enabled) {
            $this->sendToClient($charge, 'refused', countsAsReminder: false);
        }

        return $charge->fresh();
    }

    /** "Enviar agora" (o root): mesmo com os lembretes desligados; conta como o email do dia. */
    public function sendNow(ExpenseCharge $charge): void
    {
        $this->assertStatus($charge, [ExpenseCharge::OPEN, ExpenseCharge::PAYMENT_INDICATED], 'Esta cobrança já não está em aberto.');
        if (! $this->sendToClient($charge, $charge->reminders_sent === 0 ? 'new' : 'reminder')) {
            throw ValidationException::withMessages(['recipients' => ['A empresa não tem email de faturação nem administradores com email.']]);
        }
    }

    // ── Cliente ──────────────────────────────────────────────────────────────

    /** "Já paguei" (pelo link ou na plataforma): os lembretes param e a equipa é avisada. */
    public function indicatePayment(ExpenseCharge $charge, string $via, ?User $user, ?string $note, ?UploadedFile $proof): ExpenseCharge
    {
        $this->assertStatus($charge, [ExpenseCharge::OPEN], 'Esta cobrança já não está em aberto.');
        $proofPath = $proof?->storeAs('charges/company_' . $charge->company_id . '/proofs', Str::uuid() . '.' . ($proof->guessExtension() ?: 'bin'), self::disk());

        $charge->update([
            'status' => ExpenseCharge::PAYMENT_INDICATED, 'payment_indicated_at' => now(), 'payment_indicated_via' => $via,
            'payment_indicated_by_user_id' => $user?->id, 'payment_note' => $note ? mb_substr($note, 0, 1000) : null,
            'proof_path' => $proofPath ?? $charge->proof_path, 'proof_name' => $proof ? mb_substr($proof->getClientOriginalName(), 0, 190) : $charge->proof_name,
            'proof_mime' => $proof?->getMimeType() ?? $charge->proof_mime,
        ]);
        $this->notifyTeam($charge->fresh(['company', 'expense']), $via, $proof !== null, $note);

        return $charge->fresh();
    }

    // ── Lembretes ────────────────────────────────────────────────────────────

    /** Às 09:00 de Lisboa: no dia do vencimento e às segundas-feiras seguintes. Devolve quantos enviou. */
    public function runReminders(?Carbon $today = null): int
    {
        $today ??= self::today();
        $day = $today->toDateString();
        $sent = 0;
        ExpenseCharge::query()->with(['company', 'expense'])
            ->where('status', ExpenseCharge::OPEN)
            ->whereDate('due_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('last_reminder_on')->orWhereDate('last_reminder_on', '<', $day))
            ->whereHas('company', fn ($q) => $q->where('billing_reminders_enabled', true))
            ->orderBy('id')
            ->each(function (ExpenseCharge $charge) use ($today, &$sent) {
                if (self::isReminderDay($charge->due_date, $today) && $this->sendToClient($charge, 'reminder', $today)) {
                    $sent++;
                }
            });

        return $sent;
    }

    /** No dia do vencimento, e depois às segundas-feiras. */
    public static function isReminderDay(Carbon $dueDate, Carbon $today): bool
    {
        $due = $dueDate->toDateString();
        $day = $today->toDateString();

        return $day === $due || ($day > $due && $today->isMonday());
    }

    // ── Destinatários e envio ────────────────────────────────────────────────

    /** Email de faturação; sem ele, os admins ativos da empresa. */
    public static function recipients(Company $company): array
    {
        if ($company->invoice_email) {
            return [$company->invoice_email];
        }

        return User::where('company_id', $company->id)->where('role', 'admin')->whereNull('deactivated_at')
            ->whereNotNull('email')->pluck('email')->unique()->values()->all();
    }

    private function sendToClient(ExpenseCharge $charge, string $kind, ?Carbon $today = null, bool $countsAsReminder = true): bool
    {
        $charge->loadMissing(['company', 'expense']);
        $today ??= self::today();
        $to = self::recipients($charge->company);
        if ($to === []) {
            $this->alertNoRecipient($charge);

            return false;
        }
        Mail::to($to)->queue(new ChargeClientMail(
            $kind, (string) ($charge->company->trade_name ?: $charge->company->fiscal_name), (string) $charge->expense->description,
            (float) $charge->expense->amount, $charge->due_date->format('d/m/Y'), $charge->due_date->toDateString() < $today->toDateString(), $charge->publicUrl(),
            $kind === 'refused' ? $charge->refuse_note : null,
        ));
        if ($countsAsReminder) {
            $charge->forceFill(['last_reminder_on' => $today->toDateString(), 'reminders_sent' => $charge->reminders_sent + 1])->save();
        }

        return true;
    }

    private function alertNoRecipient(ExpenseCharge $charge): void
    {
        if ($charge->no_recipient_alerted_at || ! ($team = self::teamCompanyId())) {
            return;
        }
        $name = $charge->company->trade_name ?: $charge->company->fiscal_name;
        $this->alerts->createSystemAlert($team, 'warning', "Cobrança sem destinatário: {$name}",
            "{$name} não tem email de faturação nem administradores com email. Não foi possível enviar a cobrança \"{$charge->expense->description}\".",
            'high', '/admin/charges');
        $charge->forceFill(['no_recipient_alerted_at' => now()])->save();
    }

    private function notifyTeam(ExpenseCharge $charge, string $via, bool $hasProof, ?string $note): void
    {
        $name = (string) ($charge->company->trade_name ?: $charge->company->fiscal_name);
        $amount = number_format((float) $charge->expense->amount, 2, ',', '.') . ' €';
        if ($team = self::teamCompanyId()) {
            $this->alerts->createSystemAlert($team, 'opportunity', "Pagamento indicado: {$name}",
                "{$name} indicou que pagou \"{$charge->expense->description}\" ({$amount})" . ($hasProof ? ', com comprovativo' : '') . '. Confirme ou recuse em Cobranças.',
                'medium', '/admin/charges');
        }
        $emails = User::where('role', 'root')->whereNull('deactivated_at')->whereNotNull('email')->pluck('email')->unique()->values()->all();
        if ($emails !== []) {
            Mail::to($emails)->queue(new ChargeTeamMail($name, (string) $charge->expense->description, (float) $charge->expense->amount,
                $charge->due_date->format('d/m/Y'), $via, $hasProof, $note, rtrim((string) config('app.frontend_url'), '/') . '/admin/charges'));
        }
    }

    /** A empresa da equipa XPLENDOR (os avisos no sino do root). */
    public static function teamCompanyId(): ?int
    {
        $id = config('quotes.team_company_id') ?: User::where('role', 'root')->whereNotNull('company_id')->value('company_id');

        return $id ? (int) $id : null;
    }

    // ── Ficheiros e apresentação ─────────────────────────────────────────────

    public function invoice(ExpenseCharge $charge): string
    {
        return Storage::disk(self::disk())->get($charge->invoice_path) ?? abort(404, 'Fatura não encontrada.');
    }

    public function proof(ExpenseCharge $charge): string
    {
        abort_unless($charge->proof_path && Storage::disk(self::disk())->exists($charge->proof_path), 404, 'Sem comprovativo.');

        return Storage::disk(self::disk())->get($charge->proof_path);
    }

    /** Para o cliente (na plataforma e no link): sem notas internas nem dados da equipa. */
    public static function presentForClient(ExpenseCharge $charge): array
    {
        $charge->loadMissing(['company', 'expense']);

        return [
            'id' => $charge->id,
            'company' => (string) ($charge->company->trade_name ?: $charge->company->fiscal_name),
            'description' => $charge->expense->description,
            'amount' => (float) $charge->expense->amount,
            'invoice_date' => optional($charge->expense->date)->toDateString(),
            'due_date' => $charge->due_date->toDateString(),
            'status' => $charge->status,
            'overdue' => $charge->isOverdue(),
            'invoice_name' => $charge->invoice_name,
            'payment_indicated_at' => optional($charge->payment_indicated_at)->toIso8601String(),
            'paid_at' => optional($charge->paid_at)->toIso8601String(),
            // Depois de uma recusa (volta a "em aberto"), o cliente vê a nota da XPLENDOR.
            'refuse_note' => $charge->status === ExpenseCharge::OPEN ? $charge->refuse_note : null,
            'can_indicate_payment' => $charge->status === ExpenseCharge::OPEN,
        ];
    }

    /** Para o root: tudo, incluindo o link e as aberturas. */
    public static function presentForAdmin(ExpenseCharge $charge): array
    {
        $charge->loadMissing(['company', 'expense']);

        return self::presentForClient($charge) + [
            'company_id' => $charge->company_id,
            'reminders_enabled' => (bool) $charge->company->billing_reminders_enabled,
            'recipients' => self::recipients($charge->company),
            'cancel_reason' => $charge->cancel_reason, 'cancelled_at' => optional($charge->cancelled_at)->toIso8601String(),
            'payment_indicated_via' => $charge->payment_indicated_via, 'payment_note' => $charge->payment_note,
            'has_proof' => (bool) $charge->proof_path, 'proof_name' => $charge->proof_name,
            'refused_at' => optional($charge->refused_at)->toIso8601String(), 'last_refuse_note' => $charge->refuse_note,
            'last_reminder_on' => optional($charge->last_reminder_on)->toDateString(), 'reminders_sent' => $charge->reminders_sent,
            'open_count' => $charge->open_count, 'last_opened_at' => optional($charge->last_opened_at)->toIso8601String(),
            'link' => $charge->linkIsValid() ? $charge->publicUrl() : null,
            'created_at' => optional($charge->created_at)->toIso8601String(),
        ];
    }

    private function assertStatus(ExpenseCharge $charge, array $allowed, string $message): void
    {
        if (! in_array($charge->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }
}
