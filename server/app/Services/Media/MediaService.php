<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Jobs\ProcessMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\Editorial\EditorialWorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Media da Linha Editorial: envio em partes de 8 MB (retomável), deduplicação por SHA-256
 * na empresa, quota por empresa e processamento em fila (miniatura 320 e pré-visualização
 * 1080 em WebP, capa e dados técnicos do vídeo). Tudo no disco privado "media".
 */
class MediaService
{
    public function __construct(private readonly MediaProbe $probe) {}

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('media.disk', 'media'));
    }

    // ── Quota ────────────────────────────────────────────────────────────────

    public static function usedBytes(int $companyId): int
    {
        return (int) MediaAsset::where('company_id', $companyId)->whereNull('original_deleted_at')
            ->where('status', '!=', MediaAsset::REJECTED)->sum('size_bytes');
    }

    public static function quotaBytes(): int
    {
        return (int) config('media.company_quota_mb', 3072) * 1024 * 1024;
    }

    // ── Envio em partes ──────────────────────────────────────────────────────

    public function start(int $companyId, User $user, string $name, int $size, string $mime): MediaUpload
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $kind = match (true) {
            in_array($ext, config('media.image_extensions'), true) && in_array($mime, config('media.image_mimes'), true) => MediaAsset::IMAGE,
            in_array($ext, config('media.video_extensions'), true) && in_array($mime, config('media.video_mimes'), true) => MediaAsset::VIDEO,
            default => null,
        };
        if (! $kind) {
            throw ValidationException::withMessages(['file' => ['Formato não aceite. Imagens: JPEG, PNG ou WebP. Vídeos: MP4 ou MOV.']]);
        }
        $max = (int) config($kind === MediaAsset::IMAGE ? 'media.max_image_bytes' : 'media.max_video_bytes');
        if ($size <= 0 || $size > $max) {
            throw ValidationException::withMessages(['file' => [$kind === MediaAsset::IMAGE
                ? 'As imagens podem ter até ' . intdiv($max, 1048576) . ' MB.'
                : 'Os vídeos podem ter até ' . intdiv($max, 1048576) . ' MB.']]);
        }
        if (self::usedBytes($companyId) + $size > self::quotaBytes()) {
            $quota = self::quotaBytes();
            $label = $quota >= 1073741824 && $quota % 1073741824 === 0 ? intdiv($quota, 1073741824) . ' GB' : intdiv($quota, 1048576) . ' MB';
            throw ValidationException::withMessages(['file' => ["O espaço de media da empresa está cheio ({$label}). Contacte a equipa XPLENDOR."]]);
        }

        return MediaUpload::create([
            'company_id' => $companyId, 'user_id' => $user->id, 'impersonator_user_id' => EditorialWorkflowService::impersonatorId($user),
            'kind' => $kind, 'original_name' => mb_substr($name, 0, 200), 'extension' => $ext === 'jpeg' ? 'jpg' : $ext,
            'mime' => $mime, 'size_bytes' => $size, 'received_bytes' => 0,
            'expires_at' => now()->addHours((int) config('media.retention.upload_session_hours', 24)),
        ]);
    }

    /**
     * Acrescenta uma parte. Tem de começar exatamente em received_bytes (senão 409 com o
     * valor certo, para o ecrã retomar dali). Na última parte, conclui o envio.
     */
    public function appendChunk(MediaUpload $upload, int $offset, UploadedFile $chunk): MediaUpload
    {
        return DB::transaction(function () use ($upload, $offset, $chunk) {
            $upload = MediaUpload::lockForUpdate()->findOrFail($upload->id);
            if ($upload->completed_at) {
                return $upload;
            }
            if ($upload->expires_at && $upload->expires_at->isPast()) {
                throw new HttpException(410, 'Este envio expirou. Volte a escolher o ficheiro.');
            }
            if ($offset !== $upload->received_bytes) {
                throw new HttpException(409, 'received_bytes=' . $upload->received_bytes);
            }
            $bytes = (int) $chunk->getSize();
            $chunkMax = (int) config('media.chunk_bytes');
            $remaining = $upload->size_bytes - $upload->received_bytes;
            if ($bytes <= 0 || $bytes > $chunkMax || $bytes > $remaining || ($bytes < $chunkMax && $bytes !== $remaining)) {
                throw ValidationException::withMessages(['chunk' => ['Parte do ficheiro com tamanho inválido.']]);
            }

            $disk = self::disk();
            $disk->makeDirectory('tmp');
            $target = $disk->path($upload->tempPath());
            $in = fopen($chunk->getRealPath(), 'rb');
            $out = fopen($target, 'ab');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $upload->received_bytes += $bytes;
            $upload->save();

            if ($upload->received_bytes === $upload->size_bytes) {
                $this->complete($upload);
            }

            return $upload->fresh();
        });
    }

    /** Fim do envio: confirma o tipo real, deduplica pela empresa e põe o processamento em fila. */
    private function complete(MediaUpload $upload): void
    {
        $disk = self::disk();
        $temp = $disk->path($upload->tempPath());
        $real = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($temp);
        $allowed = config($upload->kind === MediaAsset::IMAGE ? 'media.image_mimes' : 'media.video_mimes');
        if (! in_array($real, $allowed, true)) {
            @unlink($temp);
            throw ValidationException::withMessages(['file' => ['O conteúdo do ficheiro não corresponde a uma imagem ou vídeo aceite.']]);
        }

        $sha = hash_file('sha256', $temp);
        $existing = MediaAsset::where('company_id', $upload->company_id)->where('sha256', $sha)
            ->where('status', '!=', MediaAsset::REJECTED)->whereNull('original_deleted_at')->first();
        if ($existing) {
            // O mesmo ficheiro já existe na empresa: reutiliza-o.
            @unlink($temp);
            $upload->forceFill(['completed_at' => now(), 'media_asset_id' => $existing->id])->save();

            return;
        }

        $dir = "company_{$upload->company_id}/" . Str::uuid();
        $disk->makeDirectory($dir);
        rename($temp, $disk->path("{$dir}/original.{$upload->extension}"));

        $asset = MediaAsset::create([
            'company_id' => $upload->company_id, 'uploaded_by_user_id' => $upload->user_id, 'impersonator_user_id' => $upload->impersonator_user_id,
            'kind' => $upload->kind, 'disk' => (string) config('media.disk', 'media'), 'dir' => $dir,
            'original_name' => $upload->original_name, 'extension' => $upload->extension, 'mime' => $real,
            'size_bytes' => $upload->size_bytes, 'sha256' => $sha, 'status' => MediaAsset::PROCESSING,
        ]);
        $upload->forceFill(['completed_at' => now(), 'media_asset_id' => $asset->id])->save();

        DB::afterCommit(fn () => ProcessMediaAssetJob::dispatch($asset->id));
    }

    // ── Processamento (fila) ─────────────────────────────────────────────────

    public function process(int $assetId): void
    {
        $asset = MediaAsset::find($assetId);
        if (! $asset || $asset->status !== MediaAsset::PROCESSING) {
            return;
        }
        $disk = self::disk();
        $original = $disk->path((string) $asset->pathFor('original'));

        try {
            $manager = new ImageManager(new Driver());
            $variants = [];

            if ($asset->kind === MediaAsset::VIDEO) {
                $info = $this->probe->probeVideo($original);
                $poster = "{$asset->dir}/poster.jpg";
                $this->probe->posterFrame($original, $disk->path($poster), min(1.0, $info['duration_ms'] / 2000));
                $variants['poster'] = 'poster.jpg';
                $source = $disk->path($poster);
                $asset->fill(['width' => $info['width'], 'height' => $info['height'], 'duration_ms' => $info['duration_ms'], 'codec' => $info['codec']]);
            } else {
                $source = $original;
            }

            $image = $manager->read($source)->orient();
            if ($asset->kind === MediaAsset::IMAGE) {
                $asset->fill(['width' => $image->width(), 'height' => $image->height()]);
            }
            foreach (['thumb' => 320, 'preview' => 1080] as $name => $width) {
                $disk->put("{$asset->dir}/{$name}.webp", (clone $image)->scaleDown(width: $width)->toWebp(80)->toString());
                $variants[$name] = "{$name}.webp";
            }

            $asset->fill(['variants' => $variants, 'status' => MediaAsset::READY, 'error' => null])->save();
        } catch (\Throwable $e) {
            Log::warning('[Media] Processamento falhou', ['asset_id' => $assetId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $asset->fill(['status' => MediaAsset::REJECTED, 'error' => $e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Não foi')
                ? $e->getMessage() : 'Não foi possível ler este ficheiro. Experimente exportá-lo de novo (JPEG, PNG, WebP, MP4 ou MOV).'])->save();
        }
    }

    // ── Remoção ──────────────────────────────────────────────────────────────

    /** Apaga o asset e todas as variantes. */
    public function purge(MediaAsset $asset): void
    {
        self::disk()->deleteDirectory($asset->dir);
        $asset->delete();
    }

    /** Apaga só o original (as miniaturas ficam para o histórico e a grelha). */
    public function purgeOriginal(MediaAsset $asset): void
    {
        if ($path = $asset->pathFor('original')) {
            self::disk()->delete($path);
        }
        $asset->forceFill(['original_deleted_at' => now()])->save();
    }
}
