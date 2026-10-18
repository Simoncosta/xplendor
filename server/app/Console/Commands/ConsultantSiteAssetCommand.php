<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConsultantSite;
use App\Services\ConsultantSite\ConsultantSiteAssets;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Site do consultor: carrega um ficheiro do perfil (o mesmo serviço da app: verificação pelo
 * conteúdo, sem EXIF, no tamanho do uso, registado nos Ficheiros). Para a equipa e para o dev.
 *
 *   php artisan consultor:site-asset 51 signature /tmp/assinatura.svg
 *   php artisan consultor:site-asset 51 zone_photo /tmp/rua.jpg --alt="Rua de Ramalde" --parish=123
 */
class ConsultantSiteAssetCommand extends Command
{
    protected $signature = 'consultor:site-asset {company : ID da empresa} {slot : ' . 'signature|photo|about_photo|hero_video_mp4|hero_video_webm|hero_poster|network_logo|credit_partner_logo|zone_photo} {file : caminho do ficheiro}
        {--alt= : zone_photo: a descrição da fotografia}
        {--parish= : zone_photo: o ID da freguesia}';

    protected $description = 'Site do consultor: carrega um ficheiro do perfil do site (o mesmo serviço da app).';

    public function handle(ConsultantSiteAssets $assets): int
    {
        $site = ConsultantSite::where('company_id', (int) $this->argument('company'))->first();
        $file = (string) $this->argument('file');
        if (! $site || ! is_file($file)) {
            $this->error('Empresa sem perfil do site, ou ficheiro inexistente.');

            return self::FAILURE;
        }
        if ($this->argument('slot') === 'zone_photo') {
            try {
                $p = $assets->storeZonePhoto($site, $file, basename($file), $this->option('alt') ?: null, $this->option('parish') ? (int) $this->option('parish') : null);
            } catch (ValidationException $e) {
                $this->error(collect($e->errors())->flatten()->first() ?? 'Ficheiro recusado.');

                return self::FAILURE;
            }
            $this->info("Guardada: {$p['path']} ({$p['width']} × {$p['height']} px), sem EXIF nem GPS.");

            return self::SUCCESS;
        }
        try {
            $r = $assets->store($site, (string) $this->argument('slot'), $file, basename($file));
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first() ?? 'Ficheiro recusado.');

            return self::FAILURE;
        }
        $this->info("Guardado: {$r['path']}" . ($r['width'] ? " ({$r['width']} × {$r['height']} px)" : '') . ', ' . round($r['bytes'] / 1024) . ' KB.'
            . ($r['share_image'] ? " Imagem de partilha: {$r['share_image']}." : ''));

        return self::SUCCESS;
    }
}
