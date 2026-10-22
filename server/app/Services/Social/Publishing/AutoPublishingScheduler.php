<?php

declare(strict_types=1);

namespace App\Services\Social\Publishing;

use App\Jobs\PrepareSocialPublicationMediaJob;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\EditorialPostNetwork;
use App\Models\SocialPublication;
use Illuminate\Support\Facades\Log;

/**
 * F2.2 · Programar e cancelar as publicações automáticas. Uma publicação sai sozinha quando está
 * Programada, com hora futura, com o interruptor da empresa ligado e a caixa "Publicar
 * automaticamente" marcada: nasce uma SocialPublication "agendada" por rede ainda por publicar.
 * Voltar atrás, mudar a data ou a hora, desmarcar, desligar o interruptor ou "Não publicar nesta
 * rede" reprograma ou cancela o que ainda não saiu (nunca chama a Meta). Sem hora, continua manual.
 *
 * Corre sozinho (eventos dos modelos): ao gravar a publicação, uma rede ou o interruptor da empresa.
 */
class AutoPublishingScheduler
{
    /** Reavalia uma publicação: agenda, reprograma ou cancela por rede. */
    public function sync(EditorialPost|int $post): void
    {
        $post = EditorialPost::with('networks.publication')->find($post instanceof EditorialPost ? $post->id : $post);
        if (! $post) {
            return;
        }
        $wanted = self::wanted($post);
        $dueAt = $wanted ? $post->dueAt()->utc() : null;
        $future = $dueAt?->gt(now()) ?? false;

        foreach ($post->networks as $network) {
            $publication = $network->publication;
            $open = $network->state() === EditorialPostNetwork::STATE_PENDING;

            // A hora já chegou e esta rede está agendada para ela: fica, o publicador está a tratar dela.
            if ($wanted && $open && ! $future && $publication?->isPending() && $publication->scheduled_for?->equalTo($dueAt)) {
                continue;
            }
            if (! $wanted || ! $open || ! $future) {
                if ($publication?->isPending()) {
                    $publication->forceFill(['status' => SocialPublication::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
                }

                continue;
            }

            if (! $publication) {
                $created = SocialPublication::create([
                    'company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'editorial_post_network_id' => $network->id,
                    'network' => $network->network, 'status' => SocialPublication::STATUS_SCHEDULED, 'scheduled_for' => $dueAt,
                ]);
                self::prepareMedia($created);

                continue;
            }

            $sameTime = $publication->scheduled_for?->equalTo($dueAt);
            $revive = $publication->status === SocialPublication::STATUS_CANCELLED
                // Uma que falhou só volta a agendar quando a hora muda (nunca sozinha, ao gravar outra coisa).
                || ($publication->status === SocialPublication::STATUS_FAILED && ! $sameTime);
            if ($publication->isPending() && ! $sameTime) {
                // Nova hora: começa do zero (sem verificação marcada nem contentor de antes).
                $publication->forceFill(['scheduled_for' => $dueAt, 'next_attempt_at' => null, 'container_id' => null])->save();
            } elseif ($revive) {
                $publication->forceFill([
                    'status' => SocialPublication::STATUS_SCHEDULED, 'scheduled_for' => $dueAt, 'attempts' => 0, 'next_attempt_at' => null,
                    'container_id' => null, 'error_code' => null, 'error_message' => null, 'cancelled_at' => null,
                ])->save();
                self::prepareMedia($publication);
            }
        }
    }

    /** F2.3 · Os ficheiros para a Meta preparam-se ao programar, na fila (depois de gravar). */
    private static function prepareMedia(SocialPublication $publication): void
    {
        PrepareSocialPublicationMediaJob::dispatch($publication->id)->afterCommit();
    }

    /** O interruptor da empresa mudou: reavalia as publicações Programadas com a caixa marcada. */
    public function syncCompany(int $companyId): void
    {
        EditorialPost::where('company_id', $companyId)->where('stage', EditorialPost::STAGE_SCHEDULED)
            ->where('auto_publish', true)->orderBy('id')->each(fn (EditorialPost $post) => $this->sync($post));
    }

    /**
     * É para sair sozinha? Programada, com hora, nas redes, com a caixa e o interruptor ligados.
     * Mesmo assim, só se agenda com a hora ainda por chegar (nunca sai atrasada sozinha).
     */
    public static function wanted(EditorialPost $post): bool
    {
        return $post->stage === EditorialPost::STAGE_SCHEDULED && $post->channel !== EditorialPost::CHANNEL_SITE
            && $post->auto_publish && $post->publish_time
            && (bool) Company::whereKey($post->company_id)->value('auto_publishing_enabled');
    }

    /** Para os eventos dos modelos: nunca deixa uma falha aqui travar a gravação da publicação. */
    public static function safely(\Closure $run): void
    {
        try {
            $run(app(self::class));
        } catch (\Throwable $e) {
            Log::error('[Publicação automática] Falha ao programar', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}
