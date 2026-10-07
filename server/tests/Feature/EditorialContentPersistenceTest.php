<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "O que a pessoa escreve nunca se perde", do lado do servidor: as ações de ficheiros
 * (enviar, ordenar, remover, capa) nunca apagam nem substituem o texto gravado; numa versão
 * congelada, a versão seguinte leva o texto todo; gravar só alguns campos não apaga os outros;
 * a gravação automática (vários pedidos seguidos) fica na mesma versão de rascunho.
 */
class EditorialContentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private User $userA;
    private EditorialPost $post;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500098001', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'Menu',
            'format' => 'Carrossel', 'channel' => 'instagram', 'media_format' => 'ig_carousel', 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $this->post->networks()->create(['company_id' => $this->a->id, 'network' => 'facebook', 'media_format' => 'fb_photos', 'position' => 1]);
    }

    private function url(string $suffix): string
    {
        return "/api/v1/companies/{$this->a->id}/editorial/posts/{$this->post->id}{$suffix}";
    }

    private function asset(): MediaAsset
    {
        $dir = 'company_' . $this->a->id . '/' . uniqid('a', true);
        Storage::disk('media')->put("{$dir}/thumb.webp", 'x');

        return MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => $dir, 'original_name' => 'f.jpg', 'extension' => 'jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1, 'width' => 1080, 'height' => 1350, 'sha256' => hash('sha256', $dir),
            'variants' => ['thumb' => 'thumb.webp', 'preview' => 'thumb.webp'], 'status' => MediaAsset::READY]);
    }

    private function content(array $data)
    {
        return $this->actingAs($this->userA, 'sanctum')->putJson($this->url('/content'), $data);
    }

    private function media(array $ids, ?int $cover = null)
    {
        return $this->actingAs($this->userA, 'sanctum')->putJson($this->url('/media'), ['items' => $ids, 'cover_id' => $cover]);
    }

    private function current(\Illuminate\Testing\TestResponse $r): array
    {
        $id = $r->json('data.post.current_version_id');

        return collect($r->json('data.versions'))->firstWhere('id', $id);
    }

    private const TEXT = [
        'caption' => 'O menu de São Martinho.', 'hashtags' => ['#saomartinho', '#porto'], 'cta' => 'Reserve já',
        'first_comment' => 'Primeiro comentário', 'network_captions' => ['facebook' => 'Só no Facebook.'],
    ];

    private function assertText(array $version): void
    {
        $this->assertSame(['O menu de São Martinho.', ['#saomartinho', '#porto'], 'Reserve já', 'Primeiro comentário', ['facebook' => 'Só no Facebook.']],
            [$version['caption'], $version['hashtags'], $version['cta'], $version['first_comment'], $version['network_captions']]);
    }

    public function test_file_actions_never_touch_the_saved_text(): void
    {
        $this->content(self::TEXT)->assertOk();
        [$a, $b, $c] = [$this->asset()->id, $this->asset()->id, $this->asset()->id];

        // Enviar um, depois dois seguidos, ordenar, remover e escolher capa: o texto fica igual.
        $this->assertText($this->current($this->media([$a])->assertOk()));
        $this->media([$a, $b])->assertOk();
        $this->assertText($this->current($this->media([$a, $b, $c])->assertOk()));
        $this->assertText($this->current($this->media([$c, $a, $b])->assertOk()));
        $this->assertText($this->current($this->media([$c, $b])->assertOk()));
        $r = $this->media([$c, $b], $a)->assertOk();
        $this->assertText($this->current($r));
        $this->assertSame(1, $r->json('data.versions.0.number'), 'Tudo na mesma versão de rascunho.');
    }

    public function test_frozen_version_copies_all_the_text_to_the_next_one(): void
    {
        $this->content(self::TEXT)->assertOk();
        $this->media([$this->asset()->id, $this->asset()->id])->assertOk();
        $this->actingAs($this->userA, 'sanctum')->postJson($this->url('/move'), ['stage' => 'client_review'])->assertOk();

        // Ficheiro novo numa versão enviada: nasce a versão 2, com o texto todo.
        $r = $this->media([$this->asset()->id, $this->asset()->id, $this->asset()->id])->assertOk();
        $v = $this->current($r);
        $this->assertSame([2, 'draft'], [$v['number'], $v['status']]);
        $this->assertText($v);
    }

    public function test_saving_some_fields_keeps_the_others(): void
    {
        $this->content(self::TEXT)->assertOk();
        // A gravação automática envia o conteúdo; um pedido só com a legenda não apaga o resto.
        $v = $this->current($this->content(['caption' => 'Outra legenda.'])->assertOk());
        $this->assertSame(['Outra legenda.', ['#saomartinho', '#porto'], 'Reserve já', ['facebook' => 'Só no Facebook.']],
            [$v['caption'], $v['hashtags'], $v['cta'], $v['network_captions']]);
    }

    public function test_autosave_requests_in_a_row_stay_in_the_same_draft(): void
    {
        foreach (['O', 'O menu', 'O menu de São Martinho.'] as $text) {
            $r = $this->content(['caption' => $text, 'hashtags' => [], 'cta' => '', 'first_comment' => '', 'network_captions' => []])->assertOk();
        }
        $v = $this->current($r);
        $this->assertSame([1, 'O menu de São Martinho.'], [$v['number'], $v['caption']]);
        $this->assertSame(1, DB::table('editorial_post_versions')->where('editorial_post_id', $this->post->id)->count());

        // Enviar um ficheiro depois da gravação automática: o texto fica.
        $this->assertSame('O menu de São Martinho.', $this->current($this->media([$this->asset()->id])->assertOk())['caption']);
    }
}
