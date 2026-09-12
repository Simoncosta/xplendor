<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DMS Pós-venda — mensagem de envio do link do relatório.
 *
 * FONTE ÚNICA do texto (WhatsApp e email usam o mesmo). Para ajustar o texto
 * padrão no futuro, editar SÓ aqui. O link é construído a partir do APP_URL
 * (o domínio do site — em prod, o mesmo onde o relatório /r/{token} é servido).
 */
class SatisfactionMessage
{
    public static function build(?string $name, string $link): string
    {
        $name = $name !== null ? trim($name) : '';
        $greeting = $name !== '' ? "Olá {$name}!" : 'Olá!';

        return "{$greeting} Parabéns pela sua nova viatura. Preparámos um espaço especial com as fotos e os detalhes da sua compra. Veja aqui: {$link}";
    }

    public static function linkFor(string $token): string
    {
        return rtrim((string) config('app.url'), '/') . '/r/' . $token;
    }
}
