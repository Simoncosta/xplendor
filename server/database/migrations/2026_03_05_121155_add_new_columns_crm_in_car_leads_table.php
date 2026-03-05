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
        Schema::table('car_leads', function (Blueprint $table) {
            $table->enum('status', ['new', 'contacted', 'qualified', 'won', 'lost', 'spam'])
                ->default('new')->index()->after('message');

            $table->timestamp('assigned_at')->nullable()->after('status');

            $table->timestamp('contacted_at')->nullable()
                ->index()->after('assigned_at');

            $table->timestamp('closed_at')->nullable()
                ->index()->after('contacted_at');

            $table->string('lost_reason')->nullable()
                ->index()->after('closed_at');

            $table->text('notes')->nullable()->after('lost_reason');

            $table->enum('source', ['website_form', 'whatsapp', 'phone_call', 'manual', 'api', 'chat'])
                ->default('website_form')->index()->after('notes');

            $table->timestamp('pending_until')->nullable()
                ->index()->after('source');

            $table->text('referrer')->nullable()->after('pending_until');

            $table->string('landing_path', 2048)->nullable()
                ->after('referrer');

            $table->string('channel')->nullable()
                ->index()->after('landing_path');

            $table->string('utm_source')->nullable()
                ->index()->after('channel');

            $table->string('utm_medium')->nullable()
                ->index()->after('utm_source');

            $table->string('utm_campaign')->nullable()
                ->index()->after('utm_medium');

            $table->string('utm_content')->nullable()
                ->after('utm_campaign');

            $table->string('utm_term')->nullable()
                ->after('utm_content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_leads', function (Blueprint $table) {
            //
        });
    }
};
