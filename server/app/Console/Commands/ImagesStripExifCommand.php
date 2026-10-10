<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Images\ImageMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pré-deploy, ponto 5: tira o EXIF (e a localização GPS) às imagens que já existem no disco
 * público: as fotografias originais das viaturas e os avatares. SIMULAÇÃO por omissão (mostra
 * quantas têm EXIF e quantas têm GPS). Com --execute, recodifica no mesmo ficheiro e formato,
 * com a orientação aplicada (o GD não copia o EXIF). Idempotente.
 *   php artisan images:strip-exif             → simulação
 *   php artisan images:strip-exif --execute   → limpa
 */
class ImagesStripExifCommand extends Command
{
    protected $signature = 'images:strip-exif {--execute : Limpa de facto (sem isto, só simula)}';

    protected $description = 'Tira o EXIF e a localização GPS às fotografias originais das viaturas e aos avatares.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->line($execute ? 'A limpar os metadados…' : 'SIMULAÇÃO (nada muda). Use --execute para limpar.');
        $paths = [];
        foreach (DB::table('car_images')->whereNotNull('original_path')->pluck('original_path') as $p) {
            $paths[] = ltrim(preg_replace('#^/storage#', '', (string) $p), '/');
        }
        foreach (['users', 'user_invites'] as $table) {
            foreach (DB::table($table)->whereNotNull('avatar')->where('avatar', '!=', '')->pluck('avatar') as $p) {
                $paths[] = ltrim(preg_replace('#^/storage#', '', (string) $p), '/');
            }
        }
        $public = Storage::disk('public');
        $stats = ['imagens' => 0, 'com_exif' => 0, 'com_gps' => 0, 'limpas' => 0, 'em_falta' => 0, 'falhas' => 0];
        foreach (array_unique($paths) as $path) {
            if (! $public->exists($path)) {
                $stats['em_falta']++;
                continue;
            }
            $stats['imagens']++;
            $info = ImageMetadata::inspect($public->path($path));
            if (! $info['exif']) {
                continue;
            }
            $stats['com_exif']++;
            $stats['com_gps'] += $info['gps'] ? 1 : 0;
            if (! $execute) {
                continue;
            }
            try {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $public->put($path, ImageMetadata::strippedBinary((string) $public->get($path), in_array($ext, ['png', 'webp'], true) ? $ext : 'jpg'));
                $stats['limpas']++;
            } catch (\Throwable $e) {
                $stats['falhas']++;
                $this->error("{$path}: " . mb_substr($e->getMessage(), 0, 160));
            }
        }
        $this->table(['Imagens', 'Com EXIF', 'Com GPS', 'Limpas', 'Em falta', 'Falhas'], [array_values($stats)]);

        return $stats['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
