<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['urgent', 'warning', 'opportunity'])->index();
            $table->string('title');
            $table->text('message');
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium')->index();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamps();

            $table->index(['company_id', 'is_read', 'created_at'], 'alerts_company_read_created_idx');
            $table->index(['company_id', 'car_id', 'type', 'created_at'], 'alerts_company_car_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
