<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um aviso (pop-up) do site da empresa. Vários podem estar programados; o site mostra, em cada
 * página, o primeiro da lista (por prioridade) que se aplica a essa página. A imagem é uma cópia
 * publicada no disco público (o original fica privado nos Ficheiros).
 */
class SitePopup extends Model
{
    public const PAGES = ['all', 'home', 'vehicles', 'vehicle'];
    public const FREQUENCIES = ['once_per_visitor', 'once_per_day', 'once_per_session'];

    protected $fillable = [
        'company_id', 'title', 'body', 'image_path', 'image_file_id', 'button_label', 'button_url', 'starts_at', 'ends_at', 'pages',
        'delay_seconds', 'frequency', 'priority', 'is_active', 'created_by_user_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'pages' => 'array', 'delay_seconds' => 'integer', 'priority' => 'integer', 'is_active' => 'boolean',
    ];

    /** Ativos agora: ligados e dentro das datas. */
    public function scopeLive(Builder $q): Builder
    {
        $now = now();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /** ativo, programado, terminado ou desligado */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'desligado',
            $this->ends_at && $this->ends_at->lte(now()) => 'terminado',
            $this->starts_at && $this->starts_at->gt(now()) => 'programado',
            default => 'ativo',
        };
    }

    /** Muda quando o aviso é editado: o site volta a mostrá-lo a quem já o fechou. */
    public function version(): string
    {
        return substr(sha1($this->id . '|' . optional($this->updated_at)->getTimestamp()), 0, 12);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? rtrim((string) config('app.url'), '/') . \App\Support\Storage\PublicUrl::path($this->image_path) : null;
    }

    public function counts(): HasMany
    {
        return $this->hasMany(SitePopupDailyCount::class);
    }
}
