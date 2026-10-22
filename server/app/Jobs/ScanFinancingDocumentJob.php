<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AgencyNoticeMail;
use App\Models\FinancingDocument;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Financing\Antivirus\AntivirusHealth;
use App\Services\Financing\Antivirus\ClamdUnavailable;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Financing\FinancingFiles;
use App\Services\Leads\LeadRecipients;
use App\Support\ExternalCalls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Analisa no antivírus um documento de financiamento acabado de chegar. Limpo: passa a poder
 * descarregar-se. Infetado: o ficheiro é apagado (fica o registo, "recusado" na página do
 * cliente) e o vendedor é avisado no sino e por email.
 *
 * Se o clamd estiver em baixo, a arrancar ou a recarregar as assinaturas, a análise volta para
 * a fila com espera crescente (30 s, 1, 2, 5, 10 e depois de 15 em 15 minutos), durante até 7
 * dias. O ficheiro nunca se perde e, até estar limpo, não se descarrega. Se as tentativas se
 * esgotarem, o antivirus:watch volta a pô-lo na fila quando o antivírus responder.
 */
class ScanFinancingDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Espera (segundos) depois de cada tentativa falhada; a última repete-se. */
    public const BACKOFF = [30, 60, 120, 300, 600, 900];

    /** Respostas inesperadas do clamd (não "em baixo"): até 10 tentativas. */
    public const MAX_UNEXPECTED = 10;

    public int $timeout = 120;

    public function __construct(public int $documentId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function handle(VirusScanner $scanner, AlertService $alerts): void
    {
        $doc = FinancingDocument::with('storedFile', 'link.car', 'link.request.car')->find($this->documentId);
        if (! $doc || $doc->scan_status !== FinancingDocument::SCAN_PENDING || ! $doc->storedFile) {
            return;
        }
        try {
            $result = $scanner->scan($doc->storedFile->disk, $doc->storedFile->path);
        } catch (ClamdUnavailable $e) {
            AntivirusHealth::markDown();
            $delay = self::BACKOFF[min(max($this->attempts() - 1, 0), count(self::BACKOFF) - 1)];
            Log::info('[Antivírus] Indisponível: a análise volta para a fila', ['document_id' => $doc->id, 'tentativa' => $this->attempts(), 'espera_s' => $delay]);
            $this->release($delay);

            return;
        } catch (\RuntimeException $e) {
            // Uma resposta que não é "limpo" nem "infetado" (ex.: o ficheiro excede o limite do
            // clamd): nunca se dá como limpo; tenta mais umas vezes e depois desiste (fica em análise).
            Log::warning('[Antivírus] Resposta inesperada', ['document_id' => $doc->id, 'tentativa' => $this->attempts(), 'error' => mb_substr($e->getMessage(), 0, 200)]);
            if ($this->attempts() >= self::MAX_UNEXPECTED) {
                $this->fail($e);

                return;
            }
            $this->release(self::BACKOFF[min($this->attempts() - 1, count(self::BACKOFF) - 1)]);

            return;
        }
        AntivirusHealth::markUp();
        if ($result === VirusScanner::CLEAN) {
            $doc->forceFill(['scan_status' => FinancingDocument::SCAN_CLEAN, 'scanned_at' => now()])->save();

            return;
        }
        FinancingFiles::deleteFile($doc, keepRow: true);
        $doc->forceFill(['scan_status' => FinancingDocument::SCAN_INFECTED, 'scanned_at' => now()])->save();
        Log::warning('[Financiamento] Documento recusado pelo antivírus', ['document_id' => $doc->id, 'link_id' => $doc->financing_link_id, 'assinatura' => $scanner->lastSignature()]);
        $this->notifySeller($doc, $alerts);
    }

    private function notifySeller(FinancingDocument $doc, AlertService $alerts): void
    {
        $link = $doc->link;
        $request = $link?->request;
        $car = $request?->car ?? $link?->car;
        $title = 'Documento recusado pelo antivírus';
        $lines = ['Um ficheiro enviado para ' . LeadRecipients::carLabel($car) . ' (pedido n.º ' . ($request?->id ?? $doc->financing_link_id) . ') foi recusado pelo antivírus e apagado.',
            'Peça ao cliente que o envie de novo (de preferência uma fotografia ou um PDF tirado diretamente do original).'];
        $path = $request?->isTradeIn() && $request->car_lead_id ? "/leads?id={$request->car_lead_id}" : ($car ? "/cars/{$car->id}/ficha" : '/ficheiros');
        $alerts->createSystemAlert((int) $doc->company_id, 'warning', $title, implode(' ', $lines), 'high', $path, ownOnly: true);
        if (! ExternalCalls::allowed((int) $doc->company_id)) {
            return;
        }
        $creatorId = $request?->created_by_user_id ?? $link?->created_by_user_id;
        $creator = $creatorId ? User::where('id', $creatorId)->where('company_id', $doc->company_id)
            ->whereNull('deactivated_at')->whereNotNull('email')->first() : null;
        $people = $creator ? collect([$creator]) : ($car ? LeadRecipients::sellerOrAdmins($car) : LeadRecipients::admins((int) $doc->company_id));
        $url = rtrim((string) config('app.frontend_url'), '/') . $path;
        foreach ($people as $user) {
            try {
                Mail::to($user->email)->queue(new AgencyNoticeMail($title, $title, $lines, 'Ver o pedido', $url));
            } catch (\Throwable $e) {
                Log::warning('[Financiamento] Falha ao enviar o aviso do antivírus', ['document_id' => $doc->id, 'user_id' => $user->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
    }

    public function failed(?\Throwable $e): void
    {
        // O documento fica "em análise" (não se descarrega); o antivirus:watch volta a tentar.
        Log::warning('[Antivírus] A análise esgotou as tentativas', ['document_id' => $this->documentId, 'error' => $e ? mb_substr($e->getMessage(), 0, 200) : null]);
    }
}
