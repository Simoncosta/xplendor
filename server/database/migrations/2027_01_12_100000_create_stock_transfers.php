<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 7c: transferências do Economato para os restaurantes.
 *  · stock_transfer_routes: o circuito (armazém de origem → armazém de destino), o documento de saída
 *    (Guia 1102 ou Guia de transporte 1007), as moradas de carga e descarga e a receção automática.
 *  · stock_transfer_requests / stock_transfer_request_lines: os documentos de transferência lidos do PingWin:
 *    os pedidos dos restaurantes (encomendas ENCFB/ENCF; requisições antigas só para consulta) e as Guias e
 *    Entradas (kind guia | entrada, para a auditoria). Cada linha guarda a linha de origem (src_line_pingwin_id).
 *  · stock_transfers / stock_transfer_lines: cada envio (Guia + Entrada) e as linhas, com o preço e o fator.
 *  · pingwin_supplier_document_lines: unit_id, unit_value e qnt_base (o fator da embalagem, para a auditoria).
 * As escritas no PingWin ficam em pingwin_stock_writes (tipos novos: guia_saida, entrada_armazem,
 * encomenda_teste, semente_teste). Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_transfer_routes')) {
            Schema::create('stock_transfer_routes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('origin_warehouse_id', 32);
                $table->string('origin_store_id', 32);
                $table->string('dest_warehouse_id', 32);
                $table->string('dest_store_id', 32);
                $table->string('exit_docconfig_id', 32)->default('1102'); // 1102 Guia de ativos próprios | 1007 Guia de transporte
                $table->boolean('auto_receive')->default(true);
                $table->json('load_address')->nullable();   // {address, postalcode, postalcode_description, city}
                $table->json('unload_address')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['company_id', 'origin_warehouse_id', 'dest_warehouse_id'], 'stock_transfer_routes_unique');
            });
        }

        if (! Schema::hasTable('stock_transfer_requests')) {
            Schema::create('stock_transfer_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('pingwin_doc_id', 32);
                $table->string('docconfig_id', 32);
                $table->string('kind', 20); // encomenda | requisicao (só consulta) | guia | entrada
                $table->string('document', 80)->nullable();
                $table->string('store_id', 32)->nullable();
                $table->string('store_name')->nullable();
                $table->string('entity_id', 32)->nullable();
                $table->string('entity_name')->nullable();
                $table->date('doc_date')->nullable();
                $table->string('docstatus_id', 10)->nullable();
                $table->string('employee_name')->nullable();
                $table->decimal('total', 14, 4)->nullable();
                $table->boolean('converted')->default(false); // o PingWin já tem um documento feito a partir desta encomenda
                $table->foreignId('route_id')->nullable()->constrained('stock_transfer_routes')->nullOnDelete();
                $table->string('lines_status', 20)->nullable(); // ok | erro
                $table->string('lines_error')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'pingwin_doc_id']);
                $table->index(['company_id', 'doc_date']);
            });
        }

        if (! Schema::hasTable('stock_transfer_request_lines')) {
            Schema::create('stock_transfer_request_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_id')->constrained('stock_transfer_requests')->cascadeOnDelete();
                $table->unsignedInteger('line_number')->default(0);
                $table->string('line_pingwin_id', 32)->nullable();
                $table->string('product_id', 32)->nullable();
                $table->string('code', 40)->nullable();
                $table->string('description')->nullable();
                $table->decimal('qnt', 14, 4)->default(0);
                $table->string('unit_id', 32)->nullable();
                $table->string('unit_desc', 80)->nullable();
                $table->decimal('unit_value', 14, 6)->nullable(); // o fator que o PingWin aplicou na linha
                $table->decimal('qnt_base', 14, 4)->nullable();
                $table->decimal('qnt_delivered', 14, 4)->nullable();
                $table->decimal('price', 14, 6)->nullable();
                $table->string('warehouse_id', 32)->nullable();
                $table->string('src_line_pingwin_id', 32)->nullable(); // a linha do documento de origem (Entrada → Guia → Encomenda)
                $table->timestamps();

                $table->index(['request_id', 'product_id']);
                $table->index('line_pingwin_id');
                $table->index('src_line_pingwin_id');
            });
        }

        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('route_id')->nullable()->constrained('stock_transfer_routes')->nullOnDelete();
                $table->foreignId('request_id')->nullable()->constrained('stock_transfer_requests')->nullOnDelete();
                $table->string('origin_warehouse_id', 32);
                $table->string('origin_store_id', 32);
                $table->string('dest_warehouse_id', 32);
                $table->string('dest_store_id', 32);
                $table->string('exit_docconfig_id', 32)->default('1102');
                $table->boolean('auto_receive')->default(true);
                // a_enviar | enviado | recebido | recebido_com_diferenca | falhou_envio | falhou_rececao | anulado
                $table->string('status', 30)->default('a_enviar');
                $table->string('method', 10)->nullable(); // conversao (P2) | nova (P3)
                $table->string('exit_doc_id', 32)->nullable();
                $table->string('exit_doc_number', 60)->nullable();
                $table->string('entry_doc_id', 32)->nullable();
                $table->string('entry_doc_number', 60)->nullable();
                $table->decimal('total_value', 14, 4)->nullable();
                $table->string('note')->nullable();
                $table->text('error_message')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('stock_transfer_lines')) {
            Schema::create('stock_transfer_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
                $table->foreignId('request_line_id')->nullable()->constrained('stock_transfer_request_lines')->nullOnDelete();
                $table->string('product_id', 32);
                $table->string('code', 40)->nullable();
                $table->string('description')->nullable();
                $table->decimal('qnt', 14, 4);           // na unidade da linha
                $table->string('unit_id', 32)->nullable();
                $table->string('unit_desc', 80)->nullable();
                $table->decimal('factor', 14, 6)->default(1); // unidades de stock por unidade da linha (confirmado)
                $table->decimal('qnt_base', 14, 4);      // na unidade de stock
                $table->decimal('origin_pcm', 14, 6)->nullable(); // por unidade de stock
                $table->decimal('last_purchase_price', 14, 6)->nullable();
                $table->decimal('price', 14, 6)->nullable(); // por unidade da linha = PCM × fator
                $table->boolean('price_confirmed')->default(false);
                $table->decimal('stock_before', 14, 4)->nullable();
                $table->decimal('received_qnt_base', 14, 4)->nullable();
                $table->decimal('sent_price', 14, 6)->nullable(); // como ficou na Guia (releitura)
                $table->decimal('received_price', 14, 6)->nullable(); // como ficou na Entrada (releitura)
                $table->timestamps();
            });
        }

        if (Schema::hasTable('pingwin_supplier_document_lines')) {
            Schema::table('pingwin_supplier_document_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('pingwin_supplier_document_lines', 'unit_id')) {
                    $table->string('unit_id', 32)->nullable()->after('unit_desc');
                }
                if (! Schema::hasColumn('pingwin_supplier_document_lines', 'unit_value')) {
                    $table->decimal('unit_value', 14, 6)->nullable()->after('unit_id');
                }
                if (! Schema::hasColumn('pingwin_supplier_document_lines', 'qnt_base')) {
                    $table->decimal('qnt_base', 14, 4)->nullable()->after('unit_value');
                }
            });
            // As linhas já lidas: o fator e a quantidade base vêm no raw (a linha completa do PingWin).
            DB::table('pingwin_supplier_document_lines')->whereNull('unit_value')->whereNotNull('raw')->select(['id', 'raw'])
                ->chunkById(1000, function ($rows) {
                    foreach ($rows as $r) {
                        $raw = json_decode((string) $r->raw, true);
                        if (! is_array($raw) || ! is_numeric($raw['unit_value'] ?? null)) {
                            continue;
                        }
                        DB::table('pingwin_supplier_document_lines')->where('id', $r->id)->update([
                            'unit_id' => isset($raw['unit_id']) ? (string) $raw['unit_id'] : null, 'unit_value' => (float) $raw['unit_value'],
                            'qnt_base' => is_numeric($raw['qnt_base'] ?? null) ? (float) $raw['qnt_base'] : null,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_transfer_request_lines');
        Schema::dropIfExists('stock_transfer_requests');
        Schema::dropIfExists('stock_transfer_routes');
        if (Schema::hasTable('pingwin_supplier_document_lines')) {
            Schema::table('pingwin_supplier_document_lines', function (Blueprint $table) {
                foreach (['qnt_base', 'unit_value', 'unit_id'] as $col) {
                    if (Schema::hasColumn('pingwin_supplier_document_lines', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
