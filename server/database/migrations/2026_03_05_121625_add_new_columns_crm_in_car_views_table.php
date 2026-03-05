<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('car_views', function (Blueprint $table) {
            $table->text('referrer')
                ->nullable()
                ->after('id'); // de onde veio

            $table->string('landing_path', 2048)
                ->nullable()
                ->after('referrer'); // página de entrada

            $table->string('channel')
                ->nullable()
                ->index()
                ->after('landing_path'); // classificação do tráfego


            $table->uuid('visitor_id')
                ->nullable()
                ->index()
                ->after('channel'); // quem

            $table->uuid('session_id')
                ->nullable()
                ->index()
                ->after('visitor_id'); // sessão


            $table->string('utm_source')
                ->nullable()
                ->index()
                ->after('session_id'); // campanha

            $table->string('utm_medium')
                ->nullable()
                ->index()
                ->after('utm_source');

            $table->string('utm_campaign')
                ->nullable()
                ->index()
                ->after('utm_medium');

            $table->string('utm_content')
                ->nullable()
                ->after('utm_campaign');

            $table->string('utm_term')
                ->nullable()
                ->after('utm_content');

            $table->index(['company_id', 'car_id', 'created_at']);
            $table->index(['company_id', 'session_id']);
            $table->index(['company_id', 'visitor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_views', function (Blueprint $table) {
            //
        });
    }
};
