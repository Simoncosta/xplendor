<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS Pós-venda (Incremento 3) — feedback interno (<4 estrelas).
 *
 * `public_message` (já existe) guarda o comentário do ramo ≥4 (copiável para o
 * Google). `internal_feedback` guarda o comentário do ramo <4 (privado, nunca
 * público). `rating`/`submitted_at`/`status` já existem. Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('satisfaction_reports', function (Blueprint $table) {
            $table->text('internal_feedback')->nullable()->after('public_message');
        });
    }

    public function down(): void
    {
        Schema::table('satisfaction_reports', function (Blueprint $table) {
            $table->dropColumn('internal_feedback');
        });
    }
};
