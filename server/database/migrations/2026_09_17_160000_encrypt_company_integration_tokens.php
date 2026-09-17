<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Cifra os access_token existentes de company_integrations (estavam em
 * TEXTO SIMPLES). Só transforma DADOS (não mexe no schema).
 *
 * ⚠️ Transição sem partir o pipeline (cautela 2026-06-09):
 *  · Opera em RAW DB (DB::table), NÃO pelo modelo — senão o cast EncryptedLegacy
 *    interferia (dupla cifra).
 *  · IDEMPOTENTE: para cada token, tenta decifrar; se JÁ está cifrado, salta; se
 *    falha (texto simples), cifra. Correr duas vezes é seguro.
 *  · O cast EncryptedLegacy lê ambos os formatos, por isso mesmo que uma linha
 *    escape aqui, o pipeline continua a funcionar (belt-and-suspenders).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('company_integrations')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $token = $row->access_token;
                if ($token === null || $token === '') {
                    continue;
                }

                // Já cifrado? (decifra sem erro) → nada a fazer.
                try {
                    Crypt::decryptString($token);
                    continue;
                } catch (DecryptException) {
                    // Texto simples legado → cifrar.
                }

                DB::table('company_integrations')
                    ->where('id', $row->id)
                    ->update(['access_token' => Crypt::encryptString($token)]);
            }
        });

        Log::info('[Migration] company_integrations access_token cifrado em repouso.');
    }

    public function down(): void
    {
        // Reverte para texto simples (best-effort). Só decifra o que estiver cifrado.
        DB::table('company_integrations')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $token = $row->access_token;
                if ($token === null || $token === '') {
                    continue;
                }
                try {
                    $plain = Crypt::decryptString($token);
                } catch (DecryptException) {
                    continue; // já em texto simples
                }
                DB::table('company_integrations')
                    ->where('id', $row->id)
                    ->update(['access_token' => $plain]);
            }
        });
    }
};
