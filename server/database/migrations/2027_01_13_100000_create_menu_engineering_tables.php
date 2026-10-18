<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 3: engenharia de menu (só leitura do espelho; nada se escreve no PingWin).
 *  · menu_engineering_snapshots: o cálculo de um período, loja e modo de preço (o ecrã lê daqui).
 *  · menu_engineering_items: uma linha por artigo vendido (ordenável no servidor).
 *  · menu_engineering_exclusions: artigos excluídos à mão, com o motivo (por empresa).
 *  · menu_engineering_settings: os interruptores das exclusões automáticas (por empresa).
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_engineering_snapshots')) {
            Schema::create('menu_engineering_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->date('period_from');
                $table->date('period_to');
                $table->unsignedBigInteger('location_id')->nullable(); // null = a empresa toda
                $table->string('price_mode', 10)->default('media');     // media | ultimo
                $table->string('status', 20)->default('ok');            // ok | a_calcular | falhou
                $table->json('data')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'location_id', 'price_mode', 'calculated_at'], 'menu_eng_snap_lookup');
            });
        }

        if (! Schema::hasTable('menu_engineering_items')) {
            Schema::create('menu_engineering_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('snapshot_id')->constrained('menu_engineering_snapshots')->cascadeOnDelete();
                $table->string('product_id', 32);
                $table->string('code', 40)->nullable();
                $table->string('description')->nullable();
                $table->string('family', 120)->nullable();
                $table->decimal('qty', 14, 3)->default(0);
                $table->bigInteger('revenue_cents')->default(0);   // sem IVA
                $table->bigInteger('tax_cents')->default(0);
                $table->decimal('price', 12, 4)->nullable();        // preço médio sem IVA
                $table->decimal('cost', 12, 4)->nullable();         // por unidade vendida (Parcial: um mínimo)
                $table->decimal('margin', 12, 4)->nullable();       // unitária
                $table->decimal('margin_total', 14, 2)->nullable();
                $table->decimal('food_cost_pct', 8, 2)->nullable();
                $table->decimal('share_pct', 8, 3)->nullable();     // quota da quantidade na família (só incluídos)
                $table->decimal('revenue_share_pct', 8, 3)->nullable();
                $table->string('confidence', 12);                   // confirmado | parcial | sem_custo
                $table->string('class', 20)->nullable();            // estrela | cavalo | quebra_cabecas | cao
                $table->boolean('popular')->nullable();
                $table->boolean('high_margin')->nullable();
                $table->boolean('fragile')->default(false);
                $table->string('excluded', 20)->nullable();         // preco_zero | pouco_vendido | retirado | uber_eats | manual
                $table->unsignedInteger('pareto')->default(0);
                $table->json('detail')->nullable();
                $table->timestamps();

                $table->index(['snapshot_id', 'family']);
                $table->index(['snapshot_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('menu_engineering_exclusions')) {
            Schema::create('menu_engineering_exclusions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_id', 32);
                $table->string('reason', 250);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('menu_engineering_settings')) {
            Schema::create('menu_engineering_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('include_zero_price')->default(false);
                $table->boolean('include_low_volume')->default(false);
                $table->boolean('include_retired')->default(false);
                $table->boolean('include_uber_eats')->default(false);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_engineering_items');
        Schema::dropIfExists('menu_engineering_snapshots');
        Schema::dropIfExists('menu_engineering_exclusions');
        Schema::dropIfExists('menu_engineering_settings');
    }
};
