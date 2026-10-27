<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/**
 * Qual é a pasta de Enviados, a partir da resposta do LIST (nome → marcas): primeiro a marca
 * especial \Sent (RFC 6154); sem ela, os nomes habituais (cPanel/Dovecot, Outlook, português).
 */
final class SentFolder
{
    private const NAMES = ['Sent', 'INBOX.Sent', 'INBOX/Sent', 'Sent Items', 'Sent Messages', 'Enviados', 'INBOX.Enviados', 'Itens Enviados', 'Mensagens Enviadas'];

    /** @param array<string, list<string>> $folders */
    public static function find(array $folders): ?string
    {
        foreach ($folders as $name => $flags) {
            foreach ((array) $flags as $flag) {
                if (strcasecmp((string) $flag, '\\Sent') === 0) {
                    return (string) $name;
                }
            }
        }
        $byLower = [];
        foreach (array_keys($folders) as $name) {
            $byLower[strtolower((string) $name)] = (string) $name;
        }
        foreach (self::NAMES as $candidate) {
            if (isset($byLower[strtolower($candidate)])) {
                return $byLower[strtolower($candidate)];
            }
        }

        return null;
    }
}
