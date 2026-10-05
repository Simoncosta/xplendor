<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Condições do orçamento separadas por tipo de cobrança (aditiva): cada uma só
 * aparece quando o orçamento tem linhas desse tipo.
 *   · monthly_start_terms: início dos serviços mensais;
 *   · payment_terms_monthly: pagamento dos serviços mensais;
 *   · payment_terms_one_off: pagamento do valor único.
 * A coluna payment_terms (texto único) deixa de ser usada; o que lá estiver passa
 * para a condição do tipo de linhas que o orçamento tem (mensal tem prioridade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->text('monthly_start_terms')->nullable()->after('payment_terms');
            $table->text('payment_terms_monthly')->nullable()->after('monthly_start_terms');
            $table->text('payment_terms_one_off')->nullable()->after('payment_terms_monthly');
        });

        foreach (DB::table('quotes')->whereNotNull('payment_terms')->get(['id', 'payment_terms']) as $q) {
            $hasMonthly = DB::table('quote_lines')->where('quote_id', $q->id)->where('billing_type', 'monthly')->exists();
            DB::table('quotes')->where('id', $q->id)->update([$hasMonthly ? 'payment_terms_monthly' : 'payment_terms_one_off' => $q->payment_terms]);
        }
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['monthly_start_terms', 'payment_terms_monthly', 'payment_terms_one_off']);
        });
    }
};
