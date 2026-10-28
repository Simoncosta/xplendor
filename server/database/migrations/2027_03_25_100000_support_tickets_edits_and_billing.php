<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de suporte: quem escreveu, editar e apagar, e a fatura separada do pagamento.
 *
 * - impersonator_user_id (tickets e mensagens): o root que escreveu na sessão como cliente.
 * - Mensagens: edited_at, deleted_at e deleted_by_user_id (a mensagem apagada continua na thread,
 *   com a marca); o texto anterior de uma edição fica em support_ticket_message_versions.
 * - Tickets: soft delete (deleted_at, deleted_by_user_id).
 * - A fatura e o pagamento em colunas próprias (invoiced_at, invoice_by_user_id, paid_at,
 *   paid_by_user_id); o quote_status fica só para a aprovação e o trabalho.
 * - support_ticket_removed_files: as faturas retiradas ou trocadas, apagadas ao fim de 30 dias.
 *
 * Os pedidos que já existem: o paid_at e a fatura preenchem-se a partir da auditoria do ticket
 * (sem auditoria, o updated_at), e os "paid" passam a "approved" com o paid_at, que mostra o
 * mesmo. O down() desfaz tudo: os "approved" com paid_at voltam a "paid".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignId('invoice_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('support_ticket_message_versions', function (Blueprint $table) {
            $table->id();
            // Nome curto: o automático passa os 64 caracteres do MySQL.
            $table->foreignId('support_ticket_message_id')->constrained('support_ticket_messages', 'id', 'st_message_versions_message_fk')->cascadeOnDelete();
            $table->text('body'); // o texto ANTES da edição
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_ticket_removed_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('kind', 20); // invoice
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['purged_at', 'removed_at']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        DB::table('support_tickets')->where('quote_status', 'approved')->whereNotNull('paid_at')->update(['quote_status' => 'paid']);

        Schema::dropIfExists('support_ticket_removed_files');
        Schema::dropIfExists('support_ticket_message_versions');

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_user_id');
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropColumn(['edited_at', 'deleted_at']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_user_id');
            $table->dropConstrainedForeignId('invoice_by_user_id');
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropColumn(['invoiced_at', 'paid_at', 'deleted_at']);
        });
    }

    /** O pagamento e a fatura dos pedidos que já existem, a partir da auditoria do ticket. */
    private function backfill(): void
    {
        $tickets = DB::table('support_tickets')
            ->where(fn ($q) => $q->whereIn('quote_status', ['paid', 'completed'])->orWhereNotNull('invoice_path'))
            ->get(['id', 'quote_status', 'invoice_path', 'updated_at']);
        $hasAudits = Schema::hasTable('audits');
        $userExists = fn ($id) => $id !== null && DB::table('users')->where('id', $id)->exists() ? (int) $id : null;

        foreach ($tickets as $t) {
            $paidAt = $paidBy = $invoicedAt = $invoiceBy = null;
            $audits = $hasAudits
                ? DB::table('audits')->where('auditable_type', \App\Models\SupportTicket::class)->where('auditable_id', $t->id)
                    ->orderBy('id')->get(['user_id', 'new_values', 'created_at'])
                : collect();
            foreach ($audits as $a) {
                $new = json_decode((string) $a->new_values, true) ?: [];
                if (($new['quote_status'] ?? null) === 'paid' && $paidAt === null) {
                    [$paidAt, $paidBy] = [$a->created_at, $a->user_id];
                }
                if (! empty($new['invoice_path'])) {
                    [$invoicedAt, $invoiceBy] = [$a->created_at, $a->user_id]; // a última fatura gravada
                }
            }

            $update = [];
            if (in_array($t->quote_status, ['paid', 'completed'], true)) {
                $update['paid_at'] = $paidAt ?? $t->updated_at;
                $update['paid_by_user_id'] = $userExists($paidBy);
            }
            if ($t->invoice_path) {
                $update['invoiced_at'] = $invoicedAt ?? $paidAt ?? $t->updated_at;
                $update['invoice_by_user_id'] = $userExists($invoiceBy ?? $paidBy);
            }
            if ($t->quote_status === 'paid') {
                $update['quote_status'] = 'approved';
            }
            if ($update !== []) {
                DB::table('support_tickets')->where('id', $t->id)->update($update);
            }
        }
    }
};
