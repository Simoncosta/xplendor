<?php

use App\Support\SaleAttributionUniqueness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Uma atribuição por venda: chave única (company_id, car_id) em vez de
 * (company_id, car_id, sold_at). Só se cria sem duplicados. Com duplicados NÃO
 * falha o deploy: regista o aviso e a chave fica para depois de
 *   php artisan sales:dedupe-attributions          (simulação, relatório)
 *   php artisan sales:dedupe-attributions --write  (limpa e cria a chave)
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = SaleAttributionUniqueness::ensure();

        if ($result === 'duplicates') {
            $msg = 'car_sale_attributions: há atribuições duplicadas; a chave única por viatura fica para '
                . 'php artisan sales:dedupe-attributions --write (correr primeiro sem --write para ver o relatório).';
            Log::warning($msg);
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                fwrite(STDERR, "  ⚠ {$msg}\n");
            }
        }
    }

    public function down(): void
    {
        // Sem down destrutivo: a chave antiga (com sold_at) permitia os duplicados.
    }
};
