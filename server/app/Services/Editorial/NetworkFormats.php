<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\MediaAsset;
use Illuminate\Support\Collection;

/**
 * Formatos das redes: UMA só regra para o servidor e para o ecrã (o ecrã recebe esta
 * tabela pela API). Documentada em documents/SOCIAL-SPIKE.md, "Formatos por rede".
 *
 *  · Cada rede só aceita os seus formatos (um formato que não existe na rede é erro).
 *  · Os ficheiros de cada rede validam-se pelo formato dessa rede (rules()).
 *  · Equivalentes entre redes: a mesma publicação sai com uma forma diferente noutra rede;
 *    é um aviso (EQUIVALENTS).
 *  · Inexistentes: o conteúdo não tem forma possível na outra rede (por exemplo, um
 *    carrossel com vídeos não tem equivalente no Facebook, que só junta fotografias); é
 *    um erro, dito pelas regras do formato dessa rede.
 *
 * Resolve a contradição do desenho (SOCIAL-SPIKE-F0-F1-F2 3.6, "Reels não entram"): o
 * carrossel do Instagram aceita vídeos como itens (até 60 s), não aceita Reels; e a
 * publicação com várias fotografias do Facebook só aceita imagens.
 */
class NetworkFormats
{
    public const NETWORKS = ['instagram', 'facebook'];

    public const LABELS = [
        'ig_feed_image' => 'Imagem (feed)',
        'ig_carousel'   => 'Carrossel',
        'ig_reel'       => 'Reel (vídeo)',
        'ig_story'      => 'Story',
        'fb_post'       => 'Publicação (texto ou ligação)',
        'fb_photos'     => 'Fotografias',
        'fb_video'      => 'Vídeo',
        'fb_reel'       => 'Reel',
        'fb_story'      => 'Story',
    ];

    public const FORMATS = [
        'instagram' => ['ig_feed_image', 'ig_carousel', 'ig_reel', 'ig_story'],
        'facebook'  => ['fb_post', 'fb_photos', 'fb_video', 'fb_reel', 'fb_story'],
    ];

    public const NETWORK_LABELS = ['instagram' => 'Instagram', 'facebook' => 'Facebook'];

    /** Formato sugerido na outra rede, quando se junta uma rede (o mais próximo). */
    public const SUGGEST = [
        'ig_feed_image' => 'fb_photos', 'ig_carousel' => 'fb_photos', 'ig_reel' => 'fb_reel', 'ig_story' => 'fb_story',
        'fb_post' => 'ig_feed_image', 'fb_photos' => 'ig_carousel', 'fb_video' => 'ig_reel', 'fb_reel' => 'ig_reel', 'fb_story' => 'ig_story',
    ];

    /**
     * Equivalentes (aviso): par de formatos (Instagram, Facebook) que dá a mesma publicação
     * com uma forma diferente. Os pares que não estão aqui são iguais nas duas redes ou não
     * pedem aviso; os impossíveis aparecem como erro nas regras do formato.
     */
    public const EQUIVALENTS = [
        'ig_carousel|fb_photos' => 'No Facebook, o carrossel sai como uma publicação com várias fotografias (não desliza).',
        'ig_feed_image|fb_post' => 'No Facebook, a imagem acompanha uma publicação de texto.',
        'ig_reel|fb_video' => 'No Facebook, o Reel sai como vídeo normal, não como Reel.',
        'ig_feed_image|fb_photos' => null,
        'ig_reel|fb_reel' => 'O Reel do Facebook tem de ter até 90 segundos.',
        'ig_story|fb_story' => null,
    ];

    /** Pares que não fazem sentido (formas diferentes demais): erro. */
    public const INCOMPATIBLE = [
        'ig_story|fb_post' => 'Uma Story do Instagram não tem equivalente numa publicação de texto do Facebook. Escolha "Story" no Facebook.',
        'ig_carousel|fb_video' => 'Um carrossel não tem equivalente num vídeo do Facebook. Escolha "Fotografias" no Facebook.',
        'ig_carousel|fb_reel' => 'Um carrossel não tem equivalente num Reel do Facebook. Escolha "Fotografias" no Facebook.',
        'ig_reel|fb_photos' => 'Um Reel não tem equivalente em fotografias do Facebook. Escolha "Reel" ou "Vídeo" no Facebook.',
        'ig_feed_image|fb_video' => 'Uma imagem não tem equivalente num vídeo do Facebook. Escolha "Fotografias" no Facebook.',
        'ig_feed_image|fb_reel' => 'Uma imagem não tem equivalente num Reel do Facebook. Escolha "Fotografias" no Facebook.',
    ];

    public static function networkOf(string $format): ?string
    {
        foreach (self::FORMATS as $network => $formats) {
            if (in_array($format, $formats, true)) {
                return $network;
            }
        }

        return null;
    }

    public static function exists(string $network, ?string $format): bool
    {
        return $format === null || in_array($format, self::FORMATS[$network] ?? [], true);
    }

    /** Mensagem de erro de um formato que não existe na rede. */
    public static function notInNetwork(string $network, string $format): string
    {
        $label = self::LABELS[$format] ?? $format;

        return 'O formato "' . $label . '" não existe no ' . self::NETWORK_LABELS[$network] . '. Escolha um dos formatos do ' . self::NETWORK_LABELS[$network] . '.';
    }

    /**
     * Erros e avisos entre redes (Instagram e Facebook escolhidos ao mesmo tempo).
     *
     * @param  array<string, ?string>  $formats  rede → formato
     * @return array{errors: string[], warnings: string[]}
     */
    public static function crossCheck(array $formats): array
    {
        $ig = $formats['instagram'] ?? null;
        $fb = $formats['facebook'] ?? null;
        if (! $ig || ! $fb) {
            return ['errors' => [], 'warnings' => []];
        }
        $key = "{$ig}|{$fb}";
        if (isset(self::INCOMPATIBLE[$key])) {
            return ['errors' => [self::INCOMPATIBLE[$key]], 'warnings' => []];
        }

        return ['errors' => [], 'warnings' => array_values(array_filter([self::EQUIVALENTS[$key] ?? null]))];
    }

    /**
     * Regras dos ficheiros de UM formato (erros). Os avisos de proporção e de codec
     * ficam em warnings().
     *
     * @param  Collection<int, MediaAsset>  $items
     * @return string[]
     */
    public static function rules(string $format, Collection $items): array
    {
        $n = $items->count();
        $images = $items->where('kind', MediaAsset::IMAGE)->count();
        $videos = $items->where('kind', MediaAsset::VIDEO)->count();
        $video = $items->firstWhere('kind', MediaAsset::VIDEO);
        $sec = $video ? $video->duration_ms / 1000 : 0;
        $one = fn (string $what) => $n !== 1 ? "Este formato leva {$what}." : null;

        return array_values(array_filter(match ($format) {
            'ig_feed_image' => [$one('uma imagem'), $videos ? 'Este formato leva uma imagem, não vídeo.' : null,
                $n === 1 && $images && ($items[0]->ratio() < 0.8 || $items[0]->ratio() > 1.91) ? 'A proporção tem de estar entre 4:5 (vertical) e 1,91:1 (horizontal).' : null],
            // Carrossel: imagens e vídeos (até 60 s cada), nunca Reels.
            'ig_carousel' => [$n < 2 || $n > 10 ? 'O carrossel leva entre 2 e 10 ficheiros.' : null,
                $items->first(fn ($a) => $a->kind === MediaAsset::VIDEO && $a->duration_ms > 60000) ? 'Os vídeos do carrossel podem ter até 60 segundos.' : null],
            'ig_reel' => [$one('um vídeo'), $images ? 'Um Reel leva um vídeo.' : null,
                $video && ($sec < 3 || $sec > 900) ? 'Um Reel tem de ter entre 3 segundos e 15 minutos.' : null],
            'ig_story', 'fb_story' => [$one('uma imagem ou um vídeo'),
                $video && $sec > 60 ? 'Os vídeos das Stories podem ter até 60 segundos.' : null],
            'fb_post' => [$n > 1 ? 'Uma publicação de texto ou ligação leva no máximo uma imagem.' : null,
                $videos ? 'Para vídeo, escolha "Vídeo" ou "Reel".' : null],
            // Várias fotografias: só imagens. Um carrossel com vídeos não tem equivalente aqui.
            'fb_photos' => [$n < 1 || $n > 10 ? 'Leva entre 1 e 10 fotografias.' : null,
                $videos ? 'O Facebook não junta vídeos com fotografias: retire os vídeos ou escolha "Vídeo".' : null],
            'fb_video' => [$one('um vídeo'), $images ? 'Este formato leva um vídeo.' : null],
            'fb_reel' => [$one('um vídeo'), $images ? 'Um Reel leva um vídeo.' : null,
                $video && ($sec < 3 || $sec > 90) ? 'Um Reel do Facebook tem de ter entre 3 e 90 segundos.' : null],
            default => [],
        }));
    }

    /** @param Collection<int, MediaAsset> $items @return string[] */
    public static function warnings(string $format, Collection $items, bool $hasCover): array
    {
        $n = $items->count();
        $isVertical = fn (MediaAsset $a) => $a->ratio() !== null && abs($a->ratio() - 9 / 16) < 0.02;
        $out = [];
        if (in_array($format, ['ig_reel', 'ig_story', 'fb_reel', 'fb_story'], true) && $n === 1 && ! $isVertical($items[0])) {
            $out[] = 'Recomendado 9:16 (vertical, 1080 x 1920). Fora disso a rede corta ou põe margens.';
        }
        if ($format === 'ig_carousel' && $n >= 2) {
            $out[] = 'No carrossel, todos os ficheiros são cortados pela proporção do primeiro.';
        }
        if ($hasCover && ! in_array($format, ['ig_reel', 'fb_reel', 'fb_video'], true)) {
            $out[] = 'A capa só é usada em Reels e vídeos.';
        }

        return $out;
    }

    /** A tabela para o ecrã (a mesma regra). */
    public static function table(): array
    {
        return [
            'networks' => self::NETWORK_LABELS,
            'formats' => collect(self::FORMATS)->map(fn ($list) => array_map(fn ($f) => ['value' => $f, 'label' => self::LABELS[$f]], $list))->all(),
            'suggest' => self::SUGGEST,
            'equivalents' => array_filter(self::EQUIVALENTS),
            'incompatible' => self::INCOMPATIBLE,
        ];
    }
}
