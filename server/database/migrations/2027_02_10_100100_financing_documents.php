<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envio seguro de documentos para financiamento: o link privado que o vendedor gera para um
 * cliente (financing_links), os ficheiros recebidos (financing_documents, ligados ao catálogo dos
 * Ficheiros) e os consentimentos aceites na página (financing_consents: o aviso de proteção de
 * dados e a cópia do Cartão de Cidadão, com o texto e a data; sem IP).
 *
 * O código do link guarda-se em hash (sha256) e numa cópia cifrada (para o vendedor o voltar a
 * copiar), como nos links de aprovação. companies.financing_retention_days: os dias até ao
 * apagamento automático, contados do último envio. ADITIVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('financing_retention_days')->default(90);
        });

        Schema::create('financing_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_lead_id')->nullable()->constrained('car_leads')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name', 120)->nullable();
            $table->json('requested');
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            // dateTime (não timestamp): no MariaDB, o primeiro timestamp NOT NULL da tabela ganha
            // "ON UPDATE CURRENT_TIMESTAMP" e o link expirava em cada alteração.
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('max_files');
            $table->unsignedBigInteger('max_bytes');
            $table->foreignId('folder_id')->nullable()->constrained('file_folders')->nullOnDelete();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('last_upload_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('purge_warned_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'car_id']);
            $table->index(['company_id', 'car_lead_id']);
        });

        Schema::create('financing_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financing_link_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->foreignId('stored_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('name', 200);
            $table->string('extension', 10);
            $table->string('mime', 80);
            $table->unsignedBigInteger('size_bytes');
            $table->string('scan_status', 20)->default('nao_analisado');
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
            $table->index(['financing_link_id', 'kind']);
        });

        Schema::create('financing_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financing_link_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('text');
            $table->string('version', 20);
            $table->dateTime('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financing_consents');
        Schema::dropIfExists('financing_documents');
        Schema::dropIfExists('financing_links');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('financing_retention_days');
        });
    }
};
