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
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('banner_main')->nullable();
            $table->string('title_about')->nullable();
            $table->text('description_about')->nullable();
            $table->string('address')->nullable();
            $table->string('number')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->enum('show_address', ['ray', 'point', 'hidden'])->default('hidden');
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('country_id')->constrained();
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
