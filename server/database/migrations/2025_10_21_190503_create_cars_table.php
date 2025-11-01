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
            $table->string('slug');
            $table->enum('status', ['active', 'pending', 'inactive', 'sold', 'sketch', 'available_soon', 'reserved', 'to_order'])->default('sketch');
            $table->boolean('is_imported')->default(false);
            $table->string('licence_plate', 10)->nullable();
            $table->integer('km')->default(0);
            $table->string('vin', 50)->nullable();
            $table->enum('month_registration', ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'])->default('01');
            $table->year('year_registration')->nullable();
            $table->string('mark', 100);
            $table->string('model', 100);
            $table->string('fuel', 50);
            $table->string('power', 50);
            $table->string('number_doors', 3);
            $table->string('gearbox', 50);
            $table->string('version', 100);
            $table->string('segment', 100);
            $table->string('color', 50);
            $table->string('link_youtube')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2)->default(0);
            $table->boolean('show_price')->default(true);
            $table->enum('discount_type', ['amount', 'percentage'])->nullable();
            $table->decimal('discount', 8, 2)->default(0);
            $table->foreignId('company_id')->constrained();
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
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
