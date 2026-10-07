<?php

namespace App\Jobs;

use App\Services\Ai\AiBlindTestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Um caso do teste às cegas: as respostas dos dois modelos (um job por caso, para não esgotar o tempo). */
class RunAiBlindCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $caseId) {}

    public function handle(AiBlindTestService $service): void
    {
        $service->runCase($this->caseId);
    }

    /** Tempo esgotado ou worker parado: o lado que ficou por gerar conta como erro (o caso não fica pendente para sempre). */
    public function failed(?\Throwable $e): void
    {
        foreach (['a', 'b'] as $s) {
            \Illuminate\Support\Facades\DB::table('ai_blind_cases')->where('id', $this->caseId)->whereNull("{$s}_text")->whereNull("{$s}_error")
                ->update(["{$s}_error" => 'O processamento foi interrompido.', 'updated_at' => now()]);
        }
        $testId = \Illuminate\Support\Facades\DB::table('ai_blind_cases')->where('id', $this->caseId)->value('ai_blind_test_id');
        if ($testId) {
            app(AiBlindTestService::class)->refreshStatus((int) $testId);
        }
    }
}
