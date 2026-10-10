<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\User;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ficheiros: os acessos do root aos ficheiros de OUTRA empresa ficam registados. Usa o registo
 * que já existe, a tabela "audits" (owen-it/laravel-auditing), com o evento próprio
 * "ficheiros.acesso_root": quem, que empresa, que ação e que ficheiro ou pasta.
 */
final class RootFileAccessLog
{
    public const EVENT = 'ficheiros.acesso_root';

    public static function record(User $user, int $companyId, string $action, ?Model $subject = null): void
    {
        if ($user->role !== 'root' || app(CompanyAccess::class)->kind($user, $companyId) === CompanyAccess::OWN) {
            return;
        }
        DB::table('audits')->insert([
            'user_type' => User::class, 'user_id' => $user->id, 'event' => self::EVENT,
            'auditable_type' => $subject ? $subject::class : \App\Models\Company::class, 'auditable_id' => $subject?->getKey() ?? $companyId,
            'old_values' => null,
            'new_values' => json_encode(['empresa' => $companyId, 'acao' => $action, 'nome' => $subject?->getAttribute('name')], JSON_UNESCAPED_UNICODE),
            'url' => request()->fullUrl(), 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1023),
            'tags' => 'ficheiros,root', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
