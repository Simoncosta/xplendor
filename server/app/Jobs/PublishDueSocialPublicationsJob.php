<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Social\Publishing\AutoPublisher;
use App\Support\ExternalCalls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * F2.2 · De minuto a minuto: publica as publicações automáticas cuja hora chegou, nas duas redes,
 * com um só mecanismo (sem o agendamento nativo do Facebook). Com o driver "disabled" (produção,
 * até haver o cliente real) não faz nada; com um cliente real (F2.4 em diante) segue a guarda das
 * chamadas externas. O simulado ("fake") é interno: corre também no dev.
 */
class PublishDueSocialPublicationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(AutoPublisher $publisher): array
    {
        $driver = (string) config('social_publishing.driver');
        if ($driver === 'disabled' || ($driver !== 'fake' && ! ExternalCalls::allowed())) {
            return ['skipped' => true];
        }

        $result = $publisher->runDue();
        if (array_sum($result) > 0) {
            Log::info('[Publicação automática] Publicador', $result + ['driver' => $driver]);
        }

        return $result;
    }
}
