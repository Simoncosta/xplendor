<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Ficheiro de media de uma empresa (imagem ou vídeo), no disco privado "media". Nunca tem
 * URL público: sai por URLs assinados de curta duração, relativos à API.
 */
class MediaAsset extends Model
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';

    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const REJECTED = 'rejected';

    public const VARIANTS = ['original', 'thumb', 'preview', 'poster'];

    protected $fillable = [
        'company_id', 'uploaded_by_user_id', 'impersonator_user_id', 'kind', 'disk', 'dir', 'original_name',
        'extension', 'mime', 'size_bytes', 'width', 'height', 'duration_ms', 'codec', 'sha256', 'variants',
        'status', 'error', 'original_deleted_at',
    ];

    protected $casts = [
        'variants' => 'array',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'original_deleted_at' => 'datetime',
    ];

    /** Caminho no disco de uma variante (null se não existir). */
    public function pathFor(string $variant): ?string
    {
        if ($variant === 'original') {
            return $this->original_deleted_at ? null : "{$this->dir}/original.{$this->extension}";
        }
        $file = $this->variants[$variant] ?? null;

        return $file ? "{$this->dir}/{$file}" : null;
    }

    /** URL assinado e relativo (o ecrã junta o endereço da API). */
    public function signedUrl(string $variant): ?string
    {
        if (! $this->pathFor($variant)) {
            return null;
        }

        return URL::temporarySignedRoute('media.file', now()->addMinutes((int) config('media.signed_url_minutes', 30)),
            ['asset' => $this->id, 'variant' => $variant], absolute: false);
    }

    /**
     * Para um serviço externo ir buscar o ficheiro (a Meta, na F2 da publicação: ela faz um
     * pedido ao endereço no momento da publicação). Endereço ABSOLUTO e assinado, válido por
     * config storage_targets.external_fetch_ttl (1 hora por omissão):
     *  · no R2: um endereço assinado do próprio R2 (o bucket continua privado);
     *  · no disco local: o URL assinado da aplicação, absoluto.
     * Quem chama tem de ter verificado a empresa e a permissão (ACL) antes.
     */
    public function externalUrl(string $variant = 'original'): ?string
    {
        $path = $this->pathFor($variant);
        if (! $path) {
            return null;
        }
        $disk = (string) ($this->disk ?: config('media.disk', 'media'));
        $ttl = (int) config('storage_targets.external_fetch_ttl', 3600);
        if (\App\Support\Storage\SignedFileUrl::supports($disk)) {
            return \App\Support\Storage\SignedFileUrl::for($disk, $path, $ttl);
        }

        return URL::temporarySignedRoute('media.file', now()->addSeconds($ttl), ['asset' => $this->id, 'variant' => $variant]);
    }

    public function ratio(): ?float
    {
        return $this->width && $this->height ? $this->width / $this->height : null;
    }

    /**
     * Para os ecrãs: dados técnicos e URLs assinados das variantes. Com $sign, os URLs
     * são outros (por exemplo, os do link de aprovação, limitados ao lote).
     *
     * @param  ?\Closure(self, string): ?string  $sign
     */
    public function present(?\Closure $sign = null): array
    {
        $url = fn (string $variant) => $sign ? ($this->pathFor($variant) ? $sign($this, $variant) : null) : $this->signedUrl($variant);

        return [
            'id' => $this->id, 'kind' => $this->kind, 'status' => $this->status, 'error' => $this->error,
            'original_name' => $this->original_name, 'size_bytes' => $this->size_bytes,
            'width' => $this->width, 'height' => $this->height, 'duration_ms' => $this->duration_ms, 'codec' => $this->codec,
            'thumb_url' => $url('thumb'), 'preview_url' => $url('preview'),
            'poster_url' => $url('poster'), 'original_url' => $url('original'),
            'original_available' => $this->original_deleted_at === null,
        ];
    }
}
