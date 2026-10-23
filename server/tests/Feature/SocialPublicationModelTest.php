<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\SocialPublication;
use App\Models\User;
use App\Services\Social\Publishing\FakeMetaPublishingClient;
use App\Services\Social\Publishing\MetaPublishingClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F2.1 · Modelo das publicações automáticas: uma social_publication por rede da publicação, o
 * interruptor da empresa e a caixa da publicação (desligados por omissão) e o cliente da Meta
 * simulado (nunca chama a Meta).
 */
class SocialPublicationModelTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500095771', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        \App\Models\EditorialMonth::create(['company_id' => $this->a->id, 'year' => 2026, 'month' => 11, 'state' => \App\Models\EditorialMonth::OPEN]);
    }

    private function makePost(array $extra = []): EditorialPost
    {
        return EditorialPost::create(array_merge([
            'company_id' => $this->a->id, 'publish_date' => '2026-11-20', 'title' => 'Menu de Natal', 'format' => 'Imagem única',
            'media_format' => 'ig_feed_image', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PLANNING,
        ], $extra))->fresh();
    }

    public function test_one_publication_per_network_row_with_the_states(): void
    {
        $post = $this->makePost();
        $network = $post->networks()->sole();
        $pub = SocialPublication::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id,
            'editorial_post_network_id' => $network->id, 'network' => 'instagram', 'scheduled_for' => now()->addDay()]);

        $pub->refresh();
        $this->assertSame([SocialPublication::STATUS_SCHEDULED, 0, true], [$pub->status, $pub->attempts, $pub->isPending()]);
        $this->assertSame('Agendado', $pub->present()['label']);
        $this->assertTrue($network->publication->is($pub));
        $this->assertSame(['scheduled', 'publishing', 'published', 'failed', 'cancelled'], SocialPublication::STATUSES);

        try {
            SocialPublication::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id,
                'editorial_post_network_id' => $network->id, 'network' => 'instagram']);
            $this->fail('duas publicações para a mesma rede');
        } catch (QueryException) {
            $this->assertSame(1, SocialPublication::count());
        }

        $post->delete();
        $this->assertSame(0, SocialPublication::count(), 'sai com a publicação');
    }

    public function test_company_switch_and_post_box_start_off_and_are_saved(): void
    {
        $post = $this->makePost();
        $this->assertFalse($post->auto_publish);
        $this->assertFalse((bool) $this->a->fresh()->auto_publishing_enabled);

        // O interruptor muda-se nas Integrações (integracoes.configurar); a Linha Editorial só o mostra.
        $switch = fn ($enabled) => $this->actingAs($this->adminA, 'sanctum')->putJson("/api/v1/companies/{$this->a->id}/integrations/social/auto-publishing", ['enabled' => $enabled]);
        $switch(true)->assertOk()->assertJsonPath('data.publishing.auto_publishing_enabled', true);
        $settings = fn (array $extra = []) => $this->actingAs($this->adminA, 'sanctum')->putJson("/api/v1/companies/{$this->a->id}/editorial/workflow-settings",
            ['content_approval_required' => false, 'internal_review_required' => false] + $extra);
        $settings(['auto_publishing_enabled' => false])->assertOk()->assertJsonPath('data.auto_publishing_enabled', true); // a Linha Editorial já não o muda
        $this->actingAs($this->adminA, 'sanctum')->getJson("/api/v1/companies/{$this->a->id}/integrations/social")->assertOk()->assertJsonPath('data.publishing.auto_publishing_enabled', true);
        $switch('talvez')->assertStatus(422);
        $switch(false)->assertOk()->assertJsonPath('data.publishing.auto_publishing_enabled', false);

        $update = fn (array $extra = []) => $this->actingAs($this->adminA, 'sanctum')->putJson("/api/v1/companies/{$this->a->id}/editorial/posts/{$post->id}",
            ['title' => 'Menu de Natal', 'publish_date' => '2026-11-20', 'format' => 'Imagem única', 'channel' => 'social'] + $extra);
        $r = $update(['auto_publish' => true]);
        $this->assertSame(200, $r->status(), (string) $r->json('message'));
        $this->assertTrue($post->fresh()->auto_publish);
        $update()->assertOk();
        $this->assertTrue($post->fresh()->auto_publish, 'sem o campo, não muda');
        $update(['auto_publish' => 'talvez'])->assertStatus(422);

        $workflow = $this->actingAs($this->adminA, 'sanctum')->getJson("/api/v1/companies/{$this->a->id}/editorial/posts/{$post->id}/workflow")->assertOk();
        $this->assertSame([true, false], [$workflow->json('data.post.auto_publish'), $workflow->json('data.post.auto_publishing_enabled')]);
        $this->assertNull($workflow->json('data.post.networks.0.publication'));
    }

    public function test_the_simulated_client_answers_without_calling_the_meta(): void
    {
        $this->assertSame('fake', config('social_publishing.driver'), 'fora de produção, o simulado');
        $client = app(MetaPublishingClient::class);
        $this->assertInstanceOf(FakeMetaPublishingClient::class, $client);

        $this->assertSame(['ok' => true, 'used' => 0, 'total' => 100], $client->instagramQuota('17841', 'tok'));
        $container = $client->createInstagramContainer('17841', 'tok', ['media_type' => 'IMAGE', 'image_url' => 'https://r2/x.jpg', 'caption' => 'Olá']);
        $this->assertTrue($container['ok']);
        $this->assertSame('FINISHED', $client->containerStatus($container['container_id'], 'tok')['status']);
        $media = $client->publishInstagram('17841', 'tok', $container['container_id']);
        $this->assertStringEndsWith('.invalid/p/' . $media['media_id'], $client->permalink('instagram', $media['media_id'], 'tok')['permalink']);
        $this->assertTrue($client->publishFacebook('1001', 'tok', ['type' => 'photo', 'urls' => ['https://r2/x.jpg']])['ok']);

        // Uma falha simulada vale uma vez (ou sempre).
        $client->failures['publishInstagram'] = FakeMetaPublishingClient::failure(MetaPublishingClient::KIND_TRANSIENT, 'Limite de pedidos.', '4');
        $this->assertSame(['ok' => false, 'kind' => 'transient', 'code' => '4', 'message' => 'Limite de pedidos.'], $client->publishInstagram('17841', 'tok', 'c-1'));
        $this->assertTrue($client->publishInstagram('17841', 'tok', 'c-1')['ok']);
        $this->assertCount(3, $client->callsTo('publishInstagram'));
        Http::assertNothingSent();
    }
}
