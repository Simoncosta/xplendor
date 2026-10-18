<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Audit\Rules\Restaurant\RestaurantRule;

/**
 * Auditoria v2-C · base da família "Dinheiro" (C1 a C6): onde está a fugir dinheiro. Só lê o espelho
 * (nunca o PingWin). A severidade vem do € em jogo (config auditoria.money.severity_eur). Separa o
 * € em jogo (confirmado) do € a confirmar (por exemplo, preços sem a unidade de compra confirmada).
 */
abstract class MoneyRule extends RestaurantRule
{
    public const FAMILY = 'dinheiro';

    public function family(): string
    {
        return self::FAMILY;
    }

    /** As regras com nomes de pessoas: só o administrador e o dono as veem. */
    public function sensitive(): bool
    {
        return false;
    }

    protected static function cfg(string $key, mixed $default = null): mixed
    {
        return config("auditoria.money.{$key}", $default);
    }

    protected static function severityFor(float $eur): string
    {
        $t = (array) self::cfg('severity_eur', ['alta' => 100, 'media' => 20]);

        return $eur >= (float) ($t['alta'] ?? 100) ? 'alta' : ($eur >= (float) ($t['media'] ?? 20) ? 'media' : 'baixa');
    }

    protected function data(AuditScope $scope): RestaurantAuditData
    {
        return RestaurantAuditData::for($scope);
    }

    /**
     * O preço por unidade base de uma linha de compra: com a quantidade confirmada (conversão
     * confirmada, ou a linha já na unidade base) é "confirmado"; senão, com a quantidade do PingWin,
     * fica "a confirmar". [preço, confirmado?]
     */
    protected static function unitPrice(array $l): array
    {
        if ($l['strict_qnt_base'] !== null && $l['strict_qnt_base'] > 0) {
            return [$l['total'] / $l['strict_qnt_base'], true];
        }

        return $l['qnt_base'] > 0 ? [$l['total'] / $l['qnt_base'], false] : [null, false];
    }

    protected static function baseQty(array $l): float
    {
        return (float) ($l['strict_qnt_base'] ?? $l['qnt_base']);
    }

    /**
     * Um produto (com stock no PingWin), não um serviço ou uma despesa (eletricidade, telefone,
     * avenças: stockconfig 7500). Sem a configuração no espelho: só se entra numa ficha.
     */
    protected function isProduct(AuditScope $scope, string $pid): bool
    {
        $cfg = $this->data($scope)->item($pid)?->stockconfig_id;
        if ($cfg !== null && $cfg !== '') {
            return (string) $cfg !== '7500';
        }

        return isset($scope->memo('restauracao.money.bom_components', fn () => array_fill_keys(\Illuminate\Support\Facades\DB::table('pingwin_product_bom_lines')
            ->where('company_id', $scope->companyId)->distinct()->pluck('component_pingwin_id')->map(fn ($v) => (string) $v)->all(), true))[$pid]);
    }

    /** "12,5 kg", "3 L", "40 unidades": a quantidade na unidade base, com o nome da unidade. */
    protected static function qtyText(float $q, string $unitName): string
    {
        $n = mb_strtolower($unitName);
        $base = str_contains($n, 'quilo') || $n === 'kg' ? 'kg' : (str_contains($n, 'litro') || $n === 'l' ? 'L' : 'un');

        return \App\Services\Restaurant\Units\UnitProposal::qty(round($q, 3), $base, $base === 'un' ? $n : null);
    }

    protected static function supplierKey(array $l): string
    {
        return (string) ($l['nif'] ?: $l['supplier_id'] ?: $l['supplier']);
    }
}
