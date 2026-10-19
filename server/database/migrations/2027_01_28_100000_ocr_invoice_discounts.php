<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturas: os descontos de promoção ligados ao artigo (ex.: "Leve Mais Pague Menos" da Makro).
 *  · na linha: o código da promoção (DD) e o código de IVA lidos da fatura, o valor antes do
 *    desconto, o desconto em € e o seu código. line_total_cents continua a ser o líquido;
 *  · ocr_invoice_discounts: o bloco de descontos lido (código, valor, código de IVA) e a quem ficou;
 *  · na fatura: o quadro do IVA lido (código → taxa, base, IVA), as linhas informativas ignoradas,
 *    a leitura original (para "Reler do documento" saber o que foi mudado à mão) e quem fechou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $t) {
            $t->string('promo_code', 40)->nullable()->after('vat_rate');
            $t->string('vat_code', 10)->nullable()->after('promo_code');
            $t->bigInteger('gross_total_cents')->nullable()->after('vat_code');
            $t->bigInteger('discount_cents')->default(0)->after('gross_total_cents');
            $t->string('discount_code', 40)->nullable()->after('discount_cents');
            $t->string('discount_label', 120)->nullable()->after('discount_code');
        });

        Schema::create('ocr_invoice_discounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ocr_invoice_id')->constrained('ocr_invoices')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('position')->default(0);
            $t->string('code', 40)->nullable();
            $t->string('label', 120)->nullable();
            $t->bigInteger('amount_cents')->default(0);
            $t->string('vat_code', 10)->nullable();
            $t->unsignedTinyInteger('vat_rate')->nullable();
            $t->string('status', 20)->default('por_atribuir'); // atribuido | por_atribuir
            $t->json('allocations')->nullable();                // [{line_id, cents}]
            $t->string('warning', 300)->nullable();
            $t->timestamps();
            $t->index(['ocr_invoice_id', 'position']);
        });

        Schema::table('ocr_invoices', function (Blueprint $t) {
            $t->json('vat_table')->nullable()->after('check_diff');
            $t->json('ignored_lines')->nullable()->after('vat_table');
            $t->json('read_snapshot')->nullable()->after('ignored_lines');
            $t->json('reread_keep')->nullable()->after('read_snapshot');
            $t->foreignId('closed_by')->nullable()->after('check_accepted_at')->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('closed_by');
            $t->dropColumn(['vat_table', 'ignored_lines', 'read_snapshot', 'reread_keep', 'closed_at']);
        });
        Schema::dropIfExists('ocr_invoice_discounts');
        Schema::table('ocr_invoice_lines', function (Blueprint $t) {
            $t->dropColumn(['promo_code', 'vat_code', 'gross_total_cents', 'discount_cents', 'discount_code', 'discount_label']);
        });
    }
};
