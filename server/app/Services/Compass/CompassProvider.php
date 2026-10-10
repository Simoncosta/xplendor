<?php

declare(strict_types=1);

namespace App\Services\Compass;

use App\Models\User;

/**
 * Bússola comum: um fornecedor por ramo (a restauração, o stand). O núcleo comum (a escolha
 * das jogadas, o "Quando", o "Onde", as frases da IA, a regra sem Finanças, o "pode agir") vive
 * em App\Services\Compass; cada fornecedor dá os seus sinais, jogadas, topo e blocos.
 */
interface CompassProvider
{
    /** A chave do fornecedor ("restauracao", "stand"). */
    public function key(): string;

    /** O módulo da empresa que mostra esta Bússola. */
    public function module(): string;

    /** O interruptor do ramo nesta empresa (nasce desligado em produção). */
    public function isEnabled(int $companyId): bool;

    /**
     * A resposta da página para esta pessoa (já sem os valores financeiros, se ela não puder ver
     * as Finanças). $options: plays_only, summary, location_id, type.
     */
    public function pageFor(User $user, int $companyId, array $options = []): array;
}
