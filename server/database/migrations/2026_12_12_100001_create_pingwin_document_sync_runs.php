<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Execuções da sync de documentos de fornecedor (F1): madrugada ou botão manual.
 * Um run por pedido; a UI faz polling do estado (queued|running|ok|failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_document_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('trigger', 10);                 // nightly|manual
            $table->string('status', 10)->default('queued'); // queued|running|ok|failed
            $table->unsignedInteger('docs_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_document_sync_runs');
    }
};
