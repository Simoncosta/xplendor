<?php

declare(strict_types=1);

namespace App\Services\Social\Publishing;

use App\Models\EditorialPost;
use App\Models\EditorialPostNetwork;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublication;
use App\Services\Editorial\EditorialPublishingService;
use App\Services\Editorial\NetworkFormats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F2.2 · O publicador: as publicações agendadas cuja hora chegou saem, uma a uma, pelo cliente da
 * Meta (nesta fatia, o simulado; as chamadas reais são da F2.4 em diante). Cada uma é reservada
 * antes (agendado → a publicar, numa só atualização), por isso nunca sai duas vezes. Correu bem:
 * "publicado", com o link, e a rede fica marcada como publicada. Correu mal: "falhou", com o
 * motivo (as tentativas, a quota esgotada e os alertas são da F2.7).
 */
class AutoPublisher
{
    public const RESULTS = ['published' => 0, 'failed' => 0, 'cancelled' => 0, 'waiting' => 0];

    /** Contentor ainda a processar: volta a ver daqui a WAIT_SECONDS, até WAIT_LIMIT_MINUTES depois da hora. */
    private const WAIT = 'wait';

    public const WAIT_SECONDS = 60;

    public const WAIT_LIMIT_MINUTES = 30;

    public function __construct(private readonly MetaPublishingClient $meta, private readonly EditorialPublishingService $publishing,
        private readonly MetaMediaPreparer $files) {}

    /** @return array{published: int, failed: int, cancelled: int} */
    public function runDue(int $limit = 20): array
    {
        $out = self::RESULTS;
        // A hora chegou; ou, numa que espera (vídeo a processar, F2.5), a próxima verificação.
        $ids = SocialPublication::where('status', SocialPublication::STATUS_SCHEDULED)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('next_attempt_at')->where('scheduled_for', '<=', now()))
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('scheduled_for')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            // Reserva: só quem passar de agendado a "a publicar" publica (nunca duas vezes).
            $claimed = SocialPublication::whereKey($id)->where('status', SocialPublication::STATUS_SCHEDULED)
                ->update(['status' => SocialPublication::STATUS_PUBLISHING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }
            $publication = SocialPublication::find($id);
            try {
                $out[$this->publish($publication)]++;
            } catch (\Throwable $e) {
                Log::error('[Publicação automática] Erro inesperado', ['publication_id' => $id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                $out[$this->fail($publication, self::failure(MetaPublishingClient::KIND_TRANSIENT, 'Erro inesperado na XPLENDOR ao publicar.'))]++;
            }
        }

        return $out;
    }

    /** @return 'published'|'failed'|'cancelled'|'waiting' */
    private function publish(SocialPublication $publication): string
    {
        $post = EditorialPost::find($publication->editorial_post_id);
        $network = EditorialPostNetwork::find($publication->editorial_post_network_id);
        if (! $post || ! $network || ! self::stillWanted($post, $network)) {
            $publication->forceFill(['status' => SocialPublication::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

            return 'cancelled';
        }
        $label = NetworkFormats::NETWORK_LABELS[$network->network] ?? $network->network;
        $account = SocialConnectionAccount::where('company_id', $post->company_id)->where('platform', $network->network)
            ->whereNotNull('page_access_token')->orderByDesc('is_primary')->orderBy('id')->first();
        if (! $account) {
            return $this->fail($publication, self::permanent("Não há uma conta do {$label} ligada nas Redes sociais da empresa."));
        }
        $version = EditorialPostVersion::find($post->approved_version_id ?? $post->current_version_id);
        $caption = self::caption($version, $network->network);
        [$items, $cover] = MetaMediaPreparer::media($version);
        $token = (string) $account->page_access_token;

        $result = $network->network === 'instagram'
            ? $this->instagram($publication, (string) $account->external_id, $token, (string) $network->media_format, $caption, $items, $cover)
            : $this->facebook((string) ($account->page_id ?: $account->external_id), $token, (string) $network->media_format, $caption, $items);
        if (! $result['ok'] && ($result['kind'] ?? null) === self::WAIT) {
            return $this->wait($publication);
        }
        if (! $result['ok']) {
            return $this->fail($publication, $result);
        }

        // Sem o identificador (o contentor já tinha saído numa tentativa anterior), fica publicada sem o link.
        $link = $result['id'] !== null ? $this->meta->permalink($network->network, $result['id'], $token) : ['ok' => false];
        $permalink = $link['ok'] ? (string) $link['permalink'] : null;
        $at = CarbonImmutable::now();
        $publication->forceFill(['status' => SocialPublication::STATUS_PUBLISHED, 'media_id' => $result['id'], 'permalink' => $permalink,
            'published_at' => $at, 'error_code' => null, 'error_message' => null])->save();
        $this->publishing->markPublishedAutomatically($network, $permalink, $at);

        return 'published';
    }

    /**
     * Instagram: quota, contentor (imagem, carrossel ou Reel), estado do contentor e publicação. O
     * contentor fica guardado logo que é criado: numa tentativa seguinte, consulta-se primeiro o estado
     * dele (PUBLISHED: já saiu, não se publica outra vez; FINISHED: publica esse; ERROR ou EXPIRED: novo).
     */
    private function instagram(SocialPublication $publication, string $igId, string $token, string $format, string $caption, array $items, ?MediaAsset $cover): array
    {
        if ($publication->container_id) {
            $previous = $this->meta->containerStatus((string) $publication->container_id, $token);
            if (! $previous['ok']) {
                return $previous;
            }
            if ($previous['status'] === 'PUBLISHED') {
                return ['ok' => true, 'id' => $publication->media_id ? (string) $publication->media_id : null];
            }
            if (in_array($previous['status'], ['FINISHED', 'IN_PROGRESS'], true)) {
                return $this->publishContainer($publication, $igId, $token, (string) $publication->container_id, $previous['status']);
            }
            $publication->forceFill(['container_id' => null])->save(); // ERROR ou EXPIRED: cria-se um novo
        }

        $quota = $this->meta->instagramQuota($igId, $token);
        if (! $quota['ok']) {
            return $quota;
        }
        if ($quota['used'] >= $quota['total']) {
            return self::failure(MetaPublishingClient::KIND_TRANSIENT, 'A quota diária de publicações do Instagram está esgotada.', 'quota');
        }

        // Os URLs assinados (1 hora) geram-se aqui, no momento de criar o contentor (F2.3).
        $container = match ($format) {
            'ig_feed_image' => ($url = $this->imageUrl($items[0] ?? null))
                ? $this->meta->createInstagramContainer($igId, $token, ['media_type' => 'IMAGE', 'image_url' => $url, 'caption' => $caption])
                : self::permanent('A versão aprovada não tem a imagem.'),
            'ig_carousel' => $this->carousel($igId, $token, $caption, $items),
            'ig_reel' => ($problem = $this->videoProblem($items[0] ?? null, $format))
                ? self::permanent($problem)
                : $this->meta->createInstagramContainer($igId, $token, $this->reel($items[0], $cover, $caption)),
            default => self::permanent(self::notYet($format)),
        };
        if (! $container['ok']) {
            return $container;
        }
        $publication->forceFill(['container_id' => (string) $container['container_id']])->save();
        // Antes de publicar, o estado do contentor (nunca publicar duas vezes nem um vídeo ainda a processar).
        $status = $this->meta->containerStatus($container['container_id'], $token);
        if (! $status['ok']) {
            return $status;
        }

        return $this->publishContainer($publication, $igId, $token, (string) $container['container_id'], $status['status']);
    }

    private function publishContainer(SocialPublication $publication, string $igId, string $token, string $containerId, string $status): array
    {
        if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
            return self::permanent('O Instagram não conseguiu processar o ficheiro. Verifique o formato e programe de novo.');
        }
        if ($status !== 'FINISHED') {
            return ['ok' => false, 'kind' => self::WAIT]; // ainda a processar (o vídeo de um Reel): verifica-se de novo daqui a pouco
        }
        $published = $this->meta->publishInstagram($igId, $token, $containerId);
        if (! $published['ok']) {
            return $published;
        }
        $publication->forceFill(['media_id' => (string) $published['media_id']])->save();

        return ['ok' => true, 'id' => (string) $published['media_id']];
    }

    /**
     * F2.5 · O Reel: o vídeo, a legenda, também no feed, e a capa escolhida (em JPEG). Sem capa, o
     * mesmo instante da imagem que a XPLENDOR mostra do vídeo (até 1 segundo, como a capa gerada).
     */
    private function reel(MediaAsset $video, ?MediaAsset $cover, string $caption): array
    {
        $payload = ['media_type' => 'REELS', 'video_url' => $video->externalUrl('original'), 'caption' => $caption, 'share_to_feed' => true];
        $coverUrl = $cover ? $this->imageUrl($cover) : null;
        if ($coverUrl) {
            $payload['cover_url'] = $coverUrl;
        } else {
            $payload['thumb_offset'] = (int) min(1000, intdiv((int) $video->duration_ms, 2));
        }

        return $payload;
    }

    private function carousel(string $igId, string $token, string $caption, array $items): array
    {
        if (count($items) < 2) {
            return self::permanent('Um carrossel precisa de pelo menos 2 ficheiros.');
        }
        $children = [];
        foreach ($items as $asset) {
            $video = $asset->kind === MediaAsset::VIDEO;
            if ($video && ($problem = $this->videoProblem($asset, 'ig_carousel'))) {
                return self::permanent($problem);
            }
            $url = $video ? $asset->externalUrl('original') : $this->imageUrl($asset);
            if (! $url) {
                return self::permanent('Falta um dos ficheiros do carrossel.');
            }
            $child = $this->meta->createInstagramContainer($igId, $token,
                $video ? ['media_type' => 'VIDEO', 'video_url' => $url, 'is_carousel_item' => true] : ['media_type' => 'IMAGE', 'image_url' => $url, 'is_carousel_item' => true]);
            if (! $child['ok']) {
                return $child;
            }
            $children[] = (string) $child['container_id'];
        }

        return $this->meta->createInstagramContainer($igId, $token, ['media_type' => 'CAROUSEL', 'children' => $children, 'caption' => $caption]);
    }

    /** Facebook: texto, fotografias ou vídeo na Página. */
    private function facebook(string $pageId, string $token, string $format, string $caption, array $items): array
    {
        $payload = match ($format) {
            'fb_post' => ['type' => 'text', 'message' => $caption],
            'fb_photos' => ($urls = array_values(array_filter(array_map(fn ($a) => $this->imageUrl($a), $items))))
                ? ['type' => count($urls) === 1 ? 'photo' : 'photos', 'message' => $caption, 'urls' => $urls] : null,
            'fb_video', 'fb_reel' => ($items[0] ?? null)?->kind === MediaAsset::VIDEO ? ['type' => 'video', 'message' => $caption, 'urls' => []] : null,
            default => false,
        };
        if ($payload === false) {
            return self::permanent(self::notYet($format));
        }
        if (($payload['type'] ?? null) === 'video') {
            if ($problem = $this->videoProblem($items[0], $format)) {
                return self::permanent($problem);
            }
            $payload['urls'] = [$items[0]->externalUrl('original')];
        }
        if ($payload === null) {
            return self::permanent('A versão aprovada não tem os ficheiros deste formato.');
        }
        $published = $this->meta->publishFacebook($pageId, $token, $payload);

        return $published['ok'] ? ['ok' => true, 'id' => (string) $published['post_id']] : $published;
    }

    /**
     * F2.5 · O contentor ainda está a processar: não prende o worker. Volta a "agendado" com a próxima
     * verificação daqui a um minuto (sem contar como tentativa), até WAIT_LIMIT_MINUTES depois da hora.
     *
     * @return 'failed'|'waiting'
     */
    private function wait(SocialPublication $publication): string
    {
        if ($publication->scheduled_for && now()->greaterThan($publication->scheduled_for->copy()->addMinutes(self::WAIT_LIMIT_MINUTES))) {
            return $this->fail($publication, self::failure(MetaPublishingClient::KIND_TRANSIENT,
                'O Instagram não acabou de processar o vídeo em ' . self::WAIT_LIMIT_MINUTES . ' minutos.', 'container'));
        }
        $publication->forceFill(['status' => SocialPublication::STATUS_SCHEDULED, 'next_attempt_at' => now()->addSeconds(self::WAIT_SECONDS),
            'attempts' => max(0, (int) $publication->attempts - 1)])->save();

        return 'waiting';
    }

    /** @return 'failed' */
    private function fail(SocialPublication $publication, array $failure): string
    {
        $publication->forceFill([
            'status' => SocialPublication::STATUS_FAILED,
            'error_code' => isset($failure['code']) ? mb_substr((string) $failure['code'], 0, 50) : ($failure['kind'] ?? null),
            'error_message' => mb_substr((string) ($failure['message'] ?? 'Falha ao publicar.'), 0, 500),
        ])->save();
        Log::warning('[Publicação automática] Falhou', ['publication_id' => $publication->id, 'network' => $publication->network,
            'kind' => $failure['kind'] ?? null, 'message' => $publication->error_message]);

        return 'failed';
    }

    /** Ainda é para sair sozinha? (a publicação pode ter mudado entre a reserva e agora) */
    private static function stillWanted(EditorialPost $post, EditorialPostNetwork $network): bool
    {
        return AutoPublishingScheduler::wanted($post) && $network->state() === EditorialPostNetwork::STATE_PENDING;
    }

    /** Legenda da rede (ou a geral) com as hashtags no fim. */
    private static function caption(?EditorialPostVersion $version, string $network): string
    {
        if (! $version) {
            return '';
        }
        $tags = collect($version->hashtags ?? [])->map(fn ($h) => trim((string) $h))->filter()
            ->map(fn ($h) => str_starts_with($h, '#') ? $h : '#' . $h)->implode(' ');

        return trim(trim((string) $version->captionFor($network)) . ($tags !== '' ? "\n\n" . $tags : ''));
    }

    /** A imagem em JPEG para a Meta (F2.3), ou null se não for uma imagem. */
    private function imageUrl(?MediaAsset $asset): ?string
    {
        return $asset ? $this->files->imageUrl($asset) : null;
    }

    private function videoProblem(?MediaAsset $asset, string $format): ?string
    {
        if (! $asset || $asset->kind !== MediaAsset::VIDEO) {
            return 'A versão aprovada não tem o vídeo.';
        }

        return $this->files->videoProblem($asset, $format);
    }

    private static function permanent(string $message): array
    {
        return self::failure(MetaPublishingClient::KIND_PERMANENT, $message);
    }

    private static function failure(string $kind, string $message, ?string $code = null): array
    {
        return ['ok' => false, 'kind' => $kind, 'code' => $code, 'message' => $message];
    }

    private static function notYet(string $format): string
    {
        return str_ends_with($format, '_story')
            ? 'As Stories ainda não saem sozinhas: publique à mão e marque como publicada.'
            : 'Este formato ainda não sai sozinho: publique à mão e marque como publicada.';
    }
}
