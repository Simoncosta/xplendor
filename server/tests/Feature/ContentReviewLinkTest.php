<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ContentReviewDigestJob;
use App\Jobs\ContentReviewRemindersJob;
use App\Mail\ContentReviewDigestMail;
use App\Mail\ContentReviewRequestMail;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewLinkOpen;
use App\Models\ContentReviewNotification;
use App\Models\EditorialPost;
use App\Models\EditorialPostReview;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\User;
use App\Support\TeamDeviceMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Linha Editorial, F3c: link de aprovação por lote. Token só no fragmento e no cabeçalho;
 * ficheiros limitados ao lote e à validade; aprovar, pedir alterações, comentar e
 * "Aprovar tudo" (numa transação); uma decisão por versão; item atualizado pela equipa;
 * reenviar no mesmo link; aberturas (robôs e equipa não contam); lembretes e resumo por
 * email; "Ver como o cliente"; tenancy.
 */
class ContentReviewLinkTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private Company $a;
    private Company $b;
    private Company $x;
    private User $userA;
    private User $adminA;
    private User $userB;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Mail::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500090001', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500090002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->x = Company::create(['nipc' => '500090003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
        }
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user', 'email' => 'ana@quebom.test']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'user']);
        $this->root = User::factory()->create(['company_id' => $this->x->id, 'role' => 'root', 'email' => 'equipa@xplendor.test']);
        config(['quotes.team_company_id' => $this->x->id]);
    }

    // ── auxiliares ───────────────────────────────────────────────────────────

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}/editorial{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function pub(string $token, string $ua = self::BROWSER): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['User-Agent' => $ua, 'X-Review-Token' => $token]);
    }

    /** Ficheiro já processado no disco "media" (sem passar pelo envio). */
    private function asset(Company $c, string $kind = 'image'): MediaAsset
    {
        $dir = "company_{$c->id}/" . uniqid('a', true);
        Storage::disk('media')->put("{$dir}/original.jpg", 'original');
        Storage::disk('media')->put("{$dir}/thumb.webp", 'thumb');
        Storage::disk('media')->put("{$dir}/preview.webp", 'preview');

        return MediaAsset::create([
            'company_id' => $c->id, 'kind' => $kind, 'disk' => 'media', 'dir' => $dir, 'original_name' => 'foto.jpg', 'extension' => 'jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 8, 'width' => 1080, 'height' => 1350, 'sha256' => hash('sha256', $dir),
            'variants' => ['thumb' => 'thumb.webp', 'preview' => 'preview.webp'], 'status' => MediaAsset::READY,
        ]);
    }

    /** Publicação em Aprovação (versão enviada), com um ficheiro. */
    private function postInReview(Company $c, string $title = 'Menu de Natal', int $daysAhead = 10, ?User $producer = null): EditorialPost
    {
        $producer ??= $this->userA;
        $p = EditorialPost::create(['company_id' => $c->id, 'publish_date' => now('Europe/Lisbon')->addDays($daysAhead)->toDateString(), 'title' => $title,
            'format' => 'Imagem', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $this->as($producer)->putJson($this->url($c, "/posts/{$p->id}/content"), ['caption' => "Legenda de {$title}", 'media_format' => 'ig_feed_image'])->assertOk();
        $this->as($producer)->putJson($this->url($c, "/posts/{$p->id}/media"), ['items' => [$this->asset($c)->id], 'cover_id' => null])->assertOk();
        $this->as($producer)->postJson($this->url($c, "/posts/{$p->id}/move"), ['stage' => 'client_review'])->assertOk();

        return $p->fresh();
    }

    private function createLink(array $posts, array $extra = [], ?User $u = null): array
    {
        return $this->as($u ?? $this->userA)->postJson($this->url($this->a, '/review-links'), [
            'title' => 'Novembro, semana 1', 'post_ids' => array_map(fn ($p) => $p->id, $posts),
        ] + $extra)->assertStatus(201)->json('data');
    }

    private static function tokenOf(array $link): string
    {
        return substr($link['url'], strrpos($link['url'], '#') + 1);
    }

    private function show(string $token)
    {
        return $this->pub($token)->getJson('/api/public/review');
    }

    // ── Envio e segurança ────────────────────────────────────────────────────

    public function test_link_token_only_in_the_fragment_and_header(): void
    {
        $p1 = $this->postInReview($this->a, 'Menu de Natal');
        $p2 = $this->postInReview($this->a, 'Bastidores');
        $link = $this->createLink([$p1, $p2], ['recipient_name' => 'Rita', 'recipient_email' => 'Rita@Cliente.test']);

        $this->assertMatchesRegularExpression('#/aprovar\#[A-Za-z0-9]{64}$#', $link['url']);
        $token = self::tokenOf($link);
        $row = ContentReviewLink::sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_encrypted);
        $this->assertSame($token, $row->token());
        $this->assertSame('rita@cliente.test', $row->recipient_email);
        $this->assertTrue($row->expires_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertStringContainsString($link['url'], $link['share_message']);
        Mail::assertQueued(ContentReviewRequestMail::class, fn ($m) => $m->hasTo('rita@cliente.test') && $m->url === $link['url'] && $m->pendingCount === 2);

        $res = $this->show($token)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(['open', true, 'Novembro, semana 1', 2], [$res->json('data.state'), $res->json('data.can_act'), $res->json('data.title'), $res->json('data.counts.pending')]);
        $this->assertSame(['Menu de Natal', 'Legenda de Menu de Natal', 'pending', true], [
            $res->json('data.items.0.title'), $res->json('data.items.0.caption'), $res->json('data.items.0.state'), $res->json('data.items.0.can_act')]);
        $this->assertSame(['name' => 'Quebom', 'logo_url' => null], $res->json('data.company'));

        // O token nunca no caminho nem na query; mal formado ou desconhecido: 404.
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Review-Token' => ''])->getJson("/api/public/review/{$token}")->assertNotFound();
        $this->withHeaders(['X-Review-Token' => ''])->getJson("/api/public/review?token={$token}")->assertNotFound();
        $this->show(str_repeat('a', 64))->assertNotFound();
        $this->show('curto')->assertNotFound();
    }

    public function test_only_posts_in_approval_and_only_producers(): void
    {
        $sent = $this->postInReview($this->a);
        $draft = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'Rascunho',
            'format' => 'Imagem', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $other = $this->postInReview($this->b, 'De outra empresa', 10, $this->userB);

        $this->as($this->userA)->getJson($this->url($this->a, '/review-links/candidates'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sent->id);
        $this->as($this->userA)->postJson($this->url($this->a, '/review-links'), ['title' => 'Lote', 'post_ids' => [$sent->id, $draft->id]])->assertStatus(422);
        $this->as($this->userA)->postJson($this->url($this->a, '/review-links'), ['title' => 'Lote', 'post_ids' => [$sent->id, $other->id]])->assertStatus(422);
        $this->as($this->userA)->postJson($this->url($this->a, '/review-links'), ['title' => '', 'post_ids' => [$sent->id]])->assertStatus(422);

        // Modo "Produção pela equipa": o cliente não envia lotes; a equipa sim.
        $this->a->forceFill(['content_production_mode' => 'team'])->save();
        $this->as($this->userA)->postJson($this->url($this->a, '/review-links'), ['title' => 'Lote', 'post_ids' => [$sent->id]])->assertStatus(403);
        $this->createLink([$sent], [], $this->root);
        $list = $this->as($this->adminA)->getJson($this->url($this->a, '/review-links'))->assertOk();
        $this->assertNull($list->json('data.links.0.url'), 'Quem não produz não vê o link com o token.');
        $this->assertSame(0, ContentReviewLink::where('company_id', $this->b->id)->count());
    }

    // ── Ficheiros ────────────────────────────────────────────────────────────

    public function test_files_only_for_the_batch_and_while_the_link_is_valid(): void
    {
        $p = $this->postInReview($this->a);
        $link = $this->createLink([$p]);
        $token = self::tokenOf($link);
        $data = $this->show($token)->assertOk()->json('data');
        $thumb = $data['items'][0]['media']['items'][0]['thumb_url'];
        $original = $data['items'][0]['media']['items'][0]['original_url'];

        $this->assertStringStartsWith('/api/public/review/media/', $thumb);
        $this->app['auth']->forgetGuards();
        $this->get($thumb)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->withHeaders(['Range' => 'bytes=0-3'])->get($original)->assertStatus(206);
        $this->flushHeaders();

        // Assinado, mas de um ficheiro fora do lote (outro ficheiro da mesma empresa): 404.
        $outside = $this->asset($this->a);
        $row = ContentReviewLink::sole();
        $forged = URL::temporarySignedRoute('review.media', now()->addMinutes(10), ['link' => $row->id, 'asset' => $outside->id, 'variant' => 'thumb'], absolute: false);
        $this->get($forged)->assertNotFound();
        // Sem assinatura ou com outra variante: recusado.
        $this->get(preg_replace('/&signature=[^&]+/', '', $thumb))->assertForbidden();

        // Expirado: os URLs deixam de servir e a página fica só para consulta, sem ficheiros.
        $row->update(['expires_at' => now()->subMinute()]);
        $this->get($thumb)->assertNotFound();
        $expired = $this->show($token)->assertOk();
        $this->assertSame(['expired', false, false, []], [$expired->json('data.state'), $expired->json('data.can_act'),
            $expired->json('data.items.0.can_act'), $expired->json('data.items.0.media.items')]);
        $this->pub($token)->postJson("/api/public/review/items/{$data['items'][0]['id']}/approve", ['name' => 'Rita'])->assertStatus(409);

        // Prolongar: volta a valer. Revogar: só consulta, ficheiros recusados.
        $this->as($this->userA)->postJson($this->url($this->a, "/review-links/{$row->id}/extend"))->assertOk()->assertJsonPath('data.state', 'open');
        $fresh = $this->show($token)->json('data.items.0.media.items.0.thumb_url');
        $this->app['auth']->forgetGuards();
        $this->get($fresh)->assertOk();
        $this->as($this->userA)->postJson($this->url($this->a, "/review-links/{$row->id}/revoke"))->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->app['auth']->forgetGuards();
        $this->get($fresh)->assertNotFound();
        $this->assertSame('revoked', $this->show($token)->json('data.state'));
    }

    // ── Decisões ─────────────────────────────────────────────────────────────

    public function test_approve_request_changes_and_comment_one_decision_per_version(): void
    {
        $p1 = $this->postInReview($this->a, 'Menu de Natal');
        $p2 = $this->postInReview($this->a, 'Bastidores');
        $token = self::tokenOf($this->createLink([$p1, $p2]));
        $items = $this->show($token)->json('data.items');

        $this->pub($token)->postJson("/api/public/review/items/{$items[0]['id']}/approve", ['name' => ''])->assertStatus(422);
        $res = $this->pub($token)->postJson("/api/public/review/items/{$items[0]['id']}/approve", ['name' => 'Rita Sousa'])->assertOk();
        $this->assertSame(['approved', 'Rita Sousa', false], [$res->json('data.items.0.state'), $res->json('data.items.0.decision.reviewer_name'), $res->json('data.items.0.can_act')]);
        $p1->refresh();
        $this->assertSame([EditorialPost::STAGE_SCHEDULED, $p1->current_version_id], [$p1->stage, $p1->approved_version_id]);
        $review = EditorialPostReview::where('editorial_post_id', $p1->id)->sole();
        $this->assertSame(['link', 'Rita Sousa', null, 'mobile'], [$review->via, $review->reviewer_name, $review->user_id, $review->device]);
        $this->assertNotNull($review->review_link_id);

        // Uma decisão por versão: aprovar de novo ou pedir alterações depois, 409.
        $this->pub($token)->postJson("/api/public/review/items/{$items[0]['id']}/approve", ['name' => 'Outra'])->assertStatus(409);
        $this->pub($token)->postJson("/api/public/review/items/{$items[0]['id']}/request-changes", ['name' => 'Outra', 'message' => 'Mudar'])->assertStatus(409);

        // Pedir alterações: mensagem obrigatória; volta a Produção.
        $this->pub($token)->postJson("/api/public/review/items/{$items[1]['id']}/request-changes", ['name' => 'Rita'])->assertStatus(422);
        $res = $this->pub($token)->postJson("/api/public/review/items/{$items[1]['id']}/request-changes", ['name' => 'Rita', 'message' => 'Trocar a foto.'])->assertOk();
        $this->assertSame(['changes_requested', 'Trocar a foto.'], [$res->json('data.items.1.state'), $res->json('data.items.1.decision.message')]);
        $this->assertSame(EditorialPost::STAGE_PRODUCTION, $p2->fresh()->stage);

        // Comentários partilhados (também depois da decisão); aparecem na app.
        $this->pub($token)->postJson("/api/public/review/items/{$items[1]['id']}/comments", ['name' => 'Rita', 'body' => ''])->assertStatus(422);
        $res = $this->pub($token)->postJson("/api/public/review/items/{$items[1]['id']}/comments", ['name' => 'Rita', 'body' => 'Pode ser a da esplanada.'])->assertOk();
        $this->assertSame(['Rita', 'Pode ser a da esplanada.', true], [$res->json('data.items.1.comments.0.author'), $res->json('data.items.1.comments.0.body'), $res->json('data.items.1.comments.0.from_link')]);
        $detail = $this->as($this->userA)->getJson($this->url($this->a, "/posts/{$p2->id}/workflow"))->assertOk();
        $this->assertSame('Pode ser a da esplanada.', $detail->json('data.comments.0.body'));
        $this->assertSame('link', $detail->json('data.reviews.0.via'));

        // Avisos a quem produz (modo próprio: sino da empresa) e item de outro link: 404.
        $this->assertTrue(Alert::where('company_id', $this->a->id)->where('title', 'like', 'Alterações pedidas%')->exists());
        $this->assertSame(3, ContentReviewNotification::count());
        $otherToken = self::tokenOf($this->createLink([$this->postInReview($this->a, 'Terceira')]));
        $this->pub($otherToken)->postJson("/api/public/review/items/{$items[0]['id']}/comments", ['name' => 'X', 'body' => 'Y'])->assertNotFound();
    }

    public function test_approve_all_in_one_transaction(): void
    {
        $p1 = $this->postInReview($this->a, 'Um');
        $p2 = $this->postInReview($this->a, 'Dois');
        $p3 = $this->postInReview($this->a, 'Três');
        $token = self::tokenOf($this->createLink([$p1, $p2, $p3]));

        // Um item que parece pendente mas falha na decisão: nada fica aprovado.
        $p3->currentVersion->forceFill(['status' => EditorialPostVersion::DRAFT])->save();
        $this->pub($token)->postJson('/api/public/review/approve-all', ['name' => 'Rita'])->assertStatus(409);
        $this->assertSame(0, EditorialPostReview::count());
        $this->assertSame(EditorialPost::STAGE_CLIENT_REVIEW, $p1->fresh()->stage);

        $p3->currentVersion->forceFill(['status' => EditorialPostVersion::SENT])->save();
        $this->pub($token)->postJson('/api/public/review/approve-all', ['name' => ''])->assertStatus(422);
        $res = $this->pub($token)->postJson('/api/public/review/approve-all', ['name' => 'Rita'])->assertOk();
        $this->assertSame(['pending' => 0, 'approved' => 3, 'changes_requested' => 0, 'outdated' => 0], $res->json('data.counts'));
        $this->assertSame(3, EditorialPost::where('stage', EditorialPost::STAGE_SCHEDULED)->count());
        $this->pub($token)->postJson('/api/public/review/approve-all', ['name' => 'Rita'])->assertStatus(409);
    }

    public function test_item_updated_by_the_team_until_resent_in_the_same_link(): void
    {
        $p = $this->postInReview($this->a);
        $link = $this->createLink([$p]);
        $token = self::tokenOf($link);
        $itemId = $this->show($token)->json('data.items.0.id');
        $v1 = $p->current_version_id;

        // A equipa muda o texto: nasce a versão 2 e a publicação volta a Produção.
        $this->as($this->userA)->putJson($this->url($this->a, "/posts/{$p->id}/content"), ['caption' => 'Legenda nova', 'media_format' => 'ig_feed_image'])->assertOk();
        $res = $this->show($token)->assertOk();
        $this->assertSame(['outdated', false, 'Legenda de Menu de Natal'], [$res->json('data.items.0.state'), $res->json('data.items.0.can_act'), $res->json('data.items.0.caption')]);
        $this->pub($token)->postJson("/api/public/review/items/{$itemId}/approve", ['name' => 'Rita'])->assertStatus(409)
            ->assertJsonPath('message', 'A equipa atualizou esta publicação. Aguarde que a volte a enviar neste link.');
        $this->pub($token)->postJson('/api/public/review/approve-all', ['name' => 'Rita'])->assertStatus(409);

        // Reenviar no mesmo link (depois de voltar a Aprovação): mesmo URL, versão nova, pendente.
        $this->as($this->userA)->postJson($this->url($this->a, "/posts/{$p->id}/move"), ['stage' => 'client_review'])->assertOk();
        $again = $this->as($this->userA)->putJson($this->url($this->a, "/review-links/{$link['id']}"), ['post_ids' => [$p->id], 'recipient_email' => 'rita@cliente.test'])->assertOk();
        $this->assertSame($link['url'], $again->json('data.url'));
        $res = $this->show($token)->assertOk();
        $this->assertSame(['pending', 'Legenda nova', 2], [$res->json('data.items.0.state'), $res->json('data.items.0.caption'), $res->json('data.items.0.version_number')]);
        $this->assertNotSame($v1, $p->fresh()->current_version_id);
        Mail::assertQueued(ContentReviewRequestMail::class, 1);
        $this->pub($token)->postJson("/api/public/review/items/{$itemId}/approve", ['name' => 'Rita'])->assertOk();
    }

    // ── Aberturas ────────────────────────────────────────────────────────────

    public function test_opens_ignore_robots_and_team_and_join_the_same_visit(): void
    {
        $this->assertFalse(Schema::hasColumn('content_review_link_opens', 'ip'));
        $token = self::tokenOf($this->createLink([$this->postInReview($this->a)], ['recipient_name' => 'Rita']));
        $open = fn (string $visitor, string $ua = self::BROWSER, array $extra = []) => $this->pub($token, $ua)->postJson('/api/public/review/open', ['visitor_id' => $visitor] + $extra);

        foreach (['WhatsApp/2.23.20.0 A', 'facebookexternalhit/1.1', 'Slackbot-LinkExpanding 1.0'] as $bot) {
            $open('robo-aaaaaaaaaaaaaaaa', $bot)->assertOk()->assertJsonPath('data.reason', 'bot');
        }
        $open('equipa-bbbbbbbbbbbbbbb', self::BROWSER, ['team_marker' => TeamDeviceMarker::issue($this->root)])->assertOk()->assertJsonPath('data.reason', 'team');
        $this->as($this->root)->withHeaders(['X-Review-Token' => $token])->postJson('/api/public/review/open', ['visitor_id' => 'equipa-ccccccccccccccc'])->assertJsonPath('data.reason', 'team');
        $this->assertSame(0, ContentReviewLinkOpen::count());

        $open('cliente-ddddddddddddddd')->assertOk()->assertJsonPath('data.counted', true);
        $open('cliente-ddddddddddddddd')->assertOk()->assertJsonPath('data.reason', 'same_visit');
        $this->travel(31)->minutes();
        $open('cliente-ddddddddddddddd')->assertOk()->assertJsonPath('data.counted', true);
        $link = ContentReviewLink::sole();
        $this->assertSame([2, 2], [$link->open_count, ContentReviewLinkOpen::count()]);
        $this->assertSame('mobile', ContentReviewLinkOpen::first()->device);
        // Um aviso na primeira abertura; o segundo só depois de 6 horas.
        $this->assertSame(1, Alert::where('title', 'like', 'Link de aprovação aberto%')->count());

        // "Ver como o cliente": a mesma página, sem ações, e não conta como abertura.
        $preview = $this->as($this->userA)->getJson($this->url($this->a, "/review-links/{$link->id}/preview"))->assertOk();
        $this->assertSame([true, false, false], [$preview->json('data.preview'), $preview->json('data.can_act'), $preview->json('data.items.0.can_act')]);
        $this->assertSame(2, $link->fresh()->open_count);
    }

    // ── Lembretes e resumo ───────────────────────────────────────────────────

    public function test_reminders_and_team_digest(): void
    {
        $this->travelTo(now('Europe/Lisbon')->startOfDay()->setTime(9, 0)->utc());
        $soon = $this->postInReview($this->a, 'Amanhã', 1);
        $later = $this->postInReview($this->a, 'Daqui a 20 dias', 20);
        $this->createLink([$soon, $later], ['recipient_name' => 'Rita', 'recipient_email' => 'rita@cliente.test']);
        Mail::assertQueued(ContentReviewRequestMail::class, 1);

        // No próprio dia: só o urgente (menos de 48 horas para a data, sem aprovação).
        $done = app(ContentReviewRemindersJob::class)->handle(app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertSame([0, 0, 1], [$done['client'], $done['team'], $done['urgent']]);
        $this->assertTrue(Alert::where('company_id', $this->a->id)->where('type', 'urgent')->where('title', 'like', '%Amanhã%')->exists());

        // Dois dias depois: lembrete ao cliente, uma vez.
        $this->travel(2)->days();
        $done = (new ContentReviewRemindersJob())->handle(app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertSame([1, 0, 0], [$done['client'], $done['team'], $done['urgent']]);
        Mail::assertQueued(ContentReviewRequestMail::class, fn ($m) => $m->reminder && $m->pendingCount === 2);
        $done = (new ContentReviewRemindersJob())->handle(app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertSame(0, $done['client']);

        // Quatro dias: aviso a quem produz, uma vez.
        $this->travel(2)->days();
        $done = (new ContentReviewRemindersJob())->handle(app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertSame(1, $done['team']);
        $this->assertSame(0, (new ContentReviewRemindersJob())->handle(app(\App\Services\ContentReview\ContentReviewNotifier::class))['team']);

        // Resumo: um email a quem enviou (modo próprio), com as novidades; depois, nada.
        $sent = (new ContentReviewDigestJob())->handle();
        $this->assertSame(['emails' => 1, 'notifications' => 2], $sent);
        Mail::assertSent(ContentReviewDigestMail::class, fn ($m) => $m->hasTo('ana@quebom.test') && count($m->lines) === 2 && str_starts_with($m->envelope()->subject, 'Urgente'));
        $this->assertSame(['emails' => 0, 'notifications' => 0], (new ContentReviewDigestJob())->handle());

        // Modo "Produção pela equipa": avisos no sino da equipa e resumo para a equipa.
        $this->a->forceFill(['content_production_mode' => 'team'])->save();
        $this->travel(11)->days();
        app(\App\Services\ContentReview\ContentReviewNotifier::class)->notify(ContentReviewLink::sole(), 'warning', 'Teste', 'Mensagem');
        $this->assertTrue(Alert::where('company_id', $this->x->id)->where('title', 'Quebom: Teste')->exists());
        (new ContentReviewDigestJob())->handle();
        Mail::assertSent(ContentReviewDigestMail::class, fn ($m) => $m->hasTo('equipa@xplendor.test'));
    }

    public function test_avatar_of_the_connected_account_in_the_preview(): void
    {
        $connection = SocialConnection::create(['company_id' => $this->a->id, 'meta_user_id' => '1', 'access_token' => 't', 'status' => SocialConnection::STATUS_ACTIVE]);
        Storage::disk('media')->put("social/company_{$this->a->id}/account_1.jpg", 'jpeg');
        $account = SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $connection->id, 'platform' => 'instagram',
            'external_id' => '17841', 'page_id' => '1001', 'name' => 'Quebom', 'username' => 'quebom.porto', 'page_access_token' => 'x', 'is_primary' => true,
            'profile_picture_path' => "social/company_{$this->a->id}/account_1.jpg"]);
        $token = self::tokenOf($this->createLink([$this->postInReview($this->a)]));

        $ig = $this->show($token)->assertOk()->json('data.accounts.instagram');
        $this->assertSame(['Quebom', 'quebom.porto'], [$ig['name'], $ig['username']]);
        $this->assertStringStartsWith('/api/social-avatar/' . $account->id, $ig['avatar_url']);
        $this->app['auth']->forgetGuards();
        $this->get($ig['avatar_url'])->assertOk();
        $this->get('/api/social-avatar/' . $account->id)->assertForbidden();
        $this->assertNull($this->show($token)->json('data.accounts.facebook'));
    }

    public function test_reading_the_accounts_copies_the_real_profile_picture(): void
    {
        $connection = SocialConnection::create(['company_id' => $this->a->id, 'meta_user_id' => '1', 'access_token' => 't', 'status' => SocialConnection::STATUS_ACTIVE]);
        $ig = SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $connection->id, 'platform' => 'instagram',
            'external_id' => '17841', 'page_id' => '1001', 'name' => 'Quebom', 'username' => 'antigo', 'page_access_token' => 'x', 'is_primary' => true]);
        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*/17841*' => \Illuminate\Support\Facades\Http::response(['followers_count' => 900, 'follows_count' => 10, 'media_count' => 50,
                'profile_picture_url' => 'https://cdn.meta.test/foto.jpg', 'username' => 'quebom.porto']),
            'cdn.meta.test/*' => \Illuminate\Support\Facades\Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        app(\App\Services\Social\SocialConnectionService::class)->readCompany($this->a->id);
        $ig->refresh();
        $this->assertSame(['quebom.porto', "social/company_{$this->a->id}/account_{$ig->id}.jpg"], [$ig->username, $ig->profile_picture_path]);
        Storage::disk('media')->assertExists($ig->profile_picture_path);

        // Desligar apaga as fotos copiadas.
        app(\App\Services\Social\SocialConnectionService::class)->disconnect($this->a->id, true);
        Storage::disk('media')->assertMissing("social/company_{$this->a->id}/account_{$ig->id}.jpg");
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_tenancy(): void
    {
        $link = $this->createLink([$this->postInReview($this->a)]);

        $this->as($this->userB)->getJson($this->url($this->a, '/review-links'))->assertStatus(403);
        $this->as($this->userB)->getJson($this->url($this->a, "/review-links/{$link['id']}/preview"))->assertStatus(403);
        $this->as($this->userB)->getJson($this->url($this->b, "/review-links/{$link['id']}/preview"))->assertNotFound();
        $this->as($this->userB)->postJson($this->url($this->b, "/review-links/{$link['id']}/revoke"))->assertNotFound();
        $this->as($this->userB)->putJson($this->url($this->b, "/review-links/{$link['id']}"), ['post_ids' => [1]])->assertNotFound();
        $this->assertNull(ContentReviewLink::sole()->revoked_at);
    }
}
