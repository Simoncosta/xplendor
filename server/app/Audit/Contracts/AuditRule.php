<?php

declare(strict_types=1);

namespace App\Audit\Contracts;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;

/**
 * XPLENDOR — Auditoria: uma regra. Uma classe por regra, no registo central (AuditRegistry). O
 * motor não conhece nenhuma regra em concreto: corre run() e guarda o que ela devolve.
 */
interface AuditRule
{
    /** Código estável (ex.: "restauracao.iva_venda"): faz parte da impressão digital dos achados. */
    public function code(): string;

    /** A área (restauracao, automovel, tracking...): decide quem vê e quem muda o estado. */
    public function area(): string;

    /** O tipo da entidade principal dos achados (artigo, linha_compra, documento, viatura...). */
    public function entityType(): string;

    /** A gravidade por omissão: alta | media | baixa | info. */
    public function severity(): string;

    public function title(): string;

    /** Uma frase sobre o que a regra procura (para a lista de regras e o pipeline). */
    public function description(): string;

    /** Os ecrãs onde o botão "Auditoria" corre esta regra (artigos, familias, faturas, fornecedores, fichas). */
    public function screens(): array;

    /** Se a regra se aplica a esta empresa (módulo ligado, integração ativa...). */
    public function appliesTo(int $companyId): bool;

    /**
     * Os achados de hoje. Só lê (o espelho, as tabelas da XPLENDOR): nunca corrige nada.
     *
     * @return iterable<AuditFindingData>
     */
    public function run(int $companyId, AuditScope $scope): iterable;
}
