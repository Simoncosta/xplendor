<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — a auditoria de escritas de unidades passa a cobrir CRIAR, EDITAR e
 * ANULAR (a mesma operação de gravar). `action` distingue-as; `unit_id` diz qual
 * unidade foi editada/anulada (null no criar). Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_unit_creations')) {
            return;
        }
        Schema::table('pingwin_unit_creations', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_unit_creations', 'action')) {
                $table->string('action')->default('create')->after('user_id'); // create|edit|anular
            }
            if (! Schema::hasColumn('pingwin_unit_creations', 'unit_id')) {
                $table->unsignedBigInteger('unit_id')->nullable()->after('action'); // pingwin_units.id (edit/anular)
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pingwin_unit_creations')) {
            Schema::table('pingwin_unit_creations', function (Blueprint $table) {
                foreach (['action', 'unit_id'] as $c) {
                    if (Schema::hasColumn('pingwin_unit_creations', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
