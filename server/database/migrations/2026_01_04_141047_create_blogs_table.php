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
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('slug')->comment('para URL amigável')->unique();
            $table->string('banner')->comment('imagem principal')->nullable();
            $table->text('excerpt')->comment('resumo para pré-visualização')->nullable();
            $table->longText('content')->comment('texto completo');
            $table->json('tags')->comment("['SUV', 'Elétrico', 'Mustang']")->nullable();
            $table->string('category')->comment('exemplo: "Análise de Carros"')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedTinyInteger('read_time')->comment('tempo estimado de leitura em minutos')->nullable();
            $table->string('meta_title')->nullable()->comment('Título SEO para Google');
            $table->string('meta_description')->nullable()->comment('Descrição SEO para Google');
            $table->string('og_title')->nullable()->comment('Título para OpenGraph');
            $table->string('og_description')->nullable()->comment('Descrição para OpenGraph');
            $table->string('og_image')->nullable()->comment('Imagem para preview em redes sociais');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
