<?php

declare(strict_types=1);

namespace App\Services\ConsultantSite;

use App\Models\ConsultantSite;
use App\Services\Files\FileTypeGuard;
use App\Services\Files\SystemFileCatalog;
use App\Support\Storage\PublicUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Site do consultor: os ficheiros do perfil (a app e o comando consultor:site-asset usam este
 * mesmo serviço). No disco público (o site é público) e registados nos Ficheiros (pasta "Site do
 * consultor"). As imagens são recodificadas (sem EXIF nem localização) no tamanho de cada uso;
 * a assinatura SVG passa pelo FileTypeGuard e só aceita contornos (sem texto nem fontes); os
 * vídeos são confirmados pelo conteúdo.
 */
class ConsultantSiteAssets
{
    /** slot => [coluna, extensões aceites, tamanho máximo em KB] */
    public const SLOTS = [
        'signature' => ['signature_svg_path', ['svg'], 512],
        'photo' => ['photo_cutout_path', ['png', 'webp'], 10240],
        'about_photo' => ['about_photo_path', ['jpg', 'jpeg', 'png', 'webp'], 10240],
        'hero_video_mp4' => ['hero_video_mp4_path', ['mp4'], 10240],
        'hero_video_webm' => ['hero_video_webm_path', ['webm'], 10240],
        'hero_poster' => ['hero_poster_path', ['jpg', 'jpeg', 'webp', 'png'], 10240],
        'network_logo' => ['network_logo_path', ['svg', 'png', 'webp'], 2048],
        'credit_partner_logo' => ['credit_partner_logo_path', ['svg', 'png', 'webp'], 2048],
    ];

    /** Os ficheiros que nascem de outro (e saem com ele): slot => [coluna => descrição]. */
    private const DERIVED = [
        'about_photo' => ['share_image_path' => 'imagem de partilha'],
        'hero_poster' => ['hero_poster_960_path' => 'capa de 960 px'],
    ];

    /** O tamanho de cada imagem no site (largura e altura máximas, em px, sem aumentar). */
    private const SIZES = [
        'photo' => [1400, 1800],        // o recorte do topo (com transparência)
        'about_photo' => [1400, 2000],  // a página Sobre
        'hero_poster' => [1920, 1080],  // a capa do vídeo do topo
        'network_logo' => [800, 400],
        'credit_partner_logo' => [800, 400],
    ];

    /** @return array{path: string, width: ?int, height: ?int, bytes: int, share_image: ?string} */
    public function store(ConsultantSite $site, string $slot, string $localPath, string $originalName): array
    {
        if (! isset(self::SLOTS[$slot])) {
            throw ValidationException::withMessages(['file' => 'Ficheiro desconhecido.']);
        }
        [$column, $extensions, $maxKb] = self::SLOTS[$slot];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, $extensions, true)) {
            throw ValidationException::withMessages(['file' => 'Aceites: ' . implode(', ', $extensions) . '.']);
        }
        if ((int) @filesize($localPath) > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => 'O ficheiro pode ter até ' . max(1, intdiv($maxKb, 1024)) . ' MB.']);
        }
        $this->checkContent($localPath, $ext);

        $disk = Storage::disk('public');
        $dir = "company_{$site->company_id}/consultor-site";
        $base = "{$slot}-" . Str::lower(Str::random(10));
        $width = $height = null;
        $share = null;
        $extra = []; // coluna => caminho dos ficheiros derivados (a imagem de partilha, a capa de 960 px)
        if (in_array($ext, ['svg', 'mp4', 'webm'], true)) {
            $path = "{$dir}/{$base}.{$ext}";
            $disk->put($path, (string) file_get_contents($localPath));
        } else {
            // Recodificada: sem EXIF nem GPS, com a orientação aplicada, no tamanho do uso.
            $image = (new ImageManager(new Driver()))->read($localPath)->orient();
            [$maxW, $maxH] = self::SIZES[$slot];
            $image->scaleDown(width: $maxW, height: $maxH);
            [$width, $height] = [$image->width(), $image->height()];
            $path = "{$dir}/{$base}.webp";
            $disk->put($path, $image->toWebp(84)->toString());
            if ($slot === 'about_photo') {
                // A imagem de partilha (Open Graph): 1200 × 630, JPEG (o formato que todas as redes leem).
                $og = (new ImageManager(new Driver()))->read($localPath)->orient()->cover(1200, 630, 'top');
                $share = "{$dir}/partilha-" . Str::lower(Str::random(10)) . '.jpg';
                $disk->put($share, $og->toJpeg(84)->toString());
                $extra['share_image_path'] = $share;
            }
            if ($slot === 'hero_poster') {
                // A versão do telemóvel (960 px), para não carregar a de 1920 px num ecrã pequeno.
                $small = (new ImageManager(new Driver()))->read($localPath)->orient()->scaleDown(width: 960, height: 960);
                $extra['hero_poster_960_path'] = "{$dir}/{$base}-960.webp";
                $disk->put($extra['hero_poster_960_path'], $small->toWebp(80)->toString());
            }
        }

        $old = array_filter([$site->{$column}, ...array_map(fn ($c) => $site->{$c}, array_keys(self::DERIVED[$slot] ?? []))]);
        $site->forceFill([$column => PublicUrl::path($path)] + array_map(fn ($p) => PublicUrl::path((string) $p), $extra))->save();
        foreach ($old as $o) {
            $disk->delete(ltrim((string) preg_replace('#^/storage/#', '', (string) $o), '/'));
        }
        app(SystemFileCatalog::class)->sync((int) $site->company_id, true); // nos Ficheiros, já

        return ['path' => PublicUrl::path($path), 'width' => $width, 'height' => $height, 'bytes' => (int) $disk->size($path),
            'share_image' => $share ? PublicUrl::path($share) : null];
    }

    public function delete(ConsultantSite $site, string $slot): void
    {
        $column = self::SLOTS[$slot][0];
        $derived = array_keys(self::DERIVED[$slot] ?? []);
        $paths = array_filter([$site->{$column}, ...array_map(fn ($c) => $site->{$c}, $derived)]);
        foreach ($paths as $p) {
            Storage::disk('public')->delete(ltrim((string) preg_replace('#^/storage/#', '', (string) $p), '/'));
        }
        $site->forceFill([$column => null] + array_fill_keys($derived, null))->save();
        app(SystemFileCatalog::class)->sync((int) $site->company_id, true);
    }

    /**
     * Uma fotografia de uma zona de atuação (fundo ou separador do site): recodificada em WebP,
     * em 2400 e 960 px, sem EXIF (o GPS das fotografias de rua sai sempre: a recodificação não
     * leva metadados, e confirma-se no ficheiro final). Até MAX_ZONE_PHOTOS.
     *
     * @return array{id: string, path: string, path_960: string, width: int, height: int, alt: ?string, parish_id: ?int}
     */
    public function storeZonePhoto(ConsultantSite $site, string $localPath, string $originalName, ?string $alt = null, ?int $parishId = null): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw ValidationException::withMessages(['file' => 'Aceites: jpg, jpeg, png, webp.']);
        }
        if ((int) @filesize($localPath) > 15 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'O ficheiro pode ter até 15 MB.']);
        }
        if (count($site->zonePhotos()) >= ConsultantSite::MAX_ZONE_PHOTOS) {
            throw ValidationException::withMessages(['file' => 'O site leva até ' . ConsultantSite::MAX_ZONE_PHOTOS . ' fotografias das zonas.']);
        }
        $this->checkContent($localPath, $ext);

        $disk = Storage::disk('public');
        $id = Str::lower(Str::random(10));
        $dir = "company_{$site->company_id}/consultor-site/zonas";
        $out = [];
        foreach ([2400 => "{$dir}/zona-{$id}.webp", 960 => "{$dir}/zona-{$id}-960.webp"] as $max => $path) {
            $image = (new ImageManager(new Driver()))->read($localPath)->orient();
            $image->scaleDown(width: $max, height: $max);
            $bytes = $image->toWebp(82)->toString();
            if (str_contains($bytes, 'EXIF') || str_contains($bytes, 'XMP ')) {
                throw ValidationException::withMessages(['file' => 'Não foi possível tirar os metadados da fotografia.']);
            }
            $disk->put($path, $bytes);
            $out[$max] = [$path, $image->width(), $image->height()];
        }
        $photo = ['id' => $id, 'path' => PublicUrl::path($out[2400][0]), 'path_960' => PublicUrl::path($out[960][0]),
            'width' => $out[2400][1], 'height' => $out[2400][2], 'alt' => $alt !== null && trim($alt) !== '' ? trim($alt) : null, 'parish_id' => $parishId];
        $site->forceFill(['zone_photos' => [...$site->zonePhotos(), $photo]])->save();
        app(SystemFileCatalog::class)->sync((int) $site->company_id, true);

        return $photo;
    }

    /** @return bool falso quando a fotografia não existe neste site */
    public function deleteZonePhoto(ConsultantSite $site, string $id): bool
    {
        $keep = [];
        $found = false;
        foreach ($site->zonePhotos() as $p) {
            if ($p['id'] !== $id) {
                $keep[] = $p;
                continue;
            }
            $found = true;
            foreach ([$p['path'], $p['path_960'] ?? null] as $path) {
                Storage::disk('public')->delete(ltrim((string) preg_replace('#^/storage/#', '', (string) $path), '/'));
            }
        }
        if (! $found) {
            return false;
        }
        $site->forceFill(['zone_photos' => $keep])->save();
        app(SystemFileCatalog::class)->sync((int) $site->company_id, true);

        return true;
    }

    private function checkContent(string $path, string $ext): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 64);
        $ok = match ($ext) {
            'svg' => (bool) FileTypeGuard::document($path, 'ficheiro.svg') && $this->onlyOutlines($path),
            'png' => str_starts_with($head, "\x89PNG"),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'mp4' => substr($head, 4, 4) === 'ftyp',
            'webm' => str_starts_with($head, "\x1A\x45\xDF\xA3"),
            default => false,
        };
        if (! $ok) {
            throw ValidationException::withMessages(['file' => "O conteúdo do ficheiro não corresponde a um .{$ext}."]);
        }
    }

    /** O SVG só com contornos: sem texto, sem fontes e sem imagens ou ligações externas. */
    private function onlyOutlines(string $path): bool
    {
        $svg = (string) file_get_contents($path);
        if (preg_match('/<(text|tspan|textPath|font|font-face|image|use|style)\b|font-family|@font-face|@import|url\(\s*["\']?https?:|xlink:href\s*=\s*["\']?https?:|\shref\s*=\s*["\']?https?:/i', $svg)) {
            throw ValidationException::withMessages(['file' => 'O SVG tem texto, fontes ou referências externas: exporte-o só com contornos (no Figma, "Outline stroke" e "Flatten").']);
        }

        return true;
    }
}
