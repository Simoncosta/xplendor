<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // ALTER TABLE ... MODIFY is MariaDB/MySQL syntax; skip on SQLite (test env)
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE car_sale_potential_scores
            MODIFY triggered_by ENUM(
                'scheduled',
                'price_change',
                'promo_price_change',
                'status_change',
                'lead_created',
                'image_added',
                'manual'
            ) NOT NULL
        ");
    }

    public function down(): void {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Antes de remover o valor, verifica se há registos a usá-lo
        $count = DB::table('car_sale_potential_scores')
            ->where('triggered_by', 'promo_price_change')
            ->count();

        if ($count > 0) {
            throw new \Exception(
                "Não é possível remover 'promo_price_change' do ENUM: existem {$count} registos a usar este valor. " .
                "Migrar dados primeiro (ex: UPDATE para 'price_change') antes de fazer rollback."
            );
        }

        DB::statement("
            ALTER TABLE car_sale_potential_scores
            MODIFY triggered_by ENUM(
                'scheduled',
                'price_change',
                'status_change',
                'lead_created',
                'image_added',
                'manual'
            ) NOT NULL
        ");
    }
};
