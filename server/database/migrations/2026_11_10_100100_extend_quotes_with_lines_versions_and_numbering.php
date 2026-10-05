<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamentos de serviços (aditiva): numeração sequencial por ano, versões
 * congeladas com o PDF, linhas (catálogo ou personalizadas), totais MENSAL e
 * VALOR ÚNICO separados, desconto global, condições, validade e cliente do módulo
 * Clientes. As colunas antigas (client_name, description, amount, status) ficam:
 * amount passa a espelhar o total de valor único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->unique()->after('id');
            $table->unsignedSmallInteger('number_year')->nullable()->after('number');
            $table->unsignedInteger('number_seq')->nullable()->after('number_year');
            $table->unsignedSmallInteger('version')->default(1)->after('number_seq');
            $table->foreignId('customer_id')->nullable()->after('company_id')->constrained('customers')->nullOnDelete();
            $table->string('client_email')->nullable()->after('client_contact');
            $table->string('client_phone', 50)->nullable()->after('client_email');
            $table->string('title')->nullable()->after('client_phone');
            $table->text('intro')->nullable()->after('title');
            $table->string('global_discount_type', 10)->nullable()->after('amount');     // percent | amount
            $table->decimal('global_discount_value', 10, 2)->nullable()->after('global_discount_type');
            $table->string('global_discount_target', 10)->nullable()->after('global_discount_value'); // monthly | one_off (só no valor em €)
            $table->string('global_discount_label')->nullable()->after('global_discount_target');
            $table->decimal('total_monthly', 10, 2)->default(0)->after('global_discount_label');
            $table->decimal('total_one_off', 10, 2)->default(0)->after('total_monthly');
            $table->unsignedTinyInteger('minimum_contract_months')->nullable()->after('total_one_off');
            $table->text('payment_terms')->nullable()->after('minimum_contract_months');
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->date('valid_until')->nullable()->after('sent_at');
            $table->timestamp('decided_at')->nullable()->after('valid_until');
            $table->timestamp('expired_at')->nullable()->after('decided_at');
            $table->string('legacy_status', 20)->nullable()->after('expired_at');
            $table->foreignId('created_by_user_id')->nullable()->after('notes')->constrained('users')->nullOnDelete();

            $table->index(['status', 'valid_until']);
            $table->index('decided_at');
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('catalog_item_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 20);
            $table->string('billing_type', 20);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->string('discount_type', 10)->nullable();   // percent | amount
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('line_total', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });

        Schema::create('quote_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('number', 20);
            $table->json('snapshot');
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['quote_id', 'version']);
        });

        Schema::create('quote_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_number_sequences');
        Schema::dropIfExists('quote_versions');
        Schema::dropIfExists('quote_lines');
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['status', 'valid_until']);
            $table->dropIndex(['decided_at']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropUnique(['number']);
            $table->dropColumn([
                'number', 'number_year', 'number_seq', 'version', 'client_email', 'client_phone', 'title', 'intro',
                'global_discount_type', 'global_discount_value', 'global_discount_target', 'global_discount_label',
                'total_monthly', 'total_one_off', 'minimum_contract_months', 'payment_terms',
                'sent_at', 'valid_until', 'decided_at', 'expired_at', 'legacy_status',
            ]);
        });
    }
};
