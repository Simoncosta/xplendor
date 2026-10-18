<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsultantSite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Site do consultor: pede ao site (contentor xplendor-consultor, pela rede interna) que atualize
 * as páginas destas etiquetas, em todos os hosts do site. O corpo vai assinado (HMAC-SHA256 com
 * CONSULTOR_REVALIDATE_SECRET, sobre "carimbo.corpo"); o site recusa acima de 5 minutos.
 */
class RevalidateConsultantSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param string[] $tags */
    public function __construct(public int $siteId, public array $tags) {}

    public function handle(): void
    {
        $url = (string) config('consultor_site.revalidate_url');
        $secret = (string) config('consultor_site.revalidate_secret');
        $site = ConsultantSite::with('hosts')->find($this->siteId);
        if ($url === '' || $secret === '' || ! $site || $site->hosts->isEmpty()) {
            return;
        }
        $body = (string) json_encode(['hosts' => $site->hosts->pluck('host')->values()->all(), 'tags' => $this->tags], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $response = Http::timeout(5)->withHeaders([
            'Content-Type' => 'application/json',
            'X-Revalidate-Timestamp' => $timestamp,
            'X-Revalidate-Signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
        ])->withBody($body, 'application/json')->post($url);
        if (! $response->successful()) {
            Log::warning('[Site do consultor] A revalidação falhou', ['site_id' => $this->siteId, 'status' => $response->status()]);
            throw new \RuntimeException('A revalidação do site falhou (HTTP ' . $response->status() . ').'); // nova tentativa ($tries, $backoff)
        }
    }
}
