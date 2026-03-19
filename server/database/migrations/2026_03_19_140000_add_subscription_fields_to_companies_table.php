<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('subscription_status')->nullable()->after('plan_id');
            $table->dateTime('trial_starts_at')->nullable()->after('subscription_status');
            $table->dateTime('trial_ends_at')->nullable()->after('trial_starts_at');
            $table->dateTime('subscription_ends_at')->nullable()->after('trial_ends_at');
        });

        DB::table('companies')
            ->whereNull('subscription_status')
            ->update([
                'subscription_status' => 'active',
            ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_status',
                'trial_starts_at',
                'trial_ends_at',
                'subscription_ends_at',
            ]);
        });
    }
};
