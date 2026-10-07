<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F1-1 do marketing da restauração: vendas por artigo e por dia (espelho do
 * relatório "Vendas por artigo" do PingWin).
 *  · companies.pingwin_item_sales_enabled: interruptor por empresa, DESLIGADO por
 *    omissão (um deploy não liga a sincronização nova sozinho).
 *  · pingwin_item_sales_daily: loja × dia × artigo. Dinheiro em CÊNTIMOS inteiros.
 *  · pingwin_item_sales_days: estado de cada loja × dia lido (conferência com o
 *    líquido diário do Resumo de Vendas; dias vazios protegidos).
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('pingwin_item_sales_enabled')->default(false)->after('cm_avg_ticket_enabled');
        });

        Schema::create('pingwin_item_sales_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('product_pingwin_id', 32);
            $table->string('product_code', 64)->nullable();
            $table->string('product_name')->nullable();
            $table->string('family_pingwin_id', 32)->nullable();
            $table->string('family_path')->nullable(); // "Família \ Comidas \ Francesinhas"
            $table->decimal('quantity', 12, 3)->default(0);
            // Sem IVA, IVA e com IVA. Com sinal (devoluções podem dar negativo).
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('gross_cents')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Nomes curtos: o MariaDB limita os identificadores a 64 caracteres.
            $table->unique(['location_id', 'business_date', 'product_pingwin_id'], 'pw_item_sales_loc_date_product_unique');
            $table->index(['company_id', 'business_date'], 'pw_item_sales_company_date_idx');
            $table->index(['company_id', 'family_pingwin_id'], 'pw_item_sales_company_family_idx');
        });

        Schema::create('pingwin_item_sales_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            // ok | mismatch | unverified | empty | empty_protected (ver PingwinItemSalesService)
            $table->string('status', 20);
            $table->unsignedInteger('rows_count')->default(0);
            $table->bigInteger('items_net_cents')->default(0);  // soma dos artigos (sem IVA)
            $table->bigInteger('daily_net_cents')->nullable();  // líquido do Resumo de Vendas (null = não sincronizado)
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date'], 'pw_item_days_loc_date_unique');
            $table->index(['company_id', 'status'], 'pw_item_days_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_item_sales_days');
        Schema::dropIfExists('pingwin_item_sales_daily');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('pingwin_item_sales_enabled');
        });
    }
};
