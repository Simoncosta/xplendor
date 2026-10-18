<?php

declare(strict_types=1);

namespace App\Support\ConsultantSite;

/**
 * A ligação para a página do imóvel no site da rede: só https e só os hosts configurados, por
 * igualdade (chavenova.com e www.chavenova.com; nenhum outro subdomínio), sem utilizador nem porta.
 */
final class ChaveNovaUrl
{
    public static function isAllowed(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return true;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        foreach ((array) config('consultor_site.chave_nova_domains') as $domain) {
            $domain = strtolower((string) $domain);
            if ($domain !== '' && $host === $domain) {
                return true;
            }
        }

        return false;
    }
}
