<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinCatalogItem;
use Illuminate\Support\Facades\DB;

/**
 * Regra 7 · Artigo vendido sem ficha (média): forsale=1, forpurchase=0, com vendas nos últimos 90
 * dias e sem nenhuma ficha em vigor. Peso: as vendas do artigo. "Corrigir" abre "Criar ficha".
 * Ficam de fora os artigos com vendas a 0 € nos 90 dias (modificadores como Gelo, Limão, Fria,
 * Natural) e os que não são do tipo "Artigo" (Complemento, Comentário, Serviço, Taxa de serviço,
 * Vasilhame, Embalagem, Voucher, Carregar saldo, Impostos...).
 */
final class VendidoSemFichaRule extends RestaurantRule
{
    public function code(): string
    {
        return 'restauracao.vendido_sem_ficha';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Artigos vendidos sem ficha técnica';
    }

    public function description(): string
    {
        return 'Artigos de venda que não se compram já feitos, com vendas nos últimos 90 dias e sem ficha técnica.';
    }

    public function screens(): array
    {
        return ['fichas', 'artigos'];
    }

    /** Só o tipo "Artigo" (pelo rótulo, ou pelo id 1 quando o rótulo falta). Sem tipo nenhum: conta. */
    public static function isArticleType(PingwinCatalogItem $item): bool
    {
        $label = $item->product_type ?? (is_array($item->raw) ? ($item->raw['product_type'] ?? null) : null);
        if ($label !== null && $label !== '') {
            return mb_strtolower(trim((string) $label)) === 'artigo';
        }

        return $item->product_type_id === null || $item->product_type_id === '' || (string) $item->product_type_id === '1';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $withBom = array_fill_keys(DB::table('pingwin_product_boms')->where('company_id', $companyId)->where('deleted', false)
            ->where('valid_from', '<=', $data->today())->distinct()->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->all(), true);

        foreach ($data->sales90() as $pid => $s) {
            $pid = (string) $pid;
            $item = $data->item($pid);
            if (! $item || ! $item->forsale || $item->forpurchase || ! $item->is_active || $s['qty'] <= 0 || $s['net'] <= 0
                || isset($withBom[$pid]) || ! self::isArticleType($item)) {
                continue;
            }

            yield new AuditFindingData(
                entityType: 'artigo', entityId: $pid, entityLabel: $data->label($pid),
                title: Fmt::title((string) $item->description, 'vendido sem ficha técnica'),
                why: 'Sem ficha, o PingWin não desconta os ingredientes do stock e a XPLENDOR não calcula o custo nem o food cost deste artigo.',
                suggestion: 'Crie a ficha técnica com os ingredientes e as quantidades de uma dose. Se o artigo não leva ingredientes (um serviço, por exemplo), ignore com esse motivo.',
                evidence: ['facts' => [
                    ['label' => 'Família', 'value' => RestaurantAuditData::familyPath($item->family)],
                    ['label' => 'Unidades vendidas (90 dias)', 'value' => Fmt::qty($s['qty'])],
                    ['label' => 'Vendas sem IVA (90 dias)', 'value' => $s['net'] / 100, 'format' => 'eur'],
                ]],
                signature: 'sem_ficha',
                weightCents: $s['net'] > 0 ? $s['net'] : null,
                fixUrl: self::recipeUrl($pid, ['editar' => 1]), fixLabel: 'Criar ficha',
                refs: [['artigo', $pid]],
                seen: sprintf('Nos últimos 90 dias vendeu %s (%s sem IVA). Não tem ficha técnica em vigor.', Fmt::units($s['qty'], 'unidade'), Fmt::cents($s['net'])),
                stake: sprintf('Não há um valor direto em jogo. A relevância são as vendas dos últimos 90 dias: %s sem IVA.', Fmt::cents($s['net'])),
            );
        }
    }
}
