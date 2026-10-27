<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use DOMDocument;
use DOMElement;

/**
 * E-mail 4 · O HTML de uma mensagem recebida, limpo antes de chegar ao ecrã: lista de etiquetas e
 * de estilos permitidos (HTMLPurifier), sem scripts, eventos, formulários, iframes nem javascript:,
 * as ligações abrem num separador novo (noopener, noreferrer) e as imagens externas ficam
 * bloqueadas (o src passa a data-xp-src) até a pessoa carregar em "Mostrar imagens". As imagens
 * anexadas (cid:) entram como data: (só PNG, JPEG, GIF e WebP até 1 MB). O ecrã mostra o resultado
 * num iframe isolado (sandbox, sem scripts) com uma CSP rígida.
 */
final class MailHtml
{
    private const ALLOWED = 'a[href|title|target],abbr,b,blockquote,br,caption,center,cite,code,col,colgroup,dd,del,div,dl,dt,em,font[color|size|face],'
        . 'h1,h2,h3,h4,h5,h6,hr,i,img[src|alt|width|height|title],ins,li,ol,p,pre,q,s,small,span,strike,strong,sub,sup,'
        . 'table[width|border|cellpadding|cellspacing|align|bgcolor],tbody,td[width|height|align|valign|colspan|rowspan|bgcolor],tfoot,'
        . 'th[width|height|align|valign|colspan|rowspan|bgcolor],thead,tr,u,ul,*[style|class|dir|align]';

    private const CSS = ['color', 'background-color', 'font-size', 'font-weight', 'font-style', 'font-family', 'text-align', 'text-decoration',
        'line-height', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'padding', 'padding-top', 'padding-bottom',
        'padding-left', 'padding-right', 'border', 'border-top', 'border-bottom', 'border-left', 'border-right', 'border-collapse',
        'width', 'height', 'vertical-align', 'white-space'];

    public const INLINE_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const INLINE_MAX_BYTES = 1_048_576;

    private static ?\HTMLPurifier $purifier = null;

    /**
     * @param  array<string, array{mime: string, content: string}>  $cid  as imagens anexadas, pelo Content-ID (sem < >)
     * @return array{html: string, external_images: int}
     */
    public static function clean(string $html, array $cid = []): array
    {
        $html = preg_replace_callback('/(["\'])cid:([^"\']+)\1/i', function ($m) use ($cid) {
            $image = $cid[trim($m[2], '<>')] ?? null;
            if ($image && in_array(strtolower($image['mime']), self::INLINE_IMAGE_TYPES, true) && strlen($image['content']) <= self::INLINE_MAX_BYTES) {
                return $m[1] . 'data:' . strtolower($image['mime']) . ';base64,' . base64_encode($image['content']) . $m[1];
            }

            return $m[1] . $m[1];
        }, $html) ?? '';

        $clean = self::purifier()->purify($html);
        if (trim($clean) === '') {
            return ['html' => '', 'external_images' => 0];
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="xp-mail">' . $clean . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $external = 0;
        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('src');
            if (preg_match('#^https?://#i', $src)) {
                $img->setAttribute('data-xp-src', $src);
                $img->removeAttribute('src');
                $external++;
            }
        }
        $root = $doc->getElementById('xp-mail') ?? $doc->documentElement;
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return ['html' => $out, 'external_images' => $external];
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('CSS.AllowedProperties', self::CSS);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        return self::$purifier = new \HTMLPurifier($config);
    }
}
