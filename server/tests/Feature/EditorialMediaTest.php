<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\MediaRetentionJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Media\DiskUsage;
use App\Services\Media\MediaProbe;
use App\Services\Media\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Linha Editorial, F3b: media por versão. Envio em partes (retomável), tipos, tamanhos e
 * quota, deduplicação por SHA-256 na empresa, miniaturas WebP e capa do vídeo (ffmpeg
 * simulado), URLs assinados de curta duração com vídeo por partes, validação por formato,
 * versões, modo "Produção pela equipa", retenção, aviso de disco, grelha e tenancy.
 */
class EditorialMediaTest extends TestCase
{
    use RefreshDatabase;

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
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500080001', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500080002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->x = Company::create(['nipc' => '500080003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
        }
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'user']);
        $this->root = User::factory()->create(['company_id' => $this->x->id, 'role' => 'root']);
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

    private function image(int $w = 1080, int $h = 1350, string $name = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /** Bytes mínimos que o finfo reconhece como MP4. */
    private function mp4(string $name = 'video.mp4', int $padding = 2000): UploadedFile
    {
        $bytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\x00", $padding);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function fakeProbe(int $w = 1080, int $h = 1920, int $ms = 15000, string $codec = 'h264'): void
    {
        $this->mock(MediaProbe::class, function ($m) use ($w, $h, $ms, $codec) {
            $m->shouldReceive('probeVideo')->andReturn(['width' => $w, 'height' => $h, 'duration_ms' => $ms, 'codec' => $codec]);
            $m->shouldReceive('posterFrame')->andReturnUsing(function ($video, $jpeg) use ($w, $h) {
                $img = imagecreatetruecolor(min($w, 540), min($h, 960));
                imagejpeg($img, $jpeg);
            });
        });
    }

    /** Envia um ficheiro em partes e devolve o media (o processamento corre na fila síncrona). */
    private function upload(User $u, Company $c, UploadedFile $file, string $mime = 'image/jpeg'): array
    {
        $content = file_get_contents($file->getRealPath());
        $start = $this->as($u)->postJson($this->url($c, '/media/uploads'), ['name' => $file->getClientOriginalName(), 'size' => strlen($content), 'mime' => $mime])
            ->assertStatus(201)->json('data');
        $chunk = (int) $start['chunk_bytes'];
        $res = null;
        for ($offset = 0; $offset < strlen($content); $offset += $chunk) {
            $res = $this->sendChunk($u, $c, $start['id'], $offset, substr($content, $offset, $chunk))->assertOk()->json('data');
        }

        return $res['asset'];
    }

    private function sendChunk(User $u, Company $c, string $id, int $offset, string $bytes)
    {
        return $this->as($u)->post($this->url($c, "/media/uploads/{$id}/chunk"),
            ['offset' => $offset, 'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes)], ['Accept' => 'application/json']);
    }

    private function makePost(Company $c, string $format = 'ig_carousel', string $channel = 'instagram'): EditorialPost
    {
        $p = EditorialPost::create(['company_id' => $c->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'Com media',
            'format' => 'Carrossel', 'channel' => $channel, 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $this->as($this->userA)->putJson($this->url($c, "/posts/{$p->id}/content"), ['caption' => 'Legenda', 'media_format' => $format])->assertOk();

        return $p->fresh();
    }

    private function setMedia(EditorialPost $p, array $items, ?int $cover = null, ?User $u = null)
    {
        return $this->as($u ?? $this->userA)->putJson($this->url($p->company_id === $this->a->id ? $this->a : $this->b, "/posts/{$p->id}/media"), ['items' => $items, 'cover_id' => $cover]);
    }

    // ── Envio e processamento ────────────────────────────────────────────────

    public function test_image_is_processed_into_webp_variants(): void
    {
        $asset = $this->upload($this->userA, $this->a, $this->image());

        $this->assertSame(['ready', 'image', 1080, 1350], [$asset['status'], $asset['kind'], $asset['width'], $asset['height']]);
        $row = MediaAsset::findOrFail($asset['id']);
        Storage::disk('media')->assertExists(["{$row->dir}/original.jpg", "{$row->dir}/thumb.webp", "{$row->dir}/preview.webp"]);
        $this->assertSame(64, strlen((string) $row->sha256));
        [$tw] = getimagesizefromstring(Storage::disk('media')->get("{$row->dir}/thumb.webp"));
        $this->assertSame(320, $tw);
    }

    public function test_video_gets_a_poster_and_technical_data(): void
    {
        $this->fakeProbe(1080, 1920, 15000, 'h264');
        $asset = $this->upload($this->userA, $this->a, $this->mp4(), 'video/mp4');

        $this->assertSame(['ready', 'video', 1080, 1920, 15000, 'h264'], [$asset['status'], $asset['kind'], $asset['width'], $asset['height'], $asset['duration_ms'], $asset['codec']]);
        $this->assertNotNull($asset['poster_url']);
        $this->assertNotNull($asset['thumb_url']);
    }

    public function test_upload_resumes_from_the_server_offset(): void
    {
        config(['media.chunk_bytes' => 4096]);
        $file = $this->image(900, 900);
        $content = file_get_contents($file->getRealPath());
        $this->assertGreaterThan(8192, strlen($content));
        $id = $this->as($this->userA)->postJson($this->url($this->a, '/media/uploads'), ['name' => 'a.jpg', 'size' => strlen($content), 'mime' => 'image/jpeg'])->json('data.id');

        $this->sendChunk($this->userA, $this->a, $id, 0, substr($content, 0, 4096))->assertOk()->assertJsonPath('data.received_bytes', 4096);
        // A mesma parte outra vez (ligação caiu e o ecrã repete): 409 com o ponto para retomar.
        $this->sendChunk($this->userA, $this->a, $id, 0, substr($content, 0, 4096))->assertStatus(409)->assertJsonPath('errors.received_bytes', 4096);
        $this->as($this->userA)->getJson($this->url($this->a, "/media/uploads/{$id}"))->assertJsonPath('data.received_bytes', 4096);
        // Uma parte curta que não é a última: recusada.
        $this->sendChunk($this->userA, $this->a, $id, 4096, substr($content, 4096, 100))->assertStatus(422);

        for ($offset = 4096; $offset < strlen($content); $offset += 4096) {
            $res = $this->sendChunk($this->userA, $this->a, $id, $offset, substr($content, $offset, 4096))->assertOk();
        }
        $res->assertJsonPath('data.completed', true)->assertJsonPath('data.asset.status', 'ready');
    }

    public function test_types_sizes_quota_and_disguised_files_are_refused(): void
    {
        $start = fn (string $name, int $size, string $mime) => $this->as($this->userA)->postJson($this->url($this->a, '/media/uploads'), ['name' => $name, 'size' => $size, 'mime' => $mime]);

        $start('doc.pdf', 1000, 'application/pdf')->assertStatus(422);
        $start('foto.jpg', 31 * 1024 * 1024, 'image/jpeg')->assertStatus(422);
        $start('video.mp4', 301 * 1024 * 1024, 'video/mp4')->assertStatus(422);
        $start('video.mp4', 300 * 1024 * 1024, 'video/mp4')->assertStatus(201);

        config(['media.company_quota_mb' => 1]);
        MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'dir' => 'x', 'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 900 * 1024, 'status' => 'ready']);
        $start('foto.jpg', 200 * 1024, 'image/jpeg')->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'espaço de media'));
        config(['media.company_quota_mb' => 5120]);

        // Um ".jpg" que não é imagem: recusado no fim do envio.
        $fake = UploadedFile::fake()->createWithContent('falsa.jpg', str_repeat('texto ', 200));
        $id = $start('falsa.jpg', $fake->getSize(), 'image/jpeg')->json('data.id');
        $this->sendChunk($this->userA, $this->a, $id, 0, file_get_contents($fake->getRealPath()))->assertStatus(422);
        $this->assertSame(1, MediaAsset::where('company_id', $this->a->id)->count());
    }

    public function test_same_file_is_deduplicated_within_the_company_only(): void
    {
        $file = $this->image(800, 1000);
        $first = $this->upload($this->userA, $this->a, $file);
        $again = $this->upload($this->userA, $this->a, $file);
        $other = $this->upload($this->userB, $this->b, $file);

        $this->assertSame($first['id'], $again['id']);
        $this->assertNotSame($first['id'], $other['id']);
        $this->assertSame(1, MediaAsset::where('company_id', $this->a->id)->count());
    }

    // ── URLs assinados ───────────────────────────────────────────────────────

    public function test_files_are_only_served_by_short_lived_signed_urls_with_ranges(): void
    {
        $asset = $this->upload($this->userA, $this->a, $this->image());
        $this->app['auth']->forgetGuards();

        $this->assertStringStartsWith('/api/media/', $asset['thumb_url']);
        $this->get($asset['thumb_url'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(strtok($asset['thumb_url'], '?'))->assertStatus(403);
        $this->get(str_replace('/thumb?', '/original?', $asset['thumb_url']))->assertStatus(403); // a assinatura não serve para outra variante

        $range = $this->withHeaders(['Range' => 'bytes=0-99'])->get($asset['original_url']);
        $range->assertStatus(206);
        $this->assertSame('100', $range->headers->get('Content-Length'));

        $this->travel(31)->minutes();
        $this->get($asset['thumb_url'])->assertStatus(403);
    }

    // ── Media por versão e validação ─────────────────────────────────────────

    public function test_media_per_version_validation_and_freeze(): void
    {
        $p = $this->makePost($this->a, 'ig_carousel');
        $img1 = $this->upload($this->userA, $this->a, $this->image(1080, 1350, 'a.jpg'));
        $img2 = $this->upload($this->userA, $this->a, $this->image(1080, 1080, 'b.jpg'));

        $res = $this->setMedia($p, [$img1['id']])->assertOk();
        $this->assertContains('O carrossel leva entre 2 e 10 ficheiros.', $res->json('data.media_validation.errors'));
        $this->as($this->userA)->postJson($this->url($this->a, "/posts/{$p->id}/move"), ['stage' => 'client_review'])
            ->assertStatus(422)->assertJsonPath('message', 'Corrija os ficheiros antes de enviar: O carrossel leva entre 2 e 10 ficheiros.');

        $res = $this->setMedia($p, [$img2['id'], $img1['id']])->assertOk();
        $this->assertSame([], $res->json('data.media_validation.errors'));
        $this->assertSame([$img2['id'], $img1['id']], array_column($res->json('data.versions.0.media.items'), 'id'));
        $this->as($this->userA)->postJson($this->url($this->a, "/posts/{$p->id}/move"), ['stage' => 'client_review'])->assertOk();

        // Mudar os media de uma versão enviada cria a versão 2, com os media copiados; a 1 fica igual.
        $res = $this->setMedia($p, [$img1['id'], $img2['id']])->assertOk()->assertJsonPath('data.post.stage', 'production');
        $this->assertSame(2, $res->json('data.versions.0.number'));
        $this->assertSame([$img1['id'], $img2['id']], array_column($res->json('data.versions.0.media.items'), 'id'));
        $this->assertSame([$img2['id'], $img1['id']], array_column($res->json('data.versions.1.media.items'), 'id'));

        $this->setMedia($p, array_fill(0, 11, $img1['id']))->assertStatus(422);
        $this->setMedia($p, [$img1['id'], $img1['id']])->assertStatus(422);
    }

    public function test_format_rules_for_feed_reel_and_cover(): void
    {
        $this->fakeProbe(1920, 1080, 2000);
        $wide = $this->upload($this->userA, $this->a, $this->image(2000, 800, 'panorama.jpg'));
        $video = $this->upload($this->userA, $this->a, $this->mp4(), 'video/mp4');
        $img = $this->upload($this->userA, $this->a, $this->image(1080, 1350, 'capa.jpg'));

        $feed = $this->makePost($this->a, 'ig_feed_image');
        $errors = $this->setMedia($feed, [$wide['id']])->json('data.media_validation.errors');
        $this->assertContains('A proporção tem de estar entre 4:5 (vertical) e 1,91:1 (horizontal).', $errors);

        $reel = $this->makePost($this->a, 'ig_reel');
        $v = $this->setMedia($reel, [$video['id']], $img['id'])->assertOk()->json('data.media_validation');
        $this->assertContains('Um Reel tem de ter entre 3 segundos e 15 minutos.', $v['errors']);
        $this->assertContains('Recomendado 9:16 (vertical, 1080 x 1920). Fora disso a rede corta ou põe margens.', $v['warnings']);
        $this->setMedia($reel, [$video['id']], $video['id'])->assertStatus(422)->assertJsonValidationErrors('cover');
    }

    // ── Modo "Produção pela equipa", tenancy e grelha ────────────────────────

    public function test_client_managed_by_the_team_cannot_upload_nor_change_calendar_structure(): void
    {
        $this->a->forceFill(['content_production_mode' => 'team'])->save();
        $p = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'X',
            'format' => 'Reels', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PRODUCTION]);

        foreach ([$this->userA, $this->adminA] as $client) {
            $this->as($client)->postJson($this->url($this->a, '/media/uploads'), ['name' => 'a.jpg', 'size' => 100, 'mime' => 'image/jpeg'])->assertStatus(403);
            $this->as($client)->putJson($this->url($this->a, "/posts/{$p->id}/media"), ['items' => []])->assertStatus(403);
            $this->as($client)->postJson($this->url($this->a, '/months/' . now()->year . '/' . now()->month . '/open'))->assertStatus(403);
            $this->as($client)->postJson($this->url($this->a, '/months/' . now()->year . '/' . now()->month . '/close'))->assertStatus(403);
            $this->as($client)->postJson($this->url($this->a, '/anchors'), ['title' => 'Aniversário'])->assertStatus(403);
            $this->as($client)->deleteJson($this->url($this->a, '/own-anchors/1'))->assertStatus(403);
            $this->as($client)->postJson($this->url($this->a, '/anchors/1/hide'), ['year' => now()->year])->assertStatus(403);
        }
        // A equipa envia.
        $this->as($this->root)->postJson($this->url($this->a, '/media/uploads'), ['name' => 'a.jpg', 'size' => 100, 'mime' => 'image/jpeg'])->assertStatus(201);
    }

    public function test_tenancy_of_media(): void
    {
        $theirs = $this->upload($this->userB, $this->b, $this->image());
        $p = $this->makePost($this->a);

        $this->setMedia($p, [$theirs['id']])->assertStatus(422)->assertJsonPath('errors.items.0', 'Ficheiro não encontrado.');
        $this->as($this->userA)->getJson($this->url($this->a, "/media/{$theirs['id']}"))->assertNotFound();
        $uploadB = MediaUpload::where('company_id', $this->b->id)->first();
        $this->as($this->userA)->getJson($this->url($this->a, "/media/uploads/{$uploadB->id}"))->assertNotFound();
        $this->as($this->userA)->getJson($this->url($this->b, '/grid'))->assertStatus(403);
    }

    public function test_instagram_grid_shows_upcoming_posts_like_the_profile(): void
    {
        $near = $this->makePost($this->a, 'ig_carousel');
        $img = $this->upload($this->userA, $this->a, $this->image());
        $img2 = $this->upload($this->userA, $this->a, $this->image(900, 900, 'c.jpg'));
        $this->setMedia($near, [$img['id'], $img2['id']])->assertOk();
        $far = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => now()->addDays(20)->toDateString(), 'title' => 'Mais tarde',
            'format' => 'Reels', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PLANNING]);
        EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => now()->addDays(3)->toDateString(), 'title' => 'Facebook',
            'format' => 'Reels', 'channel' => 'facebook', 'stage' => EditorialPost::STAGE_PLANNING]);

        $grid = $this->as($this->userA)->getJson($this->url($this->a, '/grid'))->assertOk()->json('data');
        $this->assertSame([$far->id, $near->id], array_column($grid['upcoming'], 'id'), 'A mais distante primeiro, como no perfil.');
        $this->assertSame(2, $grid['upcoming'][1]['items_count']);
        $this->assertNotNull($grid['upcoming'][1]['image_url']);
        $this->assertNull($grid['upcoming'][0]['image_url']);
    }

    // ── Retenção e disco ─────────────────────────────────────────────────────

    public function test_retention_and_disk_alert(): void
    {
        config(['quotes.team_company_id' => $this->x->id]);
        $media = app(MediaService::class);
        $make = function (int $daysOld) {
            $asset = MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'dir' => 'company_' . $this->a->id . '/' . uniqid(), 'extension' => 'jpg',
                'mime' => 'image/jpeg', 'size_bytes' => 10, 'status' => 'ready', 'variants' => ['thumb' => 'thumb.webp']]);
            Storage::disk('media')->put("{$asset->dir}/original.jpg", 'x');
            Storage::disk('media')->put("{$asset->dir}/thumb.webp", 'x');
            $asset->forceFill(['created_at' => now()->subDays($daysOld)])->save();

            return $asset;
        };
        $link = fn (int $versionId, MediaAsset $asset) => DB::table('editorial_post_version_media')->insert(['version_id' => $versionId, 'media_asset_id' => $asset->id, 'position' => 0, 'role' => 'item']);
        $version = function (EditorialPost $p, string $status, int $daysOld) {
            $v = EditorialPostVersion::create(['company_id' => $p->company_id, 'editorial_post_id' => $p->id, 'number' => (int) EditorialPostVersion::where('editorial_post_id', $p->id)->max('number') + 1, 'status' => $status]);
            DB::table('editorial_post_versions')->where('id', $v->id)->update(['updated_at' => now()->subDays($daysOld)]);

            return $v;
        };

        // 1. Versão substituída há 31 dias: o media sai e, sem outras versões, é apagado.
        $p = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'P', 'format' => 'Reels', 'channel' => 'instagram', 'stage' => 'production']);
        $oldAsset = $make(40);
        $link($version($p, 'superseded', 31)->id, $oldAsset);
        // Substituída há 10 dias: fica.
        $recentAsset = $make(40);
        $link($version($p, 'superseded', 10)->id, $recentAsset);

        // 2. Publicada há 13 meses: sai o original, ficam as miniaturas.
        $pub = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => now()->subMonths(13)->toDateString(), 'title' => 'Antiga', 'format' => 'Reels', 'channel' => 'instagram', 'stage' => 'published']);
        $pubAsset = $make(400);
        $pv = $version($pub, 'approved', 390);
        $link($pv->id, $pubAsset);
        $pub->forceFill(['approved_version_id' => $pv->id, 'current_version_id' => $pv->id])->save();

        // 3. Enviados e nunca usados: 10 dias apagado, 2 dias fica. 4. Envio expirado.
        $orphanOld = $make(10);
        $orphanNew = $make(2);
        $upload = MediaUpload::create(['company_id' => $this->a->id, 'kind' => 'image', 'original_name' => 'a.jpg', 'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'expires_at' => now()->subHour()]);
        Storage::disk('media')->put($upload->tempPath(), 'x');

        $disk = $this->mock(DiskUsage::class, fn ($m) => $m->shouldReceive('percentUsed')->andReturn(75.0));
        $stats = (new MediaRetentionJob())->handle($media, $disk, app(AlertService::class));
        (new MediaRetentionJob())->handle($media, $disk, app(AlertService::class));

        $this->assertNull(MediaAsset::find($oldAsset->id));
        Storage::disk('media')->assertMissing("{$oldAsset->dir}/original.jpg");
        $this->assertNotNull(MediaAsset::find($recentAsset->id));
        $pubAsset->refresh();
        $this->assertNotNull($pubAsset->original_deleted_at);
        Storage::disk('media')->assertMissing("{$pubAsset->dir}/original.jpg");
        Storage::disk('media')->assertExists("{$pubAsset->dir}/thumb.webp");
        $this->assertNull($pubAsset->signedUrl('original'));
        $this->assertNull(MediaAsset::find($orphanOld->id));
        $this->assertNotNull(MediaAsset::find($orphanNew->id));
        $this->assertNull(MediaUpload::find($upload->id));
        $this->assertSame(1, $stats['uploads']);
        $this->assertSame(1, Alert::where('company_id', $this->x->id)->where('title', 'Disco do servidor acima de 70%')->count(), 'Um aviso por dia.');
    }
}
