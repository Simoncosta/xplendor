<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colaboradores (separados dos utilizadores) e departamentos por empresa. Um
 * colaborador pode ter, ou não, acesso à plataforma (user_id). A secção Equipa dos
 * sites só mostra colaboradores ativos, marcados para o site e com autorização de
 * publicação registada (RGPD). Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            // Contactos públicos do departamento (aparecem no site).
            $table->string('whatsapp', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_type', 10)->nullable();   // fixed | mobile
            $table->string('email')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'active', 'sort']);
        });

        Schema::create('collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('company_departments')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('role_title', 120)->nullable();   // função
            $table->text('bio')->nullable();                  // apresentação
            $table->string('photo_path')->nullable();         // 400x400 WebP (nunca o original)
            // Contactos pessoais: só aparecem no site com autorização própria.
            $table->string('whatsapp', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_type', 10)->nullable();     // fixed | mobile
            $table->string('email')->nullable();
            $table->string('contact_mode', 15)->default('department');   // department | personal
            $table->boolean('show_on_site')->default(false);
            $table->timestamp('publish_consent_at')->nullable();
            $table->foreignId('publish_consent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('personal_contact_consent_at')->nullable();
            $table->foreignId('personal_contact_consent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'active', 'show_on_site']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Acesso retirado: não entra, sessões revogadas. Reversível.
            $table->timestamp('deactivated_at')->nullable()->after('accepted_at');
        });

        Schema::table('user_invites', function (Blueprint $table) {
            $table->foreignId('collaborator_id')->nullable()->after('company_id')->constrained('collaborators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_invites', fn (Blueprint $t) => $t->dropConstrainedForeignId('collaborator_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('deactivated_at'));
        Schema::dropIfExists('collaborators');
        Schema::dropIfExists('company_departments');
    }
};
