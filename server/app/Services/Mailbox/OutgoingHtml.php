<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/**
 * E-mail 5 · O HTML que a pessoa escreve no editor (Quill) antes de sair pela caixa: só texto e
 * ligações (parágrafos, negrito, itálico, sublinhado, listas, citações e ligações http, https e
 * mailto), sem imagens nem anexos (versão 1), e a versão em texto simples da mesma mensagem.
 */
final class OutgoingHtml
{
    private static ?\HTMLPurifier $purifier = null;

    public static function clean(string $html): string
    {
        return trim(self::purifier()->purify(self::quillLists($html)));
    }

    /**
     * O Quill 2 guarda todas as listas como <ol> e marca cada item com data-list="bullet" ou
     * "ordered" (com um <span class="ql-ui"> para o marcador). Para o e-mail: <ul> e <ol> a sério,
     * separando os itens seguidos de tipos diferentes.
     */
    public static function quillLists(string $html): string
    {
        if (! str_contains($html, 'data-list=')) {
            return $html;
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="xp-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " ql-ui ")]')) as $ui) {
            $ui->parentNode->removeChild($ui);
        }
        foreach (iterator_to_array($xpath->query('//ol[li[@data-list]]')) as $ol) {
            $current = null;
            $type = null;
            foreach (iterator_to_array($ol->childNodes) as $li) {
                if (! $li instanceof \DOMElement || $li->nodeName !== 'li') {
                    continue;
                }
                $itemType = $li->getAttribute('data-list') === 'bullet' ? 'ul' : 'ol';
                if ($current === null || $itemType !== $type) {
                    $current = $doc->createElement($itemType);
                    $ol->parentNode->insertBefore($current, $ol);
                    $type = $itemType;
                }
                $li->removeAttribute('data-list');
                $current->appendChild($li);
            }
            $ol->parentNode->removeChild($ol);
        }
        $root = $doc->getElementById('xp-root');
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /** Texto simples: parágrafos e quebras de linha, listas com "- " e as ligações como "texto (endereço)". */
    public static function toText(string $html): string
    {
        $html = preg_replace_callback('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[2]));
            $href = html_entity_decode($m[1]);

            return $label === '' || $label === $href ? $href : "{$label} ({$href})";
        }, $html) ?? $html;
        $html = preg_replace(['#<br\s*/?>#i', '#</p>#i', '#<li[^>]*>#i', '#</(li|ul|ol|blockquote|div|h\d)>#i'], ["\n", "\n\n", '- ', "\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,ol,ul,li,blockquote,a[href]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        return self::$purifier = new \HTMLPurifier($config);
    }
}
