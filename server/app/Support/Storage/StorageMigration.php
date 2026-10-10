<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\ExpenseCharge;
use App\Models\MediaAsset;
use App\Models\OcrInvoice;
use App\Models\SatisfactionReportPhoto;
use App\Models\SocialConnectionAccount;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * R2: a migração dos ficheiros existentes para o R2.
 *  · SIMULAÇÃO por omissão: só lista o que seria copiado (nada é escrito).
 *  · RETOMÁVEL e IDEMPOTENTE: cada ficheiro fica em storage_migration_items; o que já está
 *    verificado (e continua igual no destino) não volta a ser copiado.
 *  · CONFIRMA cada ficheiro: o tamanho e o SHA-256 lidos do destino têm de ser iguais aos do local.
 *  · NUNCA apaga o local: isso é o storage:purge-local, à parte.
 *  · Os media da Linha Editorial passam a ler do R2 (media_assets.disk) só quando todos os
 *    ficheiros do asset estão verificados. As fotos das contas, as cobranças, as faturas do OCR,
 *    as faturas dos tickets e as fotografias dos relatórios de satisfação leem do disco
 *    configurado (MEDIA_DISK, PRIVATE_FILES_DISK), que se muda no .env depois. As faturas dos
 *    tickets e as fotografias com o caminho público antigo (/storage/...) ficam de fora: passam
 *    primeiro ao disco privado com o files:make-private.
 */
final class StorageMigration
{
    public const KINDS = ['media', 'avatar', 'cobranca', 'ocr', 'fatura_ticket', 'foto_relatorio'];

    /** Os tipos que leem do PRIVATE_FILES_DISK. */
    private const PRIVATE_KINDS = ['cobranca', 'fatura_ticket', 'foto_relatorio'];

    /** @var array<string, int> */
    public array $stats = ['ficheiros' => 0, 'bytes' => 0, 'copiados' => 0, 'ja_estavam' => 0, 'em_falta' => 0, 'falhas' => 0, 'assets_no_r2' => 0];

    /** @var string[] */
    public array $errors = [];

    public function __construct(private readonly string $target = 'r2') {}

    /**
     * @param string[] $kinds
     * @param callable(string): void|null $progress
     */
    public function run(bool $execute, array $kinds = self::KINDS, ?int $limit = null, ?callable $progress = null): array
    {
        $done = 0;
        foreach ($this->sources($kinds) as [$kind, $sourceDisk, $path, $assetId]) {
            if ($limit !== null && $done >= $limit) {
                break;
            }
            $this->stats['ficheiros']++;
            $result = $this->one($kind, $sourceDisk, $path, $execute);
            if ($result !== 'ja_estava') {
                $done++;
            }
            $progress && $progress("{$kind} {$path}: {$result}");
        }
        if ($execute && in_array('media', $kinds, true)) {
            $this->switchVerifiedAssets();
        }

        return $this->stats;
    }

    /** @return \Generator<array{0: string, 1: string, 2: string, 3: ?int}> */
    private function sources(array $kinds): \Generator
    {
        if (in_array('media', $kinds, true)) {
            foreach (MediaAsset::where('disk', '!=', $this->target)->orderBy('id')->cursor() as $asset) {
                foreach ($this->assetPaths($asset) as $path) {
                    yield ['media', (string) $asset->disk, $path, $asset->id];
                }
            }
        }
        $mediaLocal = 'media';
        if (in_array('avatar', $kinds, true)) {
            foreach (SocialConnectionAccount::whereNotNull('profile_picture_path')->orderBy('id')->cursor() as $a) {
                yield ['avatar', $mediaLocal, (string) $a->profile_picture_path, null];
            }
        }
        if (in_array('cobranca', $kinds, true)) {
            foreach (ExpenseCharge::query()->orderBy('id')->cursor() as $c) {
                foreach (array_filter([$c->invoice_path, $c->proof_path]) as $path) {
                    yield ['cobranca', 'local', (string) $path, null];
                }
            }
        }
        if (in_array('ocr', $kinds, true)) {
            foreach (OcrInvoice::whereNotNull('image_path')->orderBy('id')->cursor() as $o) {
                yield ['ocr', 'local', (string) $o->image_path, null];
            }
        }
        if (in_array('fatura_ticket', $kinds, true)) {
            foreach (SupportTicket::whereNotNull('invoice_path')->where('invoice_path', 'not like', '/storage/%')->orderBy('id')->cursor() as $t) {
                yield ['fatura_ticket', 'local', (string) $t->invoice_path, null];
            }
        }
        if (in_array('foto_relatorio', $kinds, true)) {
            foreach (SatisfactionReportPhoto::where('path', 'not like', '/storage/%')->orderBy('id')->cursor() as $p) {
                yield ['foto_relatorio', 'local', (string) $p->path, null];
            }
        }
    }

    /** @return string[] */
    private function assetPaths(MediaAsset $asset): array
    {
        return array_values(array_filter(array_map(fn ($v) => $asset->pathFor($v), ['original', 'thumb', 'preview', 'poster'])));
    }

    private function one(string $kind, string $sourceDisk, string $path, bool $execute): string
    {
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($this->target);
        $item = DB::table('storage_migration_items')->where(['source_disk' => $sourceDisk, 'target_disk' => $this->target, 'path' => $path])->first();

        if (! $source->exists($path)) {
            $this->stats['em_falta']++;
            $execute && $this->save($item, $kind, $sourceDisk, $path, ['status' => 'em_falta', 'error' => 'O ficheiro local não existe.']);

            return 'em_falta';
        }
        $size = (int) $source->size($path);
        $this->stats['bytes'] += $size;

        if ($item && $item->status === 'verificado' && $target->exists($path) && (int) $target->size($path) === (int) $item->size_bytes && (int) $item->size_bytes === $size) {
            $this->stats['ja_estavam']++;

            return 'ja_estava';
        }
        if (! $execute) {
            return 'a_copiar';
        }

        try {
            $sha = $this->sha256($source, $path);
            $in = $source->readStream($path);
            $target->writeStream($path, $in);
            if (is_resource($in)) {
                fclose($in);
            }
            $remoteSize = (int) $target->size($path);
            $remoteSha = $this->sha256($target, $path);
            if ($remoteSize !== $size || $remoteSha !== $sha) {
                throw new \RuntimeException("O destino não bate com o local (tamanho {$remoteSize}/{$size}).");
            }
            $this->save($item, $kind, $sourceDisk, $path, ['status' => 'verificado', 'size_bytes' => $size, 'sha256' => $sha, 'error' => null, 'verified_at' => now()]);
            $this->stats['copiados']++;

            return 'copiado e verificado';
        } catch (\Throwable $e) {
            $this->stats['falhas']++;
            $this->errors[] = "{$path}: " . mb_substr($e->getMessage(), 0, 200);
            $this->save($item, $kind, $sourceDisk, $path, ['status' => 'falhou', 'size_bytes' => $size, 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'falhou';
        }
    }

    private function sha256(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return hash_final($ctx);
    }

    private function save(?object $item, string $kind, string $sourceDisk, string $path, array $data): void
    {
        $data['updated_at'] = now();
        if ($item) {
            DB::table('storage_migration_items')->where('id', $item->id)->update($data);
        } else {
            DB::table('storage_migration_items')->insert($data + ['kind' => $kind, 'source_disk' => $sourceDisk, 'target_disk' => $this->target, 'path' => $path, 'created_at' => now()]);
        }
    }

    /** Os assets com todos os ficheiros verificados no destino passam a ler de lá (media_assets.disk). */
    private function switchVerifiedAssets(): void
    {
        foreach (MediaAsset::where('disk', '!=', $this->target)->orderBy('id')->cursor() as $asset) {
            $paths = $this->assetPaths($asset);
            if ($paths === []) {
                continue;
            }
            $verified = DB::table('storage_migration_items')->where('target_disk', $this->target)->where('source_disk', $asset->disk)
                ->whereIn('path', $paths)->where('status', 'verificado')->count();
            if ($verified === count($paths)) {
                $asset->forceFill(['disk' => $this->target])->save();
                $this->stats['assets_no_r2']++;
            }
        }
    }

    /**
     * Apaga a cópia LOCAL dos ficheiros verificados (comando à parte). Só quando o destino
     * continua a existir com o mesmo tamanho e, para os media, quando o asset já lê do destino.
     *
     * @return array<string, int>
     */
    public function purgeLocal(bool $execute, ?callable $progress = null): array
    {
        $stats = ['ficheiros' => 0, 'bytes' => 0, 'apagados' => 0, 'mantidos' => 0];
        $items = DB::table('storage_migration_items')->where('target_disk', $this->target)->where('status', 'verificado')->whereNull('local_deleted_at')->orderBy('id')->get();
        foreach ($items as $item) {
            $stats['ficheiros']++;
            $source = Storage::disk($item->source_disk);
            $target = Storage::disk($this->target);
            $ok = $target->exists($item->path) && (int) $target->size($item->path) === (int) $item->size_bytes && LocalCopy::isLocal($source);
            if ($ok && $item->kind === 'media') {
                $ok = MediaAsset::where('disk', $this->target)->where('dir', dirname($item->path))->exists();
            }
            if ($ok && in_array($item->kind, ['avatar'], true)) {
                $ok = (string) config('media.disk') === $this->target; // as fotos só leem do R2 depois de MEDIA_DISK=r2
            }
            if ($ok && in_array($item->kind, self::PRIVATE_KINDS, true)) {
                $ok = (string) config('storage_targets.private_disk') === $this->target;
            }
            if ($ok && $item->kind === 'ocr') {
                $ok = (string) config('services.openai.ocr_disk') === $this->target;
            }
            if (! $ok) {
                $stats['mantidos']++;
                $progress && $progress("{$item->path}: mantido (o destino ainda não está em uso ou não bate)");
                continue;
            }
            $stats['bytes'] += (int) $item->size_bytes;
            if ($execute) {
                $source->delete($item->path);
                DB::table('storage_migration_items')->where('id', $item->id)->update(['local_deleted_at' => now(), 'updated_at' => now()]);
                $stats['apagados']++;
            }
            $progress && $progress("{$item->path}: " . ($execute ? 'apagado' : 'a apagar'));
        }

        return $stats;
    }
}
