<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão por agências F1d (aditiva):
 *  · management_requests: o pedido de gestão de uma empresa EXISTENTE, feito pela agência
 *    com o NIPC ou o email de um admin. Fica registado mesmo quando não corresponde a
 *    nenhuma empresa (a agência nunca sabe se a empresa existe); managed_company_id só é
 *    conhecido do lado da plataforma e da empresa.
 *  · companies.archived_at / archive_delete_at / archive_warned_at: a empresa sem admin que
 *    perde a agência fica arquivada 90 dias e é apagada no fim.
 *  · company_integrations.connected_by_user_id: quem ligou a integração (para saber se
 *    foi a agência).
 *  · company_managements.connections_decision: a escolha do admin do cliente sobre as
 *    ligações feitas pela agência quando a relação termina (pending, kept, disconnected).
 *  · alerts.own_only: avisos só para as pessoas da própria empresa (nunca para a agência
 *    que a gere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('identifier_type', 5); // nipc | email
            $table->string('identifier', 255);
            $table->text('message')->nullable();
            $table->timestamp('authorization_declared_at')->nullable();
            $table->string('status', 10)->default('pending'); // pending | accepted | declined | withdrawn | expired
            $table->foreignId('managed_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('management_id')->nullable()->constrained('company_managements')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['agency_company_id', 'status']);
            $table->index(['managed_company_id', 'status']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('archive_delete_at')->nullable();
            $table->timestamp('archive_warned_at')->nullable();
        });

        Schema::table('company_integrations', function (Blueprint $table) {
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('company_managements', function (Blueprint $table) {
            $table->string('connections_decision', 12)->nullable();
            $table->timestamp('connections_decided_at')->nullable();
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->boolean('own_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn('own_only'));
        Schema::table('company_managements', fn (Blueprint $t) => $t->dropColumn(['connections_decision', 'connections_decided_at']));
        Schema::table('company_integrations', fn (Blueprint $t) => $t->dropConstrainedForeignId('connected_by_user_id'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['archived_at', 'archive_delete_at', 'archive_warned_at']));
        Schema::dropIfExists('management_requests');
    }
};
