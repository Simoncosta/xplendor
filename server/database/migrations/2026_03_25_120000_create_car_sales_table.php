<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('buyer_gender', 20);
            $table->string('buyer_age_range', 20);
            $table->string('sale_channel', 20);
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone', 50)->nullable();
            $table->string('buyer_email')->nullable();
            $table->boolean('contact_consent')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('sold_at');
            $table->timestamps();

            $table->unique('car_id');
            $table->index(['company_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_sales');
    }
};
