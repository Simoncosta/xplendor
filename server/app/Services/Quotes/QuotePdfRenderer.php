<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Gera o PDF de um orçamento (dompdf, A4) a partir de um snapshot. Fontes Inter
 * locais (acentos PT); sem pedidos remotos. Escreve "Página X de Y" no rodapé de
 * cada página (o CSS do dompdf não conhece o total de páginas).
 */
class QuotePdfRenderer
{
    public function __construct(private readonly QuotePdfPresenter $presenter) {}

    public function render(array $snapshot): string
    {
        $fonts = storage_path('fonts');
        if (! is_dir($fonts)) {
            @mkdir($fonts, 0775, true);
        }

        $pdf = Pdf::setOption([
            'chroot'          => resource_path('pdf'),
            'fontDir'         => $fonts,
            'fontCache'       => $fonts,
            'isRemoteEnabled' => false,
            'defaultFont'     => 'Inter',
            'dpi'             => 144,
        ])->loadView('pdf.quote', ['doc' => $this->presenter->present($snapshot)])->setPaper('a4');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Inter', 'normal');
        // Margem de 16 mm à direita; alinhado com a última linha do rodapé.
        $canvas->page_text($canvas->get_width() - 45.35 - 60, $canvas->get_height() - 30, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7.5, [0.42, 0.44, 0.49]);

        return $dompdf->output();
    }
}
