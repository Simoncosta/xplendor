<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS — Caminho B (Metade 1): modelos de documento .docx por empresa.
 *
 * O stand carrega um .docx com {{ variáveis }} → guarda-se como modelo → gera
 * documentos preenchidos por venda. O ficheiro fica em storage isolado
 * (company_X/document_templates/). Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('original_path');           // caminho do .docx no disco 'local'
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
