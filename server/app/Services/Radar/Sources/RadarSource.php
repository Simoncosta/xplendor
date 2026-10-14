<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

/**
 * Radar: uma fonte pública (config/radar.php). Cada fonte tem o seu leitor, testado com as
 * amostras gravadas (tests/Fixtures/radar); a recolha (RadarCollector) faz o pedido, conta-o
 * para o teto por hora e guarda só o que a leitura devolve.
 */
interface RadarSource
{
    public function key(): string;

    public function label(): string;

    public function url(): string;

    /** trends | news | bce | ipma */
    public function type(): string;

    /** O texto que acompanha sempre a fonte ("Fonte: Google Trends"). */
    public function attribution(): string;

    public function enabled(): bool;

    public function intervalMinutes(): int;

    /** @return array<string, mixed> a configuração da fonte (termos, robots.txt, motivo de estar desligada) */
    public function config(): array;

    /** Um pedido à fonte, com os cabeçalhos condicionais da última recolha boa (304: nada novo). */
    public function fetch(array $conditionalHeaders = []): RadarHttpResponse;

    /** Lê o corpo de uma resposta (ou de uma amostra gravada) e devolve só o que se pode guardar. */
    public function parse(string $body): RadarParseResult;
}
