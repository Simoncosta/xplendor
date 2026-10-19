<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinWarehouse;

/**
 * XPLENDOR — Configurações: o travão das escritas no PingWin, por esta ordem.
 *  1. Global (.env PINGWIN_WRITES_ENABLED, por defeito true): com false, nenhuma empresa grava nada.
 *  2. Por empresa (root): "Escrita no PingWin" desligada → nada se grava nessa empresa.
 *  3. Por empresa (root): "Modo de testes" → só artigos cuja descrição começa por "TESTE XPLENDOR"
 *     (e armazéns com esse nome); as outras escritas (fornecedores, condições de pagamento,
 *     documentos, unidades globais) ficam bloqueadas.
 *  4. Sem as duas definições gravadas na empresa: as listas antigas do .env (compatibilidade).
 *
 * As listas vão para o Python como antes: [] = todos; ['0'] = nenhum; senão, os autorizados.
 */
final class PingwinWriteGate
{
    public const TEST_PREFIX = 'TESTE XPLENDOR';

    /** Capacidade → [chave da config da lista antiga, variável do .env, o que a lista contém]. */
    private const LEGACY = [
        'bom' => ['services.pingwin.bom_write_allowlist', 'PINGWIN_BOM_WRITE_ALLOWLIST', 'id'],
        'unit' => ['services.pingwin.unit_write_allowlist', 'PINGWIN_UNIT_WRITE_ALLOWLIST', 'code'],
        'stock' => ['services.pingwin.stock_write_allowlist', 'PINGWIN_STOCK_WRITE_ALLOWLIST', 'id'],
        'stockdoc' => ['services.pingwin.stockdoc_write_allowlist', 'PINGWIN_STOCKDOC_WRITE_ALLOWLIST', 'code'],
        'warehouse' => ['services.pingwin.warehouse_write_allowlist', 'PINGWIN_WAREHOUSE_WRITE_ALLOWLIST', 'name'],
    ];

    public static function globalEnabled(): bool
    {
        return (bool) config('services.pingwin.writes_enabled', true);
    }

    /** As listas antigas do .env ainda mandam nesta empresa (nenhuma das duas definições está gravada). */
    public static function legacy(?int $companyId): bool
    {
        return ! $companyId || (! Settings::companyHas($companyId, 'pingwin.writes_enabled') && ! Settings::companyHas($companyId, 'pingwin.test_mode'));
    }

    public static function testMode(?int $companyId): bool
    {
        return $companyId !== null && ! self::legacy($companyId) && (bool) Settings::company($companyId, 'pingwin.test_mode');
    }

    /**
     * O travão global e o da empresa: null = pode gravar; senão {mode, reason} para o ecrã.
     *
     * @return array{mode: string, reason: string}|null
     */
    public static function block(?int $companyId): ?array
    {
        if (! self::globalEnabled()) {
            return ['mode' => 'desligada', 'reason' => 'A escrita no PingWin está desligada em toda a plataforma (PINGWIN_WRITES_ENABLED=false).'];
        }
        if ($companyId && ! self::legacy($companyId) && ! Settings::company($companyId, 'pingwin.writes_enabled')) {
            return ['mode' => 'desligada', 'reason' => 'A escrita no PingWin está desligada para esta empresa (Admin › Integrações › PingWin).'];
        }

        return null;
    }

    /** @return list<string> a lista para uma capacidade (bom, unit, stock, stockdoc, warehouse) */
    public static function allowlist(?int $companyId, string $capability): array
    {
        [$configKey, , $kind] = self::LEGACY[$capability];
        if (self::block($companyId)) {
            return ['0'];
        }
        if (self::legacy($companyId)) {
            return array_values(array_filter(array_map('trim', explode(',', (string) config($configKey, ''))), static fn ($v) => $v !== ''));
        }
        if (! self::testMode($companyId)) {
            return [];
        }
        $list = $kind === 'name'
            ? PingwinWarehouse::where('company_id', $companyId)->where('description', 'like', self::TEST_PREFIX . '%')->pluck('description')->map(fn ($v) => (string) $v)->all()
            : PingwinCatalogItem::where('company_id', $companyId)->where('description', 'like', self::TEST_PREFIX . '%')
                ->pluck($kind === 'id' ? 'pingwin_id' : 'code')->map(fn ($v) => ltrim((string) $v, "\u{FEFF}"))->filter()->values()->all();

        return $list ?: ['0'];
    }

    public static function legacyEnv(string $capability): string
    {
        return self::LEGACY[$capability][1];
    }

    /**
     * Uma escrita que não é de um artigo nem de um armazém (fornecedor, condição de pagamento,
     * documento, unidade global): null = pode; no modo de testes fica bloqueada.
     */
    public static function blockOther(?int $companyId): ?array
    {
        if ($b = self::block($companyId)) {
            return $b;
        }

        return self::testMode($companyId)
            ? ['mode' => 'teste', 'reason' => 'Modo de testes: só se grava em artigos cuja descrição começa por "TESTE XPLENDOR".']
            : null;
    }

    /** Uma escrita de um artigo pela descrição (criar, editar, anular): no modo de testes, só os "TESTE XPLENDOR". */
    public static function blockArticle(?int $companyId, ?string $description): ?array
    {
        if ($b = self::block($companyId)) {
            return $b;
        }
        if (self::testMode($companyId) && ! str_starts_with(mb_strtoupper(trim((string) $description)), self::TEST_PREFIX)) {
            return ['mode' => 'teste', 'reason' => 'Modo de testes: só se grava em artigos cuja descrição começa por "TESTE XPLENDOR".'];
        }

        return null;
    }
}
