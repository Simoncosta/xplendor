<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * IPS "a calibrar": quando não há sinais suficientes (sem views e sem
     * dados de mercado), o score fica NULL e a classification 'pending'.
     * Requer score nullable + 'pending' no enum classification.
     */
    public function up(): void
    {
        Schema::table('car_sale_potential_scores', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->change();
            $table->enum('classification', ['hot', 'warm', 'cold', 'pending'])->change();
        });
    }

    public function down(): void
    {
        // Não reverter se houver registos 'pending' / score NULL — perderia dados.
        $pending = DB::table('car_sale_potential_scores')
            ->where('classification', 'pending')
            ->orWhereNull('score')
            ->count();

        if ($pending > 0) {
            throw new \RuntimeException(
                "Não é possível reverter: existem {$pending} registos com score NULL / classification 'pending'. " .
                "Migrar esses registos primeiro (ex: recalcular ou apagar) antes do rollback."
            );
        }

        Schema::table('car_sale_potential_scores', function (Blueprint $table) {
            $table->enum('classification', ['hot', 'warm', 'cold'])->change();
            $table->unsignedTinyInteger('score')->nullable(false)->change();
        });
    }
};
