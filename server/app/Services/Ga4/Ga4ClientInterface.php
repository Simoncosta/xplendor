<?php

declare(strict_types=1);

namespace App\Services\Ga4;

/**
 * XPLENDOR — Contrato fino sobre a GA4 Data API. Isola o SDK do Google numa só
 * classe (Ga4RestClient), para que o GoogleAnalyticsService (a lógica de
 * relatórios/normalização/demografia) seja testável sem tocar na rede.
 */
interface Ga4ClientInterface
{
    /**
     * Corre um runReport na propriedade GA4 e devolve linhas normalizadas.
     *
     * @param  int    $propertyId  ID numérico da propriedade GA4 (ex.: 398765432).
     * @param  array  $spec {
     *     start:      string  (YYYY-MM-DD),
     *     end:        string  (YYYY-MM-DD),
     *     metrics:    string[] (ex.: ['activeUsers','sessions']),
     *     dimensions: string[] (ex.: ['pagePath']),  // opcional
     *     limit:      int,                            // opcional
     *     orderByMetric: string,                      // opcional (ordena desc)
     * }
     * @return array{rows: array<int, array{dimensions: string[], metrics: string[]}>, subjectToThresholding: bool}
     */
    public function runReport(int $propertyId, array $spec): array;
}
