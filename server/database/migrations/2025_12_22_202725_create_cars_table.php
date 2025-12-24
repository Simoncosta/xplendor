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
        Schema::create('cars', function (Blueprint $table) {
            $table->id();

            // Status
            $table->enum('status', ['draft', 'active', 'inactive', 'sold', 'available_soon'])->default('draft');

            // Origin & identification
            $table->enum('origin', ['national', 'imported']);
            $table->string('license_plate')->nullable()->comment('Matrícula');
            $table->string('vin')->nullable();

            // Registration
            $table->unsignedTinyInteger('registration_month')->nullable();
            $table->unsignedSmallInteger('registration_year');

            // Core vehicle data
            $table->string('brand')->comment('Marca');
            $table->string('model')->comment('Modelo');
            $table->string('version')->comment('Versão');
            $table->string('public_version_name')->nullable()->comment('Versão diferente que aparecerá no site');
            $table->string('fuel_type')->comment('Combustível');
            $table->unsignedSmallInteger('power_hp')->comment('Potência Cv');
            $table->unsignedSmallInteger('engine_capacity_cc')->comment('Cilindrada');
            $table->unsignedTinyInteger('doors')->comment('Porta');
            $table->string('transmission')->comment('Transmissão');

            // Details
            $table->string('segment');
            $table->unsignedTinyInteger('seats')->comment('Lugares');
            $table->string('exterior_color')->comment('Cor exterior');
            $table->boolean('is_metallic')->default(false);
            $table->string('interior_color')->nullable()->comment('Cor interior');
            $table->enum('condition', ['new', 'used', 'like_new', 'good', 'service', 'trade_in', 'classic']);
            $table->unsignedInteger('mileage_km')->nullable();

            // Additional data
            $table->unsignedSmallInteger('co2_emissions')->nullable();
            $table->string('toll_class')->nullable()->comment('Classe portagem');
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->string('warranty_available')->nullable();
            $table->date('warranty_due_date')->nullable();
            $table->unsignedInteger('warranty_km')->nullable();
            $table->string('service_records', 10)->comment('Registos');
            $table->boolean('has_spare_key')->default(false);
            $table->boolean('has_manuals')->default(false);

            // Pricing
            $table->decimal('price_gross', 12, 2)->nullable()->comment('Preço c/ IVA');
            $table->decimal('price_net', 12, 2)->nullable()->comment('Preço s/ IVA');
            $table->boolean('hide_price_online')->default(false);
            $table->decimal('monthly_payment', 10, 2)->nullable();

            // Extras
            $table->json('extras')->nullable();
            $table->json('lifestyle')->nullable();

            // Advertiser content
            $table->text('description_website_pt')->nullable();
            $table->text('description_website_en')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('youtube_url')->nullable();

            $table->foreignId('company_id')->constrained();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
