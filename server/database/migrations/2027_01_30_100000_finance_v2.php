<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finanças v2 (só na XPLENDOR; nada se escreve no PingWin):
 *  · finance_rules (+ finance_rule_events): as regras de classificação "quando → grupo", por
 *    entidade, por artigo, por família ou por tipo de documento, com prioridade sobre a família do
 *    PingWin, e o histórico de cada mudança;
 *  · finance_payments: os pagamentos registados na XPLENDOR enquanto não há nota de liquidação no
 *    PingWin (desfeitos ficam no histórico; a nota do PingWin ganha quando aparece);
 *  · finance_document_marks: por documento, a "Situação" (forma de pagamento da folha), o envio à
 *    contabilidade (data e forma) e a nota.
 * Dados: a regra dos artigos 100833 a 100835 da entidade "FOLHA DE OBRIGAÇÕES" → Pessoal, nas
 * empresas que têm essa entidade nos documentos de fornecedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('match_type', 20);                   // entidade | artigo | familia | tipo_documento
            $t->json('match_values');                       // códigos, ids ou caminhos
            $t->string('match_label', 255)->nullable();     // o que se mostra ("Artigos 100833 a 100835")
            $t->string('entity_pingwin_id', 32)->nullable(); // só desta entidade (opcional)
            $t->string('entity_name', 160)->nullable();
            $t->string('group', 20);
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('priority')->default(100);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'active']);
        });
        Schema::create('finance_rule_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('rule_id');
            $t->string('action', 20);                       // criar | editar | ativar | desativar | apagar
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reason', 255)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['company_id', 'rule_id']);
        });

        Schema::create('finance_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('batch', 36)->nullable();            // "Regularizar": um só pagamento para vários
            $t->string('docheader_id', 32);
            $t->string('entity_pingwin_id', 32)->nullable();
            $t->bigInteger('amount_cents');
            $t->date('paid_on');
            $t->string('method', 20);                       // transferencia | cartao_debito | cartao_credito | numerario | debito_direto | outro
            $t->string('reference', 120)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('undone_at')->nullable();
            $t->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'docheader_id']);
        });

        Schema::create('finance_document_marks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('docheader_id', 32);
            $t->string('situation', 60)->nullable();        // a "Situação" da folha (TF, Cartão de débito, Recibo…)
            $t->date('sent_on')->nullable();
            $t->string('send_method', 30)->nullable();      // email | entregue | plataforma | outro
            $t->string('note', 500)->nullable();
            $t->string('source', 20)->nullable();           // manual | importado | email
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'docheader_id']);
        });

        // A regra já criada: os artigos 100833 a 100835 da folha de obrigações → Pessoal.
        $now = now();
        $entities = DB::table('pingwin_supplier_documents')->where('entity_name', 'like', 'FOLHA DE OBRIGA%')
            ->whereNotNull('entity_pingwin_id')->select('company_id', 'entity_pingwin_id', DB::raw('MAX(entity_name) as name'))
            ->groupBy('company_id', 'entity_pingwin_id')->get();
        foreach ($entities as $e) {
            $id = DB::table('finance_rules')->insertGetId([
                'company_id' => $e->company_id, 'match_type' => 'artigo', 'match_values' => json_encode(['100833', '100834', '100835']),
                'match_label' => 'Artigos 100833 a 100835', 'entity_pingwin_id' => $e->entity_pingwin_id, 'entity_name' => $e->name,
                'group' => 'pessoal', 'active' => true, 'priority' => 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('finance_rule_events')->insert([
                'company_id' => $e->company_id, 'rule_id' => $id, 'action' => 'criar', 'before' => null,
                'after' => json_encode(['match_type' => 'artigo', 'match_values' => ['100833', '100834', '100835'], 'group' => 'pessoal', 'active' => true]),
                'user_id' => null, 'reason' => 'Migração: a folha de obrigações (Segurança Social e retenções) é Pessoal; a família no PingWin fica como está.',
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_document_marks');
        Schema::dropIfExists('finance_payments');
        Schema::dropIfExists('finance_rule_events');
        Schema::dropIfExists('finance_rules');
    }
};
