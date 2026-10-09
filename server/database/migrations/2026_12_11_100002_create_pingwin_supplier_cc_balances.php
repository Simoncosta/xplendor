<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1): saldo do PingWin (ccbalance) e estado da
 * sync, um registo por fornecedor. reconciled = (Σ topay_cents × ca_signal == balance_cents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_supplier_cc_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('entity_pingwin_id', 30);
            $table->bigInteger('balance_cents')->nullable();     // ccbalance do PingWin
            $table->boolean('reconciled')->default(false);
            $table->string('sync_status', 10)->default('queued'); // queued|running|ok|failed
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'supplier_id']);
            $table->index(['company_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_supplier_cc_balances');
    }
};
