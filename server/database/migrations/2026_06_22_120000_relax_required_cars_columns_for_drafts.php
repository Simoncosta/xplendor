<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 — "Guardar rascunho sem campos obrigatórios" (1.14.7, 2026-06-22).
 *
 * Torna nullable as 10 colunas que estavam NOT NULL na migration original
 * (`create_cars_table.php`, 2025-12-22) e que bloqueavam a criação de
 * rascunhos com payload mínimo. A validação aplicacional (CarRequest)
 * continua a exigir estes campos em **publicação** (`status != 'draft'`)
 * — o relaxamento na BD é só para permitir o estado-rascunho persistir.
 *
 * As 4 colunas de motor (`fuel_type`, `power_hp`, `engine_capacity_cc`,
 * `transmission`) JÁ eram nullable desde
 * `2026_04_23_112138_adjust_cars_table_for_vehicle_types.php`. Esta migration
 * trata das restantes 10.
 *
 * Categoria: ADITIVA (sec 14.3) — relaxa constraints, não destrói dados.
 * `down()` repõe NOT NULL — apenas seguro quando todas as linhas têm
 * valores não-null (em prod, drafts existentes podem bloquear o rollback;
 * o `down()` é último recurso, não uso normal).
 *
 * Drivers:
 *  - MariaDB/MySQL: `ALTER COLUMN ... MODIFY` via Schema::change().
 *  - SQLite (testes): Laravel 12 recria a tabela em background via
 *    `change()` sem doctrine/dbal.
 */
return new class extends Migration
{
    /** Colunas e re-declarações idênticas à create_cars_table.php, agora `nullable()`. */
    private function applyChanges(bool $nullable): void
    {
        Schema::table('cars', function (Blueprint $table) use ($nullable) {
            // `origin` é enum — em MariaDB requer DB::statement explícito;
            // em SQLite o `change()` é tolerante (enum vira string). Para
            // uniformidade trato à parte abaixo.
            $table->foreignId('car_brand_id')->nullable($nullable)->change();
            $table->foreignId('car_model_id')->nullable($nullable)->change();
            $table->unsignedSmallInteger('registration_year')->nullable($nullable)->change();
            $table->string('version')->nullable($nullable)->change();
            $table->unsignedTinyInteger('doors')->nullable($nullable)->change();
            $table->string('segment')->nullable($nullable)->change();
            $table->unsignedTinyInteger('seats')->nullable($nullable)->change();
            $table->string('exterior_color')->nullable($nullable)->change();
        });

        // `origin` e `condition` são ENUMs. Em MariaDB precisamos de DB::statement;
        // em SQLite o ENUM vira STRING e o `change()` natural funciona com
        // VARCHAR. Trato com fork por driver.
        if (DB::getDriverName() === 'sqlite') {
            // SQLite — `change()` funciona em colunas string-like.
            Schema::table('cars', function (Blueprint $table) use ($nullable) {
                $table->string('origin')->nullable($nullable)->change();
                $table->string('condition')->nullable($nullable)->change();
            });
            return;
        }

        // MariaDB/MySQL — DB::statement explícito para ENUM. `condition` é
        // palavra reservada em SQL → tem de vir entre backticks.
        $nullClause = $nullable ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE cars MODIFY COLUMN `origin` ENUM('national', 'imported') {$nullClause}");
        DB::statement(
            "ALTER TABLE cars MODIFY COLUMN `condition` "
            . "ENUM('new', 'used', 'like_new', 'good', 'service', 'trade_in', 'classic') {$nullClause}"
        );
    }

    public function up(): void
    {
        $this->applyChanges(nullable: true);
    }

    public function down(): void
    {
        // Rollback: tenta repor NOT NULL. Falha se existirem rows com nulls
        // nestas colunas (drafts em prod). Aceitável — o down() é último
        // recurso (sec 14.3 — preferir migrate forward, não rollback).
        $this->applyChanges(nullable: false);
    }
};
