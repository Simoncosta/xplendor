<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\QuoteOpen;
use App\Models\QuoteResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Diário (03:10, hora de Lisboa): retenção dos dados dos links públicos dos orçamentos.
 * Aberturas: 12 meses (os contadores do orçamento mantêm-se). Respostas do cliente
 * (aceitação, recusa, pedido de alterações): 10 anos.
 */
class PruneQuoteActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OPENS_MONTHS = 12;
    public const RESPONSES_YEARS = 10;

    public int $tries = 3;

    public function handle(): void
    {
        $opens = QuoteOpen::where('opened_at', '<', now()->subMonths(self::OPENS_MONTHS))->delete();
        $responses = QuoteResponse::where('created_at', '<', now()->subYears(self::RESPONSES_YEARS))->delete();
        if ($opens > 0 || $responses > 0) {
            Log::info('[Orçamentos] Retenção aplicada', ['opens' => $opens, 'responses' => $responses]);
        }
    }
}
