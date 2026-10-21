<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

/**
 * Ficheiros de teste gerados na hora, sem nada guardado no repositório: o EICAR (o ficheiro de
 * teste padrão dos antivírus, inofensivo), um PDF limpo e um PDF com o EICAR anexado (passa a
 * verificação de tipo, que vê um PDF, e o ClamAV encontra o EICAR dentro dele). O EICAR está em
 * base64 para não ser apanhado pelos antivírus das máquinas onde o código está.
 */
final class AntivirusSamples
{
    private const EICAR_B64 = 'WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=';

    public static function eicar(): string
    {
        return base64_decode(self::EICAR_B64);
    }

    public static function cleanPdf(string $text = 'Documento de teste da XPLENDOR'): string
    {
        $content = 'BT /F1 18 Tf 72 720 Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ') Tj ET';

        return self::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);
    }

    /** Um PDF com o EICAR como ficheiro anexado (sem compressão). */
    public static function eicarPdf(): string
    {
        $eicar = self::eicar();
        $content = 'BT /F1 18 Tf 72 720 Td (Teste do antivirus) Tj ET';

        return self::pdf([
            '<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [(eicar.com) 6 0 R] >> >> >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Filespec /F (eicar.com) /EF << /F 7 0 R >> >>',
            '<< /Type /EmbeddedFile /Length ' . strlen($eicar) . " >>\nstream\n{$eicar}\nendstream",
        ]);
    }

    /** @param string[] $objects corpo de cada objeto, pela ordem (1 0 obj, 2 0 obj, …) */
    private static function pdf(array $objects): string
    {
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
