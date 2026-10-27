<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/**
 * Os servidores de uma caixa têm de ser servidores de e-mail na internet: um nome com domínio
 * (nada de "db", "redis", "localhost" nem endereços IP escritos à mão) e, no envio real, um nome
 * que resolve para endereços públicos. Assim uma caixa não serve para a plataforma ligar a
 * serviços internos.
 */
final class MailboxHosts
{
    private const BLOCKED_SUFFIXES = ['.local', '.localhost', '.internal', '.lan', '.home', '.arpa'];

    /** O nome escrito pela pessoa é aceitável? (sem ir à rede) */
    public static function acceptable(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return false;
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /** O primeiro endereço público para onde o nome resolve (null se não resolver ou se algum for privado). */
    public static function publicAddress(string $host): ?string
    {
        if (! self::acceptable($host)) {
            return null;
        }
        $ips = gethostbynamel(strtolower(trim($host)));
        if (! $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $ips[0];
    }
}
