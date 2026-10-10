<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\PermissionProfile;

/**
 * ACL (F5, D13): as SUGESTÕES de perfil. "Novo perfil" parte de uma sugestão ou de um perfil
 * vazio; a sugestão nunca é imposta (o administrador edita tudo antes de gravar). Também as
 * frases simples que explicam, área a área, o que um conjunto de permissões dá.
 *
 * Lado do cliente: Marketing, Financeiro, Só leitura (D9) e Agência convidada (D14).
 * Lado da agência (permissões dentro dos clientes): Administrador da agência, Gestor de
 * clientes e Criativo externo (este só vê os clientes atribuídos, D11).
 */
final class ProfileSuggestions
{
    public const MARKETING = 'sugestao_marketing';
    public const FINANCE = 'sugestao_financeiro';
    public const READ_ONLY = 'sugestao_so_leitura';
    public const GUEST_AGENCY = 'sugestao_agencia_convidada';
    public const AGENCY_ADMIN = 'sugestao_agencia_administrador';
    public const AGENCY_MANAGER = 'sugestao_agencia_gestor';
    public const AGENCY_CREATIVE = 'sugestao_agencia_criativo';

    /** chave => [lado, nome, descrição, só clientes atribuídos, permissões] */
    public static function all(): array
    {
        $everything = array_values(array_filter(Permissions::assignable(), fn ($p) => Permissions::area($p) !== 'agencia'));

        return [
            self::MARKETING => [PermissionProfile::SIDE_CLIENT, 'Marketing', 'Produz e aprova os conteúdos, trata da marca e da Bússola e vê os resultados.', false, [
                'empresa.ver', 'utilizadores.ver', 'integracoes.ver',
                'editorial.ver', 'editorial.criar', 'editorial.editar', 'editorial.aprovar', 'editorial.apagar',
                'blog.ver', 'blog.criar', 'blog.editar', 'blog.aprovar', 'blog.apagar',
                'marca.ver', 'marca.criar', 'marca.editar', 'bussola.ver', 'bussola.criar', 'bussola.editar', 'resultados.ver',
                'restauracao.ver', 'automovel.ver', 'suporte.ver', 'suporte.criar', 'tarefas.ver', 'tarefas.criar', 'tarefas.editar',
            ]],
            self::FINANCE => [PermissionProfile::SIDE_CLIENT, 'Financeiro', 'Trata das finanças, das cobranças e orçamentos da XPLENDOR e do back-office da restauração.', false, [
                'empresa.ver', 'utilizadores.ver', 'editorial.ver', 'resultados.ver',
                'financas.ver', 'financas.criar', 'financas.editar', 'financas.apagar', 'faturacao_xplendor.ver', 'faturacao_xplendor.aprovar',
                'restauracao.ver', 'restauracao.criar', 'restauracao.editar', 'automovel.ver', 'suporte.ver', 'suporte.criar', 'tarefas.ver', 'tarefas.criar', 'tarefas.editar',
            ]],
            // D9: vê a empresa, a Linha Editorial, o blog, a marca, a Bússola, os resultados e o
            // suporte (e as tarefas, que eram do suporte); não vê as Finanças, a faturação da XPLENDOR, os utilizadores, as
            // integrações nem o back-office da restauração.
            self::READ_ONLY => [PermissionProfile::SIDE_CLIENT, 'Só leitura', 'Consulta, sem alterar nada. Não vê as finanças, a faturação, a equipa, as integrações nem o back-office da restauração.', false, [
                'empresa.ver', 'editorial.ver', 'blog.ver', 'marca.ver', 'bussola.ver', 'resultados.ver', 'suporte.ver', 'tarefas.ver',
            ]],
            // D14: o caso da Media Tailors na Yuko (utilizadores da própria empresa).
            self::GUEST_AGENCY => [PermissionProfile::SIDE_CLIENT, 'Agência convidada', 'Para uma agência que trabalha com a empresa: produz na Linha Editorial e na Bússola e vê os resultados, sem aprovar.', false, [
                'editorial.ver', 'editorial.criar', 'editorial.editar', 'editorial.apagar', 'bussola.ver', 'bussola.criar', 'bussola.editar', 'resultados.ver',
            ]],
            self::AGENCY_ADMIN => [PermissionProfile::SIDE_AGENCY, 'Administrador da agência', 'Trabalha em todos os clientes, dentro do que cada cliente permite.', false, $everything],
            self::AGENCY_MANAGER => [PermissionProfile::SIDE_AGENCY, 'Gestor de clientes', 'Produz conteúdos, trata da marca e da Bússola e vê os resultados dos clientes que gere.', false, [
                'editorial.ver', 'editorial.criar', 'editorial.editar', 'editorial.apagar', 'blog.ver', 'blog.criar', 'blog.editar', 'blog.apagar',
                'marca.ver', 'marca.criar', 'marca.editar', 'bussola.ver', 'bussola.criar', 'bussola.editar', 'resultados.ver', 'integracoes.ver',
                'suporte.ver', 'suporte.criar', 'tarefas.ver', 'tarefas.criar',
            ]],
            // D11: só os clientes atribuídos, mesmo com team_scope = all.
            self::AGENCY_CREATIVE => [PermissionProfile::SIDE_AGENCY, 'Criativo externo', 'Produz conteúdos só nos clientes a que está atribuído.', true, [
                'editorial.ver', 'editorial.criar', 'editorial.editar', 'blog.ver', 'blog.criar', 'blog.editar', 'marca.ver', 'bussola.ver',
            ]],
        ];
    }

    /** Cria ou atualiza as sugestões (perfis de sistema, is_suggestion). Idempotente. @return array<string, int> */
    public static function ensure(): array
    {
        $out = [];
        foreach (self::all() as $key => [$side, $name, $description, $onlyAssigned, $permissions]) {
            $profile = PermissionProfile::firstOrNew(['system_key' => $key]);
            $profile->fill(['company_id' => null, 'side' => $side, 'name' => $name, 'description' => $description,
                'is_system' => true, 'is_suggestion' => true, 'only_assigned_clients' => $onlyAssigned])->save();
            $profile->syncPermissions($permissions);
            $out[$key] = $profile->id;
        }

        return $out;
    }

    /**
     * D13: o que um conjunto de permissões dá, em linguagem simples, área a área.
     * Por exemplo, "Linha Editorial: pode ver, criar e editar; não aprova".
     *
     * @param string[] $permissions
     * @param string[]|null $allowed só as áreas que fazem sentido para este lado do perfil
     * @return array<int, array{area: string, label: string, text: string, actions: string[]}>
     */
    public static function describe(array $permissions, ?array $allowed = null): array
    {
        $set = array_flip($permissions);
        $allowedAreas = $allowed === null ? null : array_flip(array_map(fn ($p) => Permissions::area($p), $allowed));
        $out = [];
        foreach (Permissions::AREAS as $area => $def) {
            if (in_array($area, Permissions::NOT_ASSIGNABLE, true) || ($allowedAreas !== null && ! isset($allowedAreas[$area]))) {
                continue;
            }
            $has = array_values(array_filter($def['actions'], fn ($a) => isset($set["{$area}.{$a}"])));
            $missing = array_values(array_diff($def['actions'], $has));
            if (in_array($area, ['empresa'], true) && ! in_array('ver', $has, true)) {
                $has = array_merge(['ver'], $has); // empresa.ver é base: está sempre
                $missing = array_values(array_diff($def['actions'], $has));
            }
            if ($has === []) {
                $text = 'Não vê.';
            } elseif ($missing === []) {
                $text = 'Pode ' . self::join(array_map(fn ($a) => self::VERBS[$a], $has)) . '.';
            } else {
                $text = 'Pode ' . self::join(array_map(fn ($a) => self::VERBS[$a], $has)) . '; não pode ' . self::join(array_map(fn ($a) => self::VERBS[$a], $missing)) . '.';
            }
            $out[] = ['area' => $area, 'label' => $def['label'], 'text' => $text, 'actions' => $has];
        }

        return $out;
    }

    private const VERBS = ['ver' => 'ver', 'criar' => 'criar', 'editar' => 'editar', 'aprovar' => 'aprovar', 'apagar' => 'apagar', 'configurar' => 'configurar'];

    private static function join(array $words): string
    {
        if (count($words) <= 1) {
            return (string) ($words[0] ?? '');
        }
        $last = array_pop($words);

        return implode(', ', $words) . ' e ' . $last;
    }
}
