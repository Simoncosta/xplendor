<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Media\MediaService;
use App\Services\Storage\CompanyStorageUsage;

/**
 * /admin: o espaço usado por cada empresa, por tipo e no total, somado na base de dados (sem
 * listar o disco nem o bucket). Só o root (grupo /admin).
 */
class StorageUsageController extends Controller
{
    // GET /admin/storage-usage
    public function index()
    {
        $usage = CompanyStorageUsage::all();
        $names = Company::whereIn('id', array_keys($usage))->get(['id', 'fiscal_name', 'trade_name'])->keyBy('id');
        $rows = [];
        foreach ($usage as $companyId => $u) {
            $c = $names->get($companyId);
            $rows[] = ['company_id' => $companyId, 'company' => $c ? (string) ($c->trade_name ?: $c->fiscal_name) : "Empresa {$companyId}"] + $u;
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ApiResponse::success([
            'kinds' => CompanyStorageUsage::KINDS,
            'rows' => $rows,
            'totals' => [
                'bytes' => array_map(fn ($k) => array_sum(array_map(fn ($r) => $r['bytes'][$k], $rows)), array_combine(array_keys(CompanyStorageUsage::KINDS), array_keys(CompanyStorageUsage::KINDS))),
                'total' => array_sum(array_column($rows, 'total')),
                'sem_tamanho' => array_sum(array_column($rows, 'sem_tamanho')),
            ],
            'quota_bytes' => MediaService::quotaBytes(),
        ], 'Espaço por empresa.');
    }
}
