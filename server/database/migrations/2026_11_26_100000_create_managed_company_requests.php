<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vista da agência (F1b), aditiva.
 *  · managed_company_requests: a agência NÃO cria empresas; o admin da agência pede "Nova
 *    empresa gerida" e o root aprova (a empresa nasce gerida pela agência, origin
 *    'created_by_agency') ou recusa com motivo. decided_at de uma aprovação é a data de
 *    início da gestão (base da futura cobrança por conta gerida).
 *  · índice editorial_posts(company_id, publish_date): as vistas de várias empresas filtram
 *    por empresa e por mês.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_company_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->foreignId('content_sector_id')->nullable()->constrained('content_sectors')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('authorization_declared_at')->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | declined
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 1000)->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('management_id')->nullable()->constrained('company_managements')->nullOnDelete();
            $table->timestamps();

            $table->index(['agency_company_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->index(['company_id', 'publish_date'], 'ed_posts_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_posts', fn (Blueprint $table) => $table->dropIndex('ed_posts_company_date_idx'));
        Schema::dropIfExists('managed_company_requests');
    }
};
