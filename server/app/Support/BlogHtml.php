<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * HTML dos artigos do blog: limpeza por lista de etiquetas permitidas (HTMLPurifier), usada
 * ao GRAVAR e ao MOSTRAR (API da plataforma e API pública), e contas de texto (palavras com
 * acentos, tempo de leitura, marcas "[VERIFICAR]").
 *
 * Permitido: p, br, h2, h3, h4, strong, b, em, i, u, s, blockquote, ul, ol, li e a (href,
 * title, target). Ligações só http, https, mailto e tel. Tudo o resto sai: scripts, estilos,
 * atributos on*, iframes, imagens, formulários. Os h1 passam a h2 (o h1 da página é o título)
 * e as listas do Quill 2 (<ol><li data-list="bullet">) passam a <ul>.
 */
final class BlogHtml
{
    public const ALLOWED = 'p,br,h2,h3,h4,strong,b,em,i,u,s,blockquote,ul,ol,li,a[href|title|target]';

    private static ?\HTMLPurifier $purifier = null;

    public static function sanitize(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        return trim(self::purifier()->purify(self::normalizeEditorMarkup($html)));
    }

    /** Texto simples (sem etiquetas nem entidades), com espaços normalizados. */
    public static function plainText(?string $html): string
    {
        $html = preg_replace('/<\s*(br|\/p|\/li|\/h[1-6]|\/blockquote)\b[^>]*>/i', ' ', (string) $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Conta palavras com letras acentuadas ("Ação", "não", "pós-venda" = 3). */
    public static function wordCount(?string $html): int
    {
        return (int) preg_match_all("/[\\p{L}\\p{N}]+(?:['’\\-][\\p{L}\\p{N}]+)*/u", self::plainText($html));
    }

    /** Minutos de leitura a 200 palavras por minuto (mínimo 1). */
    public static function readTime(?string $html): int
    {
        return max(1, (int) ceil(self::wordCount($html) / 200));
    }

    public static function hasVerifyMarker(?string $text): bool
    {
        return (bool) preg_match('/\[\s*verificar/iu', self::plainText($text));
    }

    // ── internos ─────────────────────────────────────────────────────────────

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }

        $config = \HTMLPurifier_Config::createDefault();
        // Sem cache em disco: evita depender de pastas com escrita (vendor/storage) em produção.
        $config->set('Cache.DefinitionImpl', null);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);

        return self::$purifier = new \HTMLPurifier($config);
    }

    /** h1 → h2; listas do Quill 2 (data-list) → ul/ol verdadeiras; remove os marcadores do editor. */
    private static function normalizeEditorMarkup(string $html): string
    {
        if (! str_contains($html, 'data-list') && ! preg_match('/<h1[\s>]/i', $html)) {
            return $html;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__blog_root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $root = $xpath->query('//*[@id="__blog_root"]')->item(0);
        if (! $root instanceof DOMElement) {
            return $html;
        }

        foreach (iterator_to_array($xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " ql-ui ")]', $root)) as $ui) {
            $ui->parentNode?->removeChild($ui);
        }
        foreach (iterator_to_array($xpath->query('.//h1', $root)) as $h1) {
            self::rename($doc, $h1, 'h2');
        }
        foreach (iterator_to_array($xpath->query('.//ol[li[@data-list]] | .//ul[li[@data-list]]', $root)) as $list) {
            self::splitQuillList($doc, $list);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /** Divide uma lista do Quill em listas seguidas por tipo (bullet → ul, ordered → ol). */
    private static function splitQuillList(DOMDocument $doc, DOMElement $list): void
    {
        $runs = [];
        foreach (iterator_to_array($list->childNodes) as $node) {
            if (! $node instanceof DOMElement || strtolower($node->nodeName) !== 'li') {
                continue;
            }
            $tag = $node->getAttribute('data-list') === 'bullet' ? 'ul' : 'ol';
            $node->removeAttribute('data-list');
            if (! $runs || end($runs)['tag'] !== $tag) {
                $runs[] = ['tag' => $tag, 'items' => []];
            }
            $runs[array_key_last($runs)]['items'][] = $node;
        }

        foreach ($runs as $run) {
            $new = $doc->createElement($run['tag']);
            foreach ($run['items'] as $li) {
                $new->appendChild($li);
            }
            $list->parentNode->insertBefore($new, $list);
        }
        $list->parentNode->removeChild($list);
    }

    private static function rename(DOMDocument $doc, DOMElement $el, string $tag): void
    {
        $new = $doc->createElement($tag);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
    }
}
