<?php

declare(strict_types=1);

namespace App\Services\Ga4;

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Analytics\Data\V1beta\RunReportRequest;
use RuntimeException;

/**
 * XPLENDOR — Implementação REST da GA4 Data API (o único sítio que fala com o
 * SDK do Google). TRANSPORTE REST de propósito: evita a extensão PECL `grpc` no
 * container PHP (não é preciso mexer no Docker).
 *
 * A credencial é a Service Account do SERVIDOR (uma para toda a XPLENDOR),
 * carregada de config/services.ga4 — nunca do repo.
 */
class Ga4RestClient implements Ga4ClientInterface
{
    private ?BetaAnalyticsDataClient $client = null;

    /** Constrói (uma vez) o cliente do SDK em transporte REST. */
    private function client(): BetaAnalyticsDataClient
    {
        if ($this->client) {
            return $this->client;
        }

        $options = ['transport' => 'rest'];

        // Aceita a credencial em qualquer das duas vars, e tolera o engano comum
        // de colar o JSON no GA4_SA_CREDENTIALS (que era "caminho"): se o valor
        // PARECE JSON (começa por '{'), decodifica; senão trata como caminho.
        $raw = config('services.ga4.credentials_json') ?: config('services.ga4.credentials');

        if (empty($raw)) {
            throw new RuntimeException('GA4: credencial da Service Account não configurada (GA4_SA_CREDENTIALS ou GA4_SA_CREDENTIALS_JSON).');
        }

        $trimmed = ltrim((string) $raw);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('GA4: credencial JSON inválida (não é JSON válido).');
            }
            $options['credentials'] = $decoded; // JSON inline (array)
        } else {
            $options['credentials'] = (string) $raw; // caminho para o keyfile JSON
        }

        return $this->client = new BetaAnalyticsDataClient($options);
    }

    public function runReport(int $propertyId, array $spec): array
    {
        $request = (new RunReportRequest())
            ->setProperty("properties/{$propertyId}")
            ->setDateRanges([
                (new DateRange())->setStartDate($spec['start'])->setEndDate($spec['end']),
            ])
            ->setMetrics(array_map(
                fn (string $m) => (new Metric())->setName($m),
                $spec['metrics'] ?? []
            ));

        if (! empty($spec['dimensions'])) {
            $request->setDimensions(array_map(
                fn (string $d) => (new Dimension())->setName($d),
                $spec['dimensions']
            ));
        }

        if (! empty($spec['limit'])) {
            $request->setLimit((int) $spec['limit']);
        }

        if (! empty($spec['orderByMetric'])) {
            $request->setOrderBys([
                (new OrderBy())
                    ->setMetric((new MetricOrderBy())->setMetricName($spec['orderByMetric']))
                    ->setDesc(true),
            ]);
        }

        $response = $this->client()->runReport($request);

        $rows = [];
        foreach ($response->getRows() as $row) {
            $dimensions = [];
            foreach ($row->getDimensionValues() as $dv) {
                $dimensions[] = $dv->getValue();
            }
            $metrics = [];
            foreach ($row->getMetricValues() as $mv) {
                $metrics[] = $mv->getValue();
            }
            $rows[] = ['dimensions' => $dimensions, 'metrics' => $metrics];
        }

        $metadata = $response->getMetadata();

        return [
            'rows' => $rows,
            // GA4 assinala quando escondeu linhas por thresholds de privacidade
            // (demografia com pouco volume) — usamos isto para "sem dados".
            'subjectToThresholding' => $metadata ? (bool) $metadata->getSubjectToThresholding() : false,
        ];
    }
}
