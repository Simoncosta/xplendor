<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

/**
 * O espaço usado por cada empresa, por tipo, somado na BASE DE DADOS (sem listar o disco nem o
 * bucket). É também a soma da quota por empresa (MediaService::usedBytes).
 *  · media: Linha Editorial, os originais (enquanto existem, sem os recusados) e as variantes
 *    (miniatura, pré-visualização e poster);
 *  · ocr: as faturas do OCR;
 *  · cobrancas: os PDFs das cobranças da XPLENDOR e os comprovativos de pagamento;
 *  · faturas_tickets: as faturas dos pedidos de suporte;
 *  · fotos_relatorios: as fotografias dos relatórios de satisfação.
 * Não entram as fotos das contas ligadas (cópias pequenas da Meta) nem o disco público
 * (imagens das viaturas, logótipos, avatares), que não são ficheiros privados da empresa.
 * Os ficheiros sem tamanho registado (anteriores a esta contagem) somam 0 até o comando
 * storage:fill-sizes os preencher; "sem_tamanho" diz quantos faltam.
 */
final class CompanyStorageUsage
{
    public const KINDS = [
        'media' => 'Linha Editorial',
        'ocr' => 'Faturas do OCR',
        'cobrancas' => 'Cobranças e comprovativos',
        'faturas_tickets' => 'Faturas dos pedidos de suporte',
        'fotos_relatorios' => 'Fotografias dos relatórios de satisfação',
    ];

    /** @return array{bytes: array<string, int>, total: int, sem_tamanho: int} */
    public static function forCompany(int $companyId): array
    {
        return self::all([$companyId])[$companyId] ?? ['bytes' => array_fill_keys(array_keys(self::KINDS), 0), 'total' => 0, 'sem_tamanho' => 0];
    }

    public static function totalBytes(int $companyId): int
    {
        return self::forCompany($companyId)['total'];
    }

    /**
     * @param int[]|null $companyIds null = todas
     * @return array<int, array{bytes: array<string, int>, total: int, sem_tamanho: int}>
     */
    public static function all(?array $companyIds = null): array
    {
        $rows = [];
        $add = function (iterable $sums, string $kind) use (&$rows) {
            foreach ($sums as $r) {
                $id = (int) $r->company_id;
                $rows[$id] ??= ['bytes' => array_fill_keys(array_keys(self::KINDS), 0), 'total' => 0, 'sem_tamanho' => 0];
                $rows[$id]['bytes'][$kind] += (int) $r->bytes;
                $rows[$id]['sem_tamanho'] += (int) $r->missing;
            }
        };
        $scope = fn ($q, string $column = 'company_id') => $companyIds === null ? $q : $q->whereIn($column, $companyIds);

        $add($scope(DB::table('media_assets'))->groupBy('company_id')->selectRaw(
            'company_id, SUM(CASE WHEN original_deleted_at IS NULL AND status <> ? THEN size_bytes ELSE 0 END) + SUM(COALESCE(variants_bytes, 0)) AS bytes, '
            . 'SUM(CASE WHEN status = ? AND variants_bytes IS NULL THEN 1 ELSE 0 END) AS missing', [MediaAsset::REJECTED, MediaAsset::READY])->get(), 'media');
        $add($scope(DB::table('ocr_invoices')->whereNotNull('image_path'))->groupBy('company_id')
            ->selectRaw('company_id, SUM(COALESCE(image_size_bytes, 0)) AS bytes, SUM(CASE WHEN image_size_bytes IS NULL THEN 1 ELSE 0 END) AS missing')->get(), 'ocr');
        $add($scope(DB::table('expense_charges'))->groupBy('company_id')->selectRaw(
            'company_id, SUM(COALESCE(invoice_size_bytes, 0)) + SUM(COALESCE(proof_size_bytes, 0)) AS bytes, '
            . 'SUM(CASE WHEN invoice_path IS NOT NULL AND invoice_size_bytes IS NULL THEN 1 ELSE 0 END) + SUM(CASE WHEN proof_path IS NOT NULL AND proof_size_bytes IS NULL THEN 1 ELSE 0 END) AS missing')->get(), 'cobrancas');
        $add($scope(DB::table('support_tickets')->whereNotNull('invoice_path'))->groupBy('company_id')
            ->selectRaw('company_id, SUM(COALESCE(invoice_size_bytes, 0)) AS bytes, SUM(CASE WHEN invoice_size_bytes IS NULL THEN 1 ELSE 0 END) AS missing')->get(), 'faturas_tickets');
        $add($scope(DB::table('satisfaction_report_photos')->join('satisfaction_reports', 'satisfaction_reports.id', '=', 'satisfaction_report_photos.satisfaction_report_id'), 'satisfaction_reports.company_id')
            ->groupBy('satisfaction_reports.company_id')
            ->selectRaw('satisfaction_reports.company_id AS company_id, SUM(COALESCE(satisfaction_report_photos.size_bytes, 0)) AS bytes, '
                . 'SUM(CASE WHEN satisfaction_report_photos.size_bytes IS NULL THEN 1 ELSE 0 END) AS missing')->get(), 'fotos_relatorios');

        foreach ($rows as $id => $r) {
            $rows[$id]['total'] = array_sum($r['bytes']);
        }

        return $rows;
    }
}
