<?php

declare(strict_types=1);

namespace App\Access;

/**
 * ACL: o catálogo das permissões. Uma permissão é uma ÁREA × uma AÇÃO ("editorial.aprovar").
 * A ação de cada rota é declarada (RoutePermissions), nunca deduzida do verbo HTTP.
 * Desenho: documents/ACL-DESENHO.md.
 *
 *  · As áreas "base" não dependem de módulos; as outras seguem o módulo indicado (a
 *    verificação do módulo de cada rota continua no ensure_module da rota; o módulo da área
 *    serve o /my-access e as rotas que ainda não o verificam, decisão D8).
 *  · "plataforma" é só do root (nunca entra num perfil).
 *  · "agencia" são ações da agência gestora dentro do cliente (por exemplo, convidar o
 *    primeiro administrador): nunca entra num perfil do lado do cliente.
 *  · As DECISÕES DO CLIENTE (D1) exigem uma pessoa do próprio cliente: nem o root nem a
 *    agência as tomam.
 */
final class Permissions
{
    public const ACTIONS = [
        'ver' => 'ver',
        'criar' => 'criar',
        'editar' => 'editar',
        'aprovar' => 'aprovar',
        'apagar' => 'apagar',
        'configurar' => 'configurar',
    ];

    /** área => [rótulo, módulo (null = base), ações possíveis] */
    public const AREAS = [
        'empresa' => ['label' => 'Empresa', 'module' => null, 'actions' => ['ver', 'editar', 'aprovar']],
        'utilizadores' => ['label' => 'Utilizadores e equipa', 'module' => null, 'actions' => ['ver', 'criar', 'editar', 'apagar', 'configurar']],
        'integracoes' => ['label' => 'Integrações', 'module' => null, 'actions' => ['ver', 'editar', 'configurar']],
        'faturacao_xplendor' => ['label' => 'Faturação da XPLENDOR', 'module' => null, 'actions' => ['ver', 'aprovar']],
        'editorial' => ['label' => 'Linha Editorial', 'module' => 'linha_editorial', 'actions' => ['ver', 'criar', 'editar', 'aprovar', 'apagar', 'configurar']],
        'blog' => ['label' => 'Blog', 'module' => null, 'actions' => ['ver', 'criar', 'editar', 'aprovar', 'apagar']],
        'marca' => ['label' => 'Marca', 'module' => null, 'actions' => ['ver', 'criar', 'editar']],
        'bussola' => ['label' => 'Bússola', 'module' => 'pingwin', 'actions' => ['ver', 'criar', 'editar']],
        'resultados' => ['label' => 'Resultados', 'module' => 'marketing_analytics', 'actions' => ['ver']],
        'financas' => ['label' => 'Finanças', 'module' => 'finance', 'actions' => ['ver', 'criar', 'editar', 'apagar']],
        'restauracao' => ['label' => 'Restauração', 'module' => 'pingwin', 'actions' => ['ver', 'criar', 'editar', 'apagar', 'configurar']],
        'automovel' => ['label' => 'Automóvel', 'module' => 'stock', 'actions' => ['ver', 'criar', 'editar', 'apagar']],
        'suporte' => ['label' => 'Suporte', 'module' => 'support_tasks', 'actions' => ['ver', 'criar', 'editar', 'apagar']],
        'agencia' => ['label' => 'Agência gestora', 'module' => null, 'actions' => ['configurar']],
        'plataforma' => ['label' => 'Plataforma', 'module' => null, 'actions' => ['configurar']],
    ];

    /** Áreas que nunca entram num perfil (só o root, ou só a agência). */
    public const NOT_ASSIGNABLE = ['plataforma'];

    /** D1: decisões do cliente. Nem o root nem a agência as tomam. */
    public const CLIENT_DECISIONS = ['editorial.aprovar', 'blog.aprovar', 'faturacao_xplendor.aprovar', 'empresa.aprovar'];

    /** Rótulos das ações em frases ("aprovar na Linha Editorial"). */
    public const ACTION_PHRASES = [
        'ver' => 'ver', 'criar' => 'criar', 'editar' => 'editar', 'aprovar' => 'aprovar', 'apagar' => 'apagar', 'configurar' => 'configurar',
    ];

    /** @return string[] todas as permissões válidas ("area.acao"). */
    public static function all(): array
    {
        $out = [];
        foreach (self::AREAS as $area => $def) {
            foreach ($def['actions'] as $action) {
                $out[] = "{$area}.{$action}";
            }
        }

        return $out;
    }

    /** @return string[] as que podem entrar num perfil. */
    public static function assignable(): array
    {
        return array_values(array_filter(self::all(), fn (string $p) => ! in_array(self::area($p), self::NOT_ASSIGNABLE, true)));
    }

    public static function isValid(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    public static function area(string $permission): string
    {
        return explode('.', $permission, 2)[0];
    }

    public static function action(string $permission): string
    {
        return explode('.', $permission, 2)[1] ?? '';
    }

    public static function areaLabel(string $area): string
    {
        return self::AREAS[$area]['label'] ?? $area;
    }

    public static function isClientDecision(string $permission): bool
    {
        return in_array($permission, self::CLIENT_DECISIONS, true);
    }

    /** "aprovar em Linha Editorial" */
    public static function phrase(string $permission): string
    {
        return (self::ACTION_PHRASES[self::action($permission)] ?? self::action($permission)) . ' em ' . self::areaLabel(self::area($permission));
    }
}
