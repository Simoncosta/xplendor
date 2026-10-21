<?php

declare(strict_types=1);

namespace App\Services\Financing;

/**
 * Os documentos que o vendedor pode pedir (cada um aceita vários ficheiros: o CC frente e verso,
 * os 3 recibos, a declaração e a nota de liquidação) e os tipos de ficheiro aceites, confirmados
 * pelo CONTEÚDO (os primeiros bytes), nunca pela extensão nem pelo tipo que o browser diz.
 */
final class FinancingDocumentTypes
{
    /** chave => [rótulo, ajuda para o cliente] */
    public const ITEMS = [
        'cc' => ['Cartão de Cidadão', 'Frente e verso.'],
        'morada' => ['Comprovativo de morada', 'Uma fatura recente (água, luz, telecomunicações) ou outro comprovativo.'],
        'recibos' => ['Recibos de vencimento', 'Os 3 últimos.'],
        'irs' => ['Declaração de IRS e nota de liquidação', 'Do último ano.'],
        'iban' => ['Comprovativo de IBAN', 'Emitido pelo banco.'],
        'mapa_bp' => ['Mapa de responsabilidades do Banco de Portugal', 'Pode pedi-lo no Portal do Cliente Bancário.'],
        'outro' => ['Outro documento', ''],
    ];

    /** extensão => tipo MIME com que se guarda */
    public const ACCEPTED = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'heic' => 'image/heic', 'heif' => 'image/heif'];

    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'];
    private const HEIF_BRANDS = ['mif1', 'msf1', 'heif'];

    public static function label(string $item, ?string $other = null): string
    {
        if ($item === 'outro' && $other) {
            return $other;
        }

        return self::ITEMS[$item][0] ?? $item;
    }

    /** O tipo pelo conteúdo: [extensão, mime] ou null (recusado). @return array{0: string, 1: string}|null */
    public static function detect(string $path): ?array
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return null;
        }
        $head = (string) fread($fh, 1024);
        fclose($fh);
        if ($head === '' || \App\Services\Files\FileTypeGuard::looksExecutable($head) || str_contains(strtolower($head), '<html') || str_contains(strtolower($head), '<script')) {
            return null; // programas, scripts e HTML disfarçados, mesmo com um %PDF- lá dentro
        }

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => ['jpg', self::ACCEPTED['jpg']],
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => ['png', self::ACCEPTED['png']],
            // Alguns PDF trazem bytes antes do cabeçalho; aceita-se o %PDF- no primeiro KB.
            str_contains($head, '%PDF-') => ['pdf', self::ACCEPTED['pdf']],
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true) => ['heic', self::ACCEPTED['heic']],
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIF_BRANDS, true) => ['heif', self::ACCEPTED['heif']],
            default => null,
        };
    }
}
