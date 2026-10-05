<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de serviços da XPLENDOR (tabela padrão dos orçamentos). Gerido só pela
 * equipa. Preços SEM IVA. Unidade: month | project | hour. Cobrança: monthly | one_off.
 * Vem com o catálogo inicial (editável depois); só é inserido se a tabela estiver vazia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('unit_price', 10, 2);
            $table->string('unit', 20);           // month | project | hour
            $table->string('billing_type', 20);   // monthly | one_off
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort']);
        });

        if (DB::table('service_catalog_items')->count() === 0) {
            $now = now();
            DB::table('service_catalog_items')->insert([
                ['name' => 'Social Media', 'description' => '2 publicações por semana.', 'unit_price' => 200, 'unit' => 'month', 'billing_type' => 'monthly', 'active' => true, 'sort' => 10, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Tráfego Pago', 'description' => 'Gestão de campanhas.', 'unit_price' => 200, 'unit' => 'month', 'billing_type' => 'monthly', 'active' => true, 'sort' => 20, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Website', 'description' => null, 'unit_price' => 25, 'unit' => 'hour', 'billing_type' => 'one_off', 'active' => true, 'sort' => 30, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_catalog_items');
    }
};
