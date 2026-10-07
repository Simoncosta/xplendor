<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão de empresas por agências (F1a). Aditiva, salvo companies.nipc passar a nullable
 * (relaxamento sem perda de dados: uma empresa gerida pode nascer sem NIPC).
 *  · companies.agency_enabled_at: a empresa é uma agência (marcado pelo root).
 *  · company_managements: "a agência X gere a empresa Y". Cada linha é uma relação com o
 *    seu ciclo (pendente, ativa, recusada, retirada, terminada, expirada); mudar de agência
 *    termina uma e cria outra, por isso as linhas são o histórico. active_key = empresa
 *    gerida enquanto ativa (NULL nos outros estados) e é UNIQUE: uma só agência ativa por
 *    empresa, sem índice parcial (o MariaDB não os tem).
 *  · company_management_members: as pessoas da agência atribuídas ao cliente (quando o
 *    âmbito é "assigned"; por omissão, toda a equipa da agência).
 *  · editorial_post_events.acting_company_id: a empresa da pessoa no momento da ação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('agency_enabled_at')->nullable();
            $table->string('agency_notification_email')->nullable();
            $table->string('nipc', 20)->nullable()->change();
        });

        Schema::create('company_managements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('managed_company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('origin', 20); // platform | created_by_agency | request_accepted
            $table->string('status', 12); // pending | active | declined | withdrawn | ended | expired
            $table->unsignedBigInteger('active_key')->nullable()->unique();
            $table->string('team_scope', 10)->default('all'); // all | assigned
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->text('request_message')->nullable();
            $table->timestamp('agency_authorization_declared_at')->nullable();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('request_expires_at')->nullable();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ended_by_side', 10)->nullable(); // company | agency | platform
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 500)->nullable();
            $table->string('data_outcome', 12)->nullable(); // handed_over | archived | exported
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['agency_company_id', 'status']);
            $table->index(['managed_company_id', 'status']);
        });

        Schema::create('company_management_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_id')->constrained('company_managements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->unique(['management_id', 'user_id']);
        });

        Schema::table('editorial_post_events', function (Blueprint $table) {
            $table->foreignId('acting_company_id')->nullable()->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('editorial_post_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acting_company_id');
        });
        Schema::dropIfExists('company_management_members');
        Schema::dropIfExists('company_managements');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['agency_enabled_at', 'agency_notification_email']);
        });
        // companies.nipc fica nullable: voltar a NOT NULL falharia com empresas sem NIPC.
    }
};
