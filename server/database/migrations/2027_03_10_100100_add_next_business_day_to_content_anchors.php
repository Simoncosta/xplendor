<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola 6: os prazos fiscais (IMI, IRS) passam para o dia útil seguinte quando o último dia calha
 * num fim de semana ou feriado. Coluna nova com valor por omissão (as âncoras existentes não mudam).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_anchors', function (Blueprint $table) {
            $table->boolean('next_business_day')->default(false)->after('easter_offset');
        });
    }

    public function down(): void
    {
        Schema::table('content_anchors', function (Blueprint $table) {
            $table->dropColumn('next_business_day');
        });
    }
};
