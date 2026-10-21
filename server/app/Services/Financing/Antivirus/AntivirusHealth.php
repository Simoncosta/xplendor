<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

use App\Models\FinancingDocument;
use App\Jobs\ScanFinancingDocumentJob;
use App\Services\Agency\AgencyNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O estado do antivírus (em cache): desde quando está em baixo e se o root já foi avisado. Se
 * estiver indisponível há mais de 15 minutos, avisa o root (no sino da equipa e por email, sem
 * dados pessoais: só desde quando e quantos documentos estão à espera); quando volta, avisa que
 * voltou e põe na fila as análises que ficaram por fazer.
 */
class AntivirusHealth
{
    public const ALERT_AFTER_MINUTES = 15;
    private const DOWN_SINCE = 'antivirus:down_since';
    private const ALERTED = 'antivirus:down_alerted';

    public function __construct(private readonly VirusScanner $scanner, private readonly AgencyNotifier $notifier) {}

    public static function markDown(): void
    {
        Cache::add(self::DOWN_SINCE, now()->getTimestamp(), now()->addDays(30));
    }

    public static function markUp(): void
    {
        Cache::forget(self::DOWN_SINCE);
    }

    public static function downSince(): ?int
    {
        $ts = Cache::get(self::DOWN_SINCE);

        return $ts ? (int) $ts : null;
    }

    /** A verificação de 5 em 5 minutos (antivirus:watch). @return array{up: bool, alerted: bool, recovered: bool, requeued: int} */
    public function watch(): array
    {
        $out = ['up' => false, 'alerted' => false, 'recovered' => false, 'requeued' => 0];
        if (! $this->scanner->configured()) {
            return $out;
        }
        if (! $this->scanner->ping()) {
            self::markDown();
            $since = self::downSince();
            if ($since && now()->getTimestamp() - $since >= self::ALERT_AFTER_MINUTES * 60 && Cache::add(self::ALERTED, 1, now()->addDays(30))) {
                $waiting = FinancingDocument::where('scan_status', FinancingDocument::SCAN_PENDING)->count();
                Log::warning('[Antivírus] Indisponível há mais de 15 minutos', ['desde' => date('c', $since), 'em_analise' => $waiting]);
                $this->notifier->roots('Antivírus indisponível', [
                    'O antivírus (ClamAV) dos documentos de financiamento não responde desde ' . now()->setTimestamp($since)->timezone('Europe/Lisbon')->format('d/m/Y H:i') . '.',
                    "Documentos à espera de análise: {$waiting}. Nada se perde: ficam em análise e não se descarregam até o antivírus voltar.",
                    'Verifique o contentor xplendor-clamav (docker ps; docker logs xplendor-clamav) e corra php artisan antivirus:check.',
                ], '/admin/espaco');
                $out['alerted'] = true;
            }

            return $out;
        }

        $out['up'] = true;
        $wasDown = self::downSince() !== null;
        self::markUp();
        if ($wasDown && Cache::pull(self::ALERTED)) {
            $this->notifier->roots('Antivírus disponível de novo', ['O antivírus (ClamAV) dos documentos de financiamento voltou a responder. As análises em espera seguem na fila.'], '/admin/espaco');
            $out['recovered'] = true;
        }
        $out['requeued'] = $this->requeue();

        return $out;
    }

    /**
     * As análises que ficaram por fazer: os documentos em análise há mais de 30 minutos (a tarefa
     * pode ter esgotado as tentativas) e os que chegaram sem analisador ("não analisado").
     */
    public function requeue(): int
    {
        $n = 0;
        $docs = FinancingDocument::whereNotNull('stored_file_id')->where(fn ($q) => $q
            ->where('scan_status', FinancingDocument::SCAN_NONE)
            ->orWhere(fn ($p) => $p->where('scan_status', FinancingDocument::SCAN_PENDING)->where('updated_at', '<', now()->subMinutes(30))))
            ->orderBy('id')->limit(200)->get();
        foreach ($docs as $doc) {
            $doc->forceFill(['scan_status' => FinancingDocument::SCAN_PENDING])->save(); // também toca no updated_at
            ScanFinancingDocumentJob::dispatch($doc->id);
            $n++;
        }

        return $n;
    }
}
