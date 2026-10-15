<?php

namespace App\Support\Tracking;

/**
 * XPLENDOR — a regra única de "contacto por WhatsApp" (XP-WA-FIX).
 *
 * Conta como contacto:
 *  - whatsapp_send: o "Enviar" do modal do site;
 *  - whatsapp_click, exceto quando o page_context diz que o clique só abriu o modal
 *    (floating_button, o botão flutuante da Quebom). Esse clique fica gravado, para medir
 *    a passagem de abrir a enviar, mas não é contacto. Os sites que não enviam page_context
 *    contam o whatsapp_click como antes.
 *
 * O page_context é a coluna car_interactions.page_context: vem de data.page_context ou, se
 * só vier aí, de data.meta.page_context (TrackController).
 *
 * Todos os leitores contam o WhatsApp por aqui (isContact em PHP; sql(), contactSql() e
 * countSql() em SQL). A vista car_funnel_metrics_daily é gerada a partir desta regra pela
 * migration 2027_01_07_100000: se a regra mudar, essa vista tem de ser recriada.
 */
final class WhatsappContact
{
    public const CLICK = 'whatsapp_click';

    public const SEND = 'whatsapp_send';

    /** Os tipos de interação de WhatsApp que o /track aceita. */
    public const TYPES = [self::CLICK, self::SEND];

    /** page_context de um clique que só abre o modal: gravado, mas não é contacto. */
    public const OPEN_ONLY_CONTEXTS = ['floating_button'];

    /** Os outros contactos diretos: chamada, mostrar e copiar o telefone. */
    public const PHONE_TYPES = ['call_click', 'show_phone', 'copy_phone'];

    public static function isContact(string $type, ?string $pageContext): bool
    {
        return $type === self::SEND
            || ($type === self::CLICK && ! in_array((string) $pageContext, self::OPEN_ONLY_CONTEXTS, true));
    }

    /** Condição SQL "é contacto por WhatsApp". $prefix é o alias da tabela com ponto (ex.: "ci."). */
    public static function sql(string $prefix = ''): string
    {
        $type = $prefix . 'interaction_type';

        return "({$type} = '" . self::SEND . "' OR ({$type} = '" . self::CLICK . "' AND COALESCE({$prefix}page_context, '') NOT IN ("
            . self::quoted(self::OPEN_ONLY_CONTEXTS) . ')))';
    }

    /** Condição SQL "é contacto direto": WhatsApp pela regra, chamada ou telefone. */
    public static function contactSql(string $prefix = ''): string
    {
        return '(' . self::sql($prefix) . " OR {$prefix}interaction_type IN (" . self::quoted(self::PHONE_TYPES) . '))';
    }

    /** Condição SQL "só abriu o modal" (o whatsapp_click que não é contacto). */
    public static function openOnlySql(string $prefix = ''): string
    {
        return "({$prefix}interaction_type = '" . self::CLICK . "' AND COALESCE({$prefix}page_context, '') IN ("
            . self::quoted(self::OPEN_ONLY_CONTEXTS) . '))';
    }

    /** SUM(...) que conta os contactos por WhatsApp de um grupo. */
    public static function countSql(string $prefix = ''): string
    {
        return 'SUM(CASE WHEN ' . self::sql($prefix) . ' THEN 1 ELSE 0 END)';
    }

    /** @param list<string> $values */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'" . str_replace("'", "''", $v) . "'", $values));
    }
}
