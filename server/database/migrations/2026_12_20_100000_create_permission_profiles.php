<?php

use App\Access\CompatibilityMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACL, F2 (documents/ACL-DESENHO.md): perfis de permissão.
 *  · permission_profiles: os perfis (company_id nulo = de sistema: os de compatibilidade e as
 *    sugestões); lado cliente, agência ou teto (o que o cliente dá à agência gestora).
 *  · profile_permissions: uma linha por perfil × área × ação (D3: tabela, não JSON).
 *  · users.profile_id: o perfil na própria empresa; users.agency_profile_id: o perfil dentro
 *    dos clientes, para quem trabalha numa agência (D4: um utilizador, uma empresa).
 *  · company_managements.guest_profile_id: o teto da agência gestora nesse cliente.
 *  · permission_profile_events: o registo de cada alteração (quem, quando, o quê).
 * Depois de criar, migra os dados atuais para os perfis de compatibilidade (derivados da
 * fotografia do varrimento), sem mudar nenhum acesso. Só acrescenta: nada é apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('side', 16); // cliente | agencia | teto
            $table->string('system_key', 64)->nullable(); // perfis de sistema (únicos por chave)
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);   // de sistema: não se edita
            $table->boolean('is_suggestion')->default(false); // sugestão para "Novo perfil" (D13)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('system_key');
            $table->index(['company_id', 'side']);
        });

        Schema::create('profile_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('permission_profiles')->cascadeOnDelete();
            $table->string('area', 40);
            $table->string('action', 20);
            $table->unique(['profile_id', 'area', 'action']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->after('role')->constrained('permission_profiles')->nullOnDelete();
            $table->foreignId('agency_profile_id')->nullable()->after('profile_id')->constrained('permission_profiles')->nullOnDelete();
        });

        Schema::table('company_managements', function (Blueprint $table) {
            $table->foreignId('guest_profile_id')->nullable()->after('team_scope')->constrained('permission_profiles')->nullOnDelete();
        });

        Schema::create('permission_profile_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('permission_profiles')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });

        CompatibilityMigration::run();
    }

    public function down(): void
    {
        Schema::table('company_managements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_profile_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_profile_id');
            $table->dropConstrainedForeignId('profile_id');
        });
        Schema::dropIfExists('permission_profile_events');
        Schema::dropIfExists('profile_permissions');
        Schema::dropIfExists('permission_profiles');
    }
};
