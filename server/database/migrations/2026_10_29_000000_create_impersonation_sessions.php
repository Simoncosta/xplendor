<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — IMPERSONATION: auditoria das sessões "root A a agir como user B".
 * O token de impersonation autentica COMO o user B (Auth::user()=B → tenancy uniforme),
 * e ESTA tabela guarda o rasto do root real (quem, quem, empresa, quando, ip). Liga-se ao
 * token Sanctum por token_id (personal_access_tokens.id). ended_at null = sessão ATIVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('root_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable(); // personal_access_tokens.id (sem FK dura — pode ser podado)
            $table->string('reason')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('token_id');
            $table->index('root_id');
            $table->index('target_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
