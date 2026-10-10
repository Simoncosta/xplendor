<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Ordenação das listas pedida pelo cliente (?sort=chave&dir=asc|desc), SÓ por uma lista de
 * chaves permitidas. O texto do pedido nunca chega ao SQL: serve apenas para escolher uma
 * entrada da lista, e é essa entrada (uma coluna escrita no código, ou uma função) que ordena.
 *  · chave desconhecida ou sentido que não seja asc/desc → 422 (ValidationException);
 *  · sem `sort` → a ordem por omissão de cada lista (a de sempre);
 *  · no fim, ordena sempre pelo id, no sentido da primeira ordenação: a ordem fica estável
 *    entre páginas mesmo com valores repetidos.
 */
final class ListSort
{
    /**
     * @param array<string, string|Closure(Builder, string): void> $allowed chave pública → coluna, ou função (query, sentido)
     * @param list<array{0: string, 1: string}>                    $default pares [chave da lista, sentido] quando não há `sort`
     * @return array{sort: string|null, dir: string} o que foi aplicado (null = ordem por omissão)
     */
    public static function apply(Builder $query, Request $request, array $allowed, array $default, string $idColumn = 'id'): array
    {
        $data = Validator::make(
            ['sort' => $request->input('sort'), 'dir' => $request->input('dir')],
            [
                'sort' => ['nullable', 'string', Rule::in(array_keys($allowed))],
                'dir'  => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            ],
            [
                'sort.in' => 'Ordenação inválida: use ' . implode(', ', array_keys($allowed)) . '.',
                'dir.in'  => 'Sentido inválido: use asc ou desc.',
            ],
        )->validate();

        $pairs = $data['sort'] !== null && $data['sort'] !== ''
            ? [[$data['sort'], $data['dir'] ?? 'asc']]
            : $default;

        foreach ($pairs as [$key, $dir]) {
            $target = $allowed[$key];
            $target instanceof Closure ? $target($query, $dir) : $query->orderBy($target, $dir);
        }
        $query->orderBy($idColumn, $pairs[0][1] ?? 'asc');

        return ['sort' => $data['sort'] ?: null, 'dir' => $data['sort'] ? ($data['dir'] ?? 'asc') : ($pairs[0][1] ?? 'asc')];
    }
}
