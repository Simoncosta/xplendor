<?php

declare(strict_types=1);

namespace App\Services\SitePopups;

use App\Models\SitePopup;
use App\Models\SitePopupDailyCount;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Support\Storage\PublicUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Os avisos (pop-up) dos sites dos clientes.
 *  · A imagem escolhida nos Ficheiros (privados) é publicada como cópia reduzida (até 1200 px,
 *    WebP) no disco público; ao trocar a imagem ou apagar o aviso, a cópia antiga é apagada.
 *  · A API pública devolve numa só chamada os avisos ativos, por prioridade, cada um com as suas
 *    páginas: o site mostra o primeiro que se aplica à página.
 *  · As vistas, os cliques e os fechos contam-se por dia, só números (sem IP, sem cookies).
 */
class SitePopupService
{
    public const IMAGE_MAX = 1200;

    public function __construct(private readonly FileAccess $files) {}

    /** @param array<string, mixed> $data já validado */
    public function save(int $companyId, User $user, array $data, ?SitePopup $popup = null): SitePopup
    {
        $pages = array_values(array_unique((array) $data['pages']));
        $fields = [
            'title' => trim((string) $data['title']), 'body' => isset($data['body']) ? trim((string) $data['body']) ?: null : null,
            'button_label' => ($data['button_label'] ?? null) ? trim((string) $data['button_label']) : null,
            'button_url' => ($data['button_url'] ?? null) ? trim((string) $data['button_url']) : null,
            'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
            'pages' => in_array('all', $pages, true) ? ['all'] : $pages,
            'delay_seconds' => (int) ($data['delay_seconds'] ?? 3), 'frequency' => (string) $data['frequency'],
            'priority' => (int) ($data['priority'] ?? 0), 'is_active' => (bool) ($data['is_active'] ?? true),
        ];
        if (! $fields['button_label'] || ! $fields['button_url']) {
            $fields['button_label'] = $fields['button_url'] = null;
        }
        $oldImage = $popup?->image_path;
        $newImage = null;
        if (! empty($data['image_file_id']) && (int) $data['image_file_id'] !== (int) $popup?->image_file_id) {
            $file = StoredFile::where('company_id', $companyId)->whereNull('deleted_at')->find((int) $data['image_file_id']);
            if (! $file || ! $this->files->canSeeFile($user, $file)) {
                throw ValidationException::withMessages(['image_file_id' => ['A imagem não foi encontrada nos Ficheiros.']]);
            }
            $newImage = $this->publishImage($companyId, $file);
            $fields['image_path'] = $newImage;
            $fields['image_file_id'] = $file->id;
        } elseif (! empty($data['remove_image'])) {
            $fields['image_path'] = null;
            $fields['image_file_id'] = null;
        }

        $popup = DB::transaction(function () use ($companyId, $user, $fields, $popup) {
            if ($popup) {
                $popup->fill($fields)->save();

                return $popup;
            }

            return SitePopup::create($fields + ['company_id' => $companyId, 'created_by_user_id' => $user->id]);
        });
        // A cópia pública antiga sai quando a imagem muda ou é retirada.
        if ($oldImage && $oldImage !== $popup->image_path) {
            Storage::disk('public')->delete($oldImage);
        }

        return $popup->fresh();
    }

    public function delete(SitePopup $popup): void
    {
        if ($popup->image_path) {
            Storage::disk('public')->delete($popup->image_path);
        }
        $popup->delete();
    }

    /** A cópia pública da imagem (WebP, até 1200 px). Devolve o caminho no disco público. */
    public function publishImage(int $companyId, StoredFile $file): string
    {
        if (! in_array((string) $file->mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['image_file_id' => ['Escolha uma imagem JPG, PNG ou WebP.']]);
        }
        $disk = Storage::disk($file->disk);
        if (! $disk->exists($file->path)) {
            throw ValidationException::withMessages(['image_file_id' => ['O ficheiro da imagem já não existe.']]);
        }
        try {
            $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
            $webp = $manager->read($disk->get($file->path))->scaleDown(width: self::IMAGE_MAX, height: self::IMAGE_MAX)->toWebp(85)->toString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['image_file_id' => ['Não foi possível ler esta imagem.']]);
        }
        $path = "company_{$companyId}/popups/" . Str::uuid() . '.webp';
        Storage::disk('public')->put($path, $webp);

        return $path;
    }

    /** Os avisos ativos agora, por prioridade (a maior primeiro; com empate, o mais recente). @return Collection<int, SitePopup> */
    public function live(int $companyId): Collection
    {
        return SitePopup::where('company_id', $companyId)->live()
            ->orderByDesc('priority')->orderByRaw('COALESCE(starts_at, created_at) DESC')->orderByDesc('id')->get();
    }

    /** O aviso como o site o recebe. */
    public static function publicShape(SitePopup $p): array
    {
        return [
            'id' => $p->id, 'version' => $p->version(), 'title' => $p->title, 'body' => $p->body, 'image_url' => $p->imageUrl(),
            'button' => $p->button_label && $p->button_url ? ['label' => $p->button_label, 'url' => $p->button_url] : null,
            'pages' => array_values((array) $p->pages), 'delay_seconds' => $p->delay_seconds, 'frequency' => $p->frequency,
            'ends_at' => optional($p->ends_at)->toIso8601String(),
        ];
    }

    /** Uma vista, um clique ou um fecho, no dia de hoje (só números). */
    public function record(SitePopup $popup, string $type): void
    {
        $column = ['view' => 'views', 'click' => 'clicks', 'close' => 'closes'][$type];
        $day = now('Europe/Lisbon')->toDateString();
        // Inserir (se ainda não houver a linha do dia) e somar na base de dados: sem corridas entre pedidos.
        DB::table('site_popup_daily_counts')->insertOrIgnore(['site_popup_id' => $popup->id, 'day' => $day, 'views' => 0, 'clicks' => 0, 'closes' => 0]);
        DB::table('site_popup_daily_counts')->where('site_popup_id', $popup->id)->where('day', $day)->increment($column);
    }

    /** Os totais dos últimos N dias e, opcionalmente, a série por dia. */
    public function stats(SitePopup $popup, int $days = 30, bool $series = false): array
    {
        $from = now('Europe/Lisbon')->subDays($days - 1)->toDateString();
        $rows = SitePopupDailyCount::where('site_popup_id', $popup->id)->where('day', '>=', $from)->orderBy('day')->get();
        $views = (int) $rows->sum('views');
        $clicks = (int) $rows->sum('clicks');
        $out = ['days' => $days, 'views' => $views, 'clicks' => $clicks, 'closes' => (int) $rows->sum('closes'),
            'click_rate' => $views > 0 ? round($clicks * 100 / $views, 1) : null];
        if ($series) {
            $byDay = $rows->keyBy(fn ($r) => $r->day->toDateString());
            $out['series'] = collect(range($days - 1, 0))->map(function ($ago) use ($byDay) {
                $d = now('Europe/Lisbon')->subDays($ago)->toDateString();
                $r = $byDay->get($d);

                return ['day' => $d, 'views' => (int) ($r->views ?? 0), 'clicks' => (int) ($r->clicks ?? 0), 'closes' => (int) ($r->closes ?? 0)];
            })->values()->all();
        }

        return $out;
    }

    /** Os avisos ligados que se sobrepõem a este (páginas em comum e datas que se cruzam). @return int[] */
    public static function overlaps(SitePopup $p, Collection $all): array
    {
        if (! $p->is_active || $p->state() === 'terminado') {
            return [];
        }

        return $all->filter(function (SitePopup $o) use ($p) {
            if ($o->id === $p->id || ! $o->is_active || $o->state() === 'terminado') {
                return false;
            }
            $pagesP = (array) $p->pages;
            $pagesO = (array) $o->pages;
            $pages = in_array('all', $pagesP, true) || in_array('all', $pagesO, true) || array_intersect($pagesP, $pagesO) !== [];
            $startsBeforeEnd = fn ($a, $b) => ! $a->starts_at || ! $b->ends_at || $a->starts_at->lt($b->ends_at);

            return $pages && $startsBeforeEnd($p, $o) && $startsBeforeEnd($o, $p);
        })->pluck('id')->values()->all();
    }

    public function presentAdmin(SitePopup $p, Collection $all): array
    {
        return [
            'id' => $p->id, 'title' => $p->title, 'body' => $p->body, 'image_file_id' => $p->image_file_id,
            'image_path' => $p->image_path ? PublicUrl::path($p->image_path) : null, 'image_url' => $p->imageUrl(),
            'button_label' => $p->button_label, 'button_url' => $p->button_url,
            'starts_at' => optional($p->starts_at)->toIso8601String(), 'ends_at' => optional($p->ends_at)->toIso8601String(),
            'pages' => array_values((array) $p->pages), 'delay_seconds' => $p->delay_seconds, 'frequency' => $p->frequency,
            'priority' => $p->priority, 'is_active' => $p->is_active, 'state' => $p->state(), 'version' => $p->version(),
            'overlaps' => self::overlaps($p, $all), 'stats' => $this->stats($p),
            'updated_at' => optional($p->updated_at)->toIso8601String(),
        ];
    }
}
