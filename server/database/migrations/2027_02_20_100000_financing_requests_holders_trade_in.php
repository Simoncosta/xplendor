<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financiamento com dois titulares e a retoma (pedidos da Quebom):
 *  · financing_requests: o pedido (financiamento ou retoma), que junta os links dos titulares,
 *    com a lista de documentos, o ano do IRS, as observações da retoma, as pastas dos Ficheiros
 *    e a retenção (conta do último envio de qualquer titular);
 *  · financing_links passa a ser o link de cada titular (1 ou 2), com a relação do segundo
 *    titular e os dados que o cliente preenche (telemóvel, email, habitação e valor mensal);
 *  · financing_consents guarda o nome completo escrito por quem aceita;
 *  · companies.financing_default_items: a lista por omissão da empresa (vazia = a da plataforma).
 * Os links que já existem passam a ser o titular 1 de um pedido criado aqui. ADITIVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_lead_id')->nullable()->constrained('car_leads')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose', 20)->default('financiamento')->index();
            $table->json('items');
            $table->string('other_description', 120)->nullable();
            $table->unsignedSmallInteger('irs_year')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('folder_id')->nullable()->constrained('file_folders')->nullOnDelete();
            $table->foreignId('photos_folder_id')->nullable()->constrained('file_folders')->nullOnDelete();
            $table->dateTime('last_upload_at')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->dateTime('purge_warned_at')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'car_id']);
            $table->index(['company_id', 'car_lead_id']);
        });

        Schema::table('financing_links', function (Blueprint $table) {
            $table->foreignId('financing_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('holder')->default(1);
            $table->string('relationship', 20)->nullable();
            $table->string('relationship_other', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('housing', 30)->nullable();
            $table->string('housing_other', 120)->nullable();
            $table->decimal('housing_monthly_eur', 10, 2)->nullable();
            $table->dateTime('details_at')->nullable();
        });

        Schema::table('financing_consents', function (Blueprint $table) {
            $table->string('signed_name', 160)->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->json('financing_default_items')->nullable();
        });

        // Os links que já existem: um pedido de financiamento para cada um, com o link como titular 1.
        \App\Services\Financing\FinancingRequestBackfill::run();
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('financing_default_items'));
        Schema::table('financing_consents', fn (Blueprint $t) => $t->dropColumn('signed_name'));
        Schema::table('financing_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financing_request_id');
            $table->dropColumn(['holder', 'relationship', 'relationship_other', 'phone', 'email', 'housing', 'housing_other', 'housing_monthly_eur', 'details_at']);
        });
        Schema::dropIfExists('financing_requests');
    }
};
