<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\User;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Support\Facades\DB;

/**
 * O registo dos documentos de financiamento, na tabela "audits" que já existe (como os acessos
 * do root aos Ficheiros): cada descarga (evento "financiamento.descarga") e cada acesso do root a
 * outra empresa (evento "financiamento.acesso_root"). Quem, que empresa, que ação e o número do
 * documento ou do pedido; nunca o nome do cliente nem o do ficheiro.
 */
final class FinancingAudit
{
    public const DOWNLOAD = 'financiamento.descarga';
    public const ROOT_ACCESS = 'financiamento.acesso_root';

    public static function download(User $user, int $companyId, int $documentId, int $linkId): void
    {
        self::insert($user, $companyId, self::DOWNLOAD, \App\Models\FinancingDocument::class, $documentId,
            ['empresa' => $companyId, 'acao' => 'descarregar', 'documento' => $documentId, 'pedido' => $linkId], self::isRootElsewhere($user, $companyId));
    }

    /** O root a ver os pedidos ou os documentos de outra empresa. */
    public static function rootAccess(User $user, int $companyId, string $action, string $subjectType, int $subjectId): void
    {
        if (! self::isRootElsewhere($user, $companyId)) {
            return;
        }
        self::insert($user, $companyId, self::ROOT_ACCESS, $subjectType, $subjectId, ['empresa' => $companyId, 'acao' => $action], true);
    }

    private static function isRootElsewhere(User $user, int $companyId): bool
    {
        return $user->role === 'root' && app(CompanyAccess::class)->kind($user, $companyId) !== CompanyAccess::OWN;
    }

    private static function insert(User $user, int $companyId, string $event, string $type, int $id, array $values, bool $root): void
    {
        DB::table('audits')->insert([
            'user_type' => User::class, 'user_id' => $user->id, 'event' => $event, 'auditable_type' => $type, 'auditable_id' => $id,
            'old_values' => null, 'new_values' => json_encode($values, JSON_UNESCAPED_UNICODE),
            'url' => request()->fullUrl(), 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1023),
            'tags' => $root ? 'financiamento,root' : 'financiamento', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
