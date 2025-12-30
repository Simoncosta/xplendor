<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            $table->string('nipc', 20);
            $table->string('fiscal_name'); // Designação Fiscal
            $table->string('slug')->nullable()->unique();
            $table->string('trade_name')->nullable(); // Nome Comercial
            $table->string('responsible_name')->nullable(); // Nome Responsável

            $table->string('address')->nullable(); // Rua
            $table->string('postal_code')->nullable(); // Código Postal (3020-405)
            $table->foreignId('district_id')->nullable()->constrained();
            $table->foreignId('municipality_id')->nullable()->constrained();
            $table->foreignId('parish_id')->nullable()->constrained();

            $table->string('phone')->nullable(); // Telefone
            $table->string('mobile')->nullable(); // Telemóvel
            $table->string('email')->nullable(); // Email principal
            $table->string('invoice_email')->nullable(); // Email para faturação

            $table->string('registry_office')->nullable(); // Conservatória
            $table->string('registry_office_number')->nullable(); // Nº de Conservatória
            $table->string('capital_social')->nullable(); // Capital Social
            $table->string('nib')->nullable(); // NIB
            $table->integer('registration_fees')->default(0); // Despesa de Registo Automóvel
            $table->boolean('export_promotion_price')->default(false); // Exportar Preço de Promoção?
            $table->string('credit_intermediation_link')->nullable(); // Link Banco de Portugal
            $table->integer('vat_value')->nullable(); // Valor IVA

            $table->string('facebook_page_id')->nullable(); // ID da Página do Facebook
            $table->string('facebook_pixel_id')->nullable(); // Facebook Pixel ID
            $table->string('facebook_access_token')->nullable(); // Token de Acesso Facebook
            $table->string('website')->nullable(); // Website
            $table->string('instagram')->nullable(); // Instagram
            $table->string('youtube')->nullable(); // Youtube
            $table->string('facebook')->nullable(); // Facebook
            $table->string('google')->nullable(); // Google

            $table->string('lead_hours_pending')->nullable(); // Nº de horas que a lead fica pendente
            $table->enum('lead_distribution', ['manual', 'automatic_latest', 'automatic_less'])->default('manual'); // Distribuição de Leads

            $table->text('ad_text')->nullable(); // Texto genérico para anúncios

            $table->string('pdf_path')->nullable(); // Caminho do PDF
            $table->string('logo_path')->nullable(); // Caminho do logotipo principal
            $table->string('banner_path', 20)->nullable(); // Caminho do banner principal
            $table->string('carmine_logo_path')->nullable(); // Caminho do logotipo Carmine

            // Token public
            $table->string('public_api_token')->unique()->nullable();

            $table->foreignId('plan_id')->constrained();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
