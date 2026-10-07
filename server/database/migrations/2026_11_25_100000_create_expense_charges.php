<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranças da XPLENDOR (aditiva). A XPLENDOR não emite faturas: recebe a fatura em PDF,
 * mostra-a ao cliente, gere o estado e os lembretes.
 *  · companies.billing_reminders_enabled: lembretes de cobrança (desligados por omissão);
 *    o destino é o "Email de faturação" (companies.invoice_email), que já existe.
 *  · categoria de sistema "XPLENDOR" (system_key 'xplendor') em TODAS as empresas.
 *  · expense_charges: 1 para 1 com a despesa (source 'xplendor'): vencimento, estado,
 *    fatura e comprovativo no disco privado, link seguro (hash e cópia cifrada do token),
 *    lembretes enviados e aberturas (contador do serviço partilhado PublicLinkOpens).
 *  · expense_charge_opens: aberturas reais do link, sem IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('billing_reminders_enabled')->default(false);
        });

        Schema::create('expense_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open'); // open | payment_indicated | paid | cancelled
            $table->date('due_date');
            $table->string('invoice_path');
            $table->string('invoice_name')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('payment_indicated_at')->nullable();
            $table->string('payment_indicated_via', 10)->nullable(); // link | app
            $table->foreignId('payment_indicated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_note', 1000)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_name')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->string('refuse_note', 1000)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('last_reminder_on')->nullable();
            $table->unsignedInteger('reminders_sent')->default(0);
            $table->timestamp('no_recipient_alerted_at')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('link_revoked_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('last_open_alert_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('expense_charge_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_charge_id')->constrained()->cascadeOnDelete();
            $table->char('visitor_hash', 64);
            $table->string('device', 10); // mobile | desktop
            // useCurrent: no MariaDB uma segunda coluna timestamp obrigatória precisa de valor por omissão.
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->index(['expense_charge_id', 'visitor_hash', 'last_seen_at'], 'expense_charge_opens_visit_idx');
        });

        // A categoria "XPLENDOR" em todas as empresas que já existem (as novas: CompanyObserver).
        $now = now();
        $have = DB::table('expense_categories')->where('system_key', 'xplendor')->pluck('company_id')->all();
        DB::table('companies')->whereNotIn('id', $have)->orderBy('id')->pluck('id')->chunk(500)->each(function ($ids) use ($now) {
            DB::table('expense_categories')->insert($ids->map(fn ($id) => [
                'company_id' => $id, 'system_key' => 'xplendor', 'name' => 'XPLENDOR', 'color' => '#405189',
                'archived' => false, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_charge_opens');
        Schema::dropIfExists('expense_charges');
        DB::table('expense_categories')->where('system_key', 'xplendor')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('expenses')->whereColumn('expenses.expense_category_id', 'expense_categories.id'))
            ->delete();
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('billing_reminders_enabled'));
    }
};
