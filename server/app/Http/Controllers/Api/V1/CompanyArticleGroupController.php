<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditFindings;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ArticleGroup;
use App\Models\ArticleGroupMember;
use App\Models\AuditFinding;
use App\Services\Restaurant\CompassFinancials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Auditoria (prováveis duplicados): "Escolher artigo principal" de um grupo. Fica só na
 * XPLENDOR (article_groups); não escreve no PingWin. O seletor de componentes das fichas mostra o
 * principal com uma estrela.
 */
class CompanyArticleGroupController extends Controller
{
    public function setMain(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate([
            'finding_id' => ['required', 'integer'],
            'product_id' => ['required', 'string', 'max:32'],
        ]);
        $f = AuditFinding::where('company_id', $companyId)->whereKey($v['finding_id'])->where('rule', 'restauracao.duplicados')->first();
        if (! $f) {
            return ApiResponse::error('Grupo de duplicados não encontrado.', 404);
        }
        $members = array_map('strval', (array) ($f->fix_action['members'] ?? []));
        if (! in_array($v['product_id'], $members, true)) {
            return ApiResponse::error('O artigo principal tem de ser um dos artigos do grupo.', 422);
        }
        $key = (string) ($f->fix_action['group_key'] ?? $f->entity_id);

        DB::transaction(function () use ($companyId, $key, $f, $members, $v, $request) {
            $group = ArticleGroup::updateOrCreate(['company_id' => $companyId, 'key' => $key], [
                'label' => $f->fix_action['label'] ?? $f->entity_label, 'main_product_pingwin_id' => $v['product_id'],
                'chosen_by' => $request->user()?->id, 'chosen_at' => now(),
            ]);
            ArticleGroupMember::where('group_id', $group->id)->whereNotIn('product_pingwin_id', $members)->delete();
            foreach ($members as $pid) {
                ArticleGroupMember::firstOrCreate(['group_id' => $group->id, 'product_pingwin_id' => $pid], ['company_id' => $companyId]);
            }
            // A evidência do achado mostra já a estrela (a corrida seguinte volta a calculá-la).
            $evidence = $f->evidence ?? [];
            $evidence['group']['main'] = $v['product_id'];
            foreach ($evidence['table']['rows'] ?? [] as $i => $row) {
                $evidence['table']['rows'][$i]['main'] = ($row['product_id'] ?? null) === $v['product_id'];
            }
            $f->forceFill(['evidence' => $evidence])->save();
        });
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);

        return ApiResponse::success(['finding' => app(AuditFindings::class)->shape($f->fresh(), $finance, null, true) + ['can_edit' => true]],
            'Artigo principal escolhido.');
    }
}
