<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * XPLENDOR — Cast de string cifrada TOLERANTE À TRANSIÇÃO.
 *
 * Ao contrário do cast nativo 'encrypted' (que REBENTA se o valor na BD estiver
 * em texto simples), este:
 *  · LÊ: tenta decifrar; se falhar (valor legado em texto simples), devolve o
 *    valor cru — assim os tokens já existentes CONTINUAM a funcionar mesmo antes
 *    de a migração os cifrar (o pipeline Meta nunca para).
 *  · GRAVA: cifra sempre — qualquer escrita passa a ficar cifrada em repouso.
 *
 * Combinado com a migração que cifra os existentes, garante transição sem partir.
 */
class EncryptedLegacy implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Legado em texto simples (ainda não migrado) → devolve cru.
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return [$key => null];
        }

        return [$key => Crypt::encryptString((string) $value)];
    }
}
