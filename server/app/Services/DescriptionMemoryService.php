<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\SupplierDescriptionMemory;

/**
 * Memória por fornecedor e descrição (B2), só para as linhas sem código do fornecedor:
 * - aprende com a ligação à mão (associar, sugestão aceite, "Ligar selecionadas a…"); mudar a
 *   ligação muda a memória;
 * - no decide(), entra depois do código (mapa) e da linha do documento PingWin ligado, e antes da
 *   descrição igual e das sugestões; a linha fica "Ligada pela memória" e pode desfazer-se (a
 *   memória esquece o par);
 * - nunca aprende descrições genéricas: menos de 6 caracteres, sem letras, ou da lista GENERIC.
 */
class DescriptionMemoryService
{
    public const METHOD = 'memoria';
    public const MIN_LENGTH = 6;

    /**
     * Descrições (normalizadas: minúsculas, sem acentos) que nunca se aprendem: dizem o tipo de linha,
     * não o produto, e o mesmo fornecedor usa-as para coisas diferentes. Os portes, o transporte, o
     * vasilhame e as taxas ambientais aprendem-se: como a memória é por fornecedor, apontam sempre para
     * o mesmo artigo.
     */
    public const GENERIC = [
        'desconto', 'descontos', 'desconto comercial', 'desconto financeiro', 'arredondamento', 'acerto', 'acertos',
        'diversos', 'artigo', 'artigos', 'artigos diversos', 'produto', 'produtos', 'produtos diversos', 'outros produtos',
        'mercadoria', 'mercadorias', 'material', 'materiais', 'servico', 'servicos', 'prestacao de servicos',
        'embalagem', 'embalagens',
        'despesa', 'despesas', 'despesas gerais', 'oferta', 'ofertas', 'promocao', 'devolucao', 'devolucoes', 'credito', 'nota de credito',
    ];

    /** A descrição pode aprender-se? (normalizada; null = genérica). */
    public static function key(?string $description): ?string
    {
        $norm = OcrLineArticleService::normDesc($description);
        if (mb_strlen($norm) < self::MIN_LENGTH || ! preg_match('/[a-z]/', $norm) || in_array($norm, self::GENERIC, true)) {
            return null;
        }

        return mb_substr($norm, 0, 191);
    }

    /** Só as linhas sem código do fornecedor usam a memória. */
    public static function applies(OcrInvoiceLine $line): bool
    {
        return trim((string) $line->supplier_code) === '';
    }

    /** Aprende (ou muda) o par da ligação à mão. */
    public function learn(OcrInvoice $inv, OcrInvoiceLine $line, int $articleId, ?int $userId): void
    {
        $nif = SupplierArticleMapService::nif($inv->supplier_nif);
        $key = self::key($line->item);
        if ($nif === '' || $key === null || ! self::applies($line)) {
            return;
        }
        SupplierDescriptionMemory::updateOrCreate(['company_id' => $inv->company_id, 'supplier_nif' => $nif, 'description_norm' => $key],
            ['article_id' => $articleId, 'learned_by' => $userId]);
    }

    /** O artigo lembrado para a linha (id do catálogo), ou null. */
    public function lookup(OcrInvoice $inv, OcrInvoiceLine $line): ?SupplierDescriptionMemory
    {
        $nif = SupplierArticleMapService::nif($inv->supplier_nif);
        $key = self::key($line->item);
        if ($nif === '' || $key === null || ! self::applies($line)) {
            return null;
        }

        return SupplierDescriptionMemory::where('company_id', $inv->company_id)->where('supplier_nif', $nif)->where('description_norm', $key)->first();
    }

    /** Esquece o par (desfazer a ligação), se ainda for o deste artigo. */
    public function forget(OcrInvoice $inv, OcrInvoiceLine $line, int $articleId): void
    {
        $key = self::key($line->item);
        if ($key === null) {
            return;
        }
        SupplierDescriptionMemory::where('company_id', $inv->company_id)->where('supplier_nif', SupplierArticleMapService::nif($inv->supplier_nif))
            ->where('description_norm', $key)->where('article_id', $articleId)->delete();
    }
}
