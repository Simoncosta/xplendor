<?php

declare(strict_types=1);

namespace App\Access;

/**
 * ACL: os perfis de COMPATIBILIDADE, derivados da fotografia do varrimento (não escritos à
 * mão). Para cada ator de hoje e cada permissão do catálogo: se alguma rota dessa permissão
 * dá 403 ao ator, a permissão fica negada no perfil equivalente. Uma permissão com rotas
 * que dão 403 e rotas que respondem 2xx ao mesmo ator é um CONFLITO: o catálogo é grosso de
 * mais e tem de ser dividido (a derivação recusa-se a gravar).
 *
 *   cliente_admin       → "Administrador"
 *   cliente_utilizador  → "Utilizador (como hoje)"; o aprovador de conteúdos é o mesmo perfil
 *                         + editorial.aprovar (a coluna can_approve_content continua a valer)
 *   agencia_admin       → teto "Agência convidada (como hoje)" e perfil "Administrador da agência (como hoje)"
 *   agencia_membro      → "Gestor de clientes (como hoje)" (o que falta ao membro face ao admin)
 *   root                → regra própria até à decisão D1 (F3): as rotas que hoje lhe dão 403
 *
 * Ficheiro gerado: database/data/acl/perfis-compatibilidade.json (php artisan acl:derive).
 */
final class CompatibilityProfiles
{
    public const FILE = 'database/data/acl/perfis-compatibilidade.json';
    public const SNAPSHOT_DIR = 'tests/Fixtures/acl/fotografia';

    public const CLIENT_ADMIN = 'cliente_admin';
    public const CLIENT_USER = 'cliente_utilizador';
    public const AGENCY_ADMIN = 'agencia_admin';
    public const AGENCY_MEMBER = 'agencia_membro';
    public const CEILING = 'teto_agencia';

    public const NAMES = [
        self::CLIENT_ADMIN => ['lado' => 'cliente', 'nome' => 'Administrador'],
        self::CLIENT_USER => ['lado' => 'cliente', 'nome' => 'Utilizador (como hoje)'],
        self::AGENCY_ADMIN => ['lado' => 'agencia', 'nome' => 'Administrador da agência (como hoje)'],
        self::AGENCY_MEMBER => ['lado' => 'agencia', 'nome' => 'Gestor de clientes (como hoje)'],
        self::CEILING => ['lado' => 'teto', 'nome' => 'Agência convidada (como hoje)'],
    ];

    private static ?array $loaded = null;

    /**
     * @param array<string, array{proibidas: string[], estados: array<string, int>}> $snapshots ator → fotografia
     * @param array<string, string> $map RoutePermissions::MAP
     * @return array{perfis: array, aprovador: string, root: array, conflitos: array}
     */
    public static function derive(array $snapshots, array $map): array
    {
        $byPermission = [];
        foreach ($map as $key => $permission) {
            $byPermission[$permission][] = self::fullKey($key);
        }
        ksort($byPermission);

        $conflicts = [];
        $denied = function (string $actor) use ($snapshots, $byPermission, &$conflicts): array {
            $forbidden = array_flip($snapshots[$actor]['proibidas']);
            $statuses = $snapshots[$actor]['estados'];
            $out = [];
            foreach ($byPermission as $permission => $routes) {
                $hit = array_values(array_filter($routes, fn ($r) => isset($forbidden[$r])));
                if ($hit === []) {
                    continue;
                }
                $out[] = $permission;
                $ok = array_values(array_filter($routes, fn ($r) => ! isset($forbidden[$r]) && ($statuses[$r] ?? 0) >= 200 && ($statuses[$r] ?? 0) < 300));
                if ($ok !== []) {
                    $conflicts[] = ['ator' => $actor, 'permissao' => $permission, 'com_403' => $hit, 'com_2xx' => $ok];
                }
            }

            return $out;
        };

        $clientAdmin = $denied('cliente_admin');
        $clientUser = $denied('cliente_utilizador');
        $approver = $denied('cliente_aprovador');
        $agencyAdmin = $denied('agencia_admin');
        $agencyMember = $denied('agencia_membro');

        // O aprovador tem de ser exatamente o utilizador + editorial.aprovar.
        if (array_values(array_diff($clientUser, $approver)) !== ['editorial.aprovar'] || array_diff($approver, $clientUser) !== []) {
            $conflicts[] = ['ator' => 'cliente_aprovador', 'permissao' => 'editorial.aprovar', 'com_403' => array_values(array_diff($approver, $clientUser)),
                'com_2xx' => array_values(array_diff($clientUser, $approver))];
        }
        // O membro da agência tem de ter, no máximo, o que o admin da agência tem.
        if (array_diff($agencyAdmin, $agencyMember) !== []) {
            $conflicts[] = ['ator' => 'agencia_membro', 'permissao' => '*', 'com_403' => [], 'com_2xx' => array_values(array_diff($agencyAdmin, $agencyMember))];
        }

        $platform = array_values(array_filter(Permissions::all(), fn ($p) => Permissions::area($p) === 'plataforma'));
        $clean = fn (array $list) => array_values(array_diff($list, $platform)); // a plataforma nunca entra num perfil

        return [
            'perfis' => [
                self::CLIENT_ADMIN => self::NAMES[self::CLIENT_ADMIN] + ['negadas' => $clean($clientAdmin)],
                self::CLIENT_USER => self::NAMES[self::CLIENT_USER] + ['negadas' => $clean($clientUser)],
                self::AGENCY_ADMIN => self::NAMES[self::AGENCY_ADMIN] + ['negadas' => []],
                self::AGENCY_MEMBER => self::NAMES[self::AGENCY_MEMBER] + ['negadas' => $clean(array_values(array_diff($agencyMember, $agencyAdmin)))],
                self::CEILING => self::NAMES[self::CEILING] + ['negadas' => $clean($agencyAdmin)],
            ],
            'aprovador' => 'editorial.aprovar',
            'root' => [
                'propria' => $snapshots['root_propria']['proibidas'],
                'outra' => $snapshots['root_outra']['proibidas'],
            ],
            'conflitos' => array_values(array_filter($conflicts, fn ($c) => ! str_starts_with($c['ator'], 'root'))),
        ];
    }

    /** @return array<string, array> as fotografias gravadas (ator → conteúdo). */
    public static function snapshots(): array
    {
        $out = [];
        foreach (glob(base_path(self::SNAPSHOT_DIR) . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $out[$data['ator']] = $data;
        }

        return $out;
    }

    /** O ficheiro gerado (em memória durante o processo). */
    public static function load(): array
    {
        return self::$loaded ??= json_decode((string) file_get_contents(base_path(self::FILE)), true);
    }

    /** @return array<string, true> permissões permitidas do perfil de compatibilidade */
    public static function allowed(string $profile): array
    {
        $denied = array_flip(self::load()['perfis'][$profile]['negadas'] ?? Permissions::assignable());
        $out = [];
        foreach (Permissions::assignable() as $p) {
            if (! isset($denied[$p])) {
                $out[$p] = true;
            }
        }

        return $out;
    }

    public static function fullKey(string $key): string
    {
        [$method, $uri] = explode(' ', $key, 2);

        return $method . ' ' . RoutePermissions::PREFIX . $uri;
    }

    public static function flush(): void
    {
        self::$loaded = null;
    }
}
