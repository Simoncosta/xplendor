<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Jobs\RevalidateConsultantSiteJob;
use App\Models\ConsultantSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsConsultantSite;
use Tests\TestCase;

/**
 * Site do consultor: o perfil do site (só o exclusivo do site; o resto vem da empresa), publicar
 * só com a mediação, o responsável pelo tratamento e as ligações legais, os ficheiros do perfil,
 * os hosts (só o root) e a revalidação do site pela rede interna.
 */
class ConsultantSiteProfileTest extends TestCase
{
    use BuildsConsultantSite;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('public');
        $this->buildConsultantSite();
    }

    private function api(string $method, string $uri, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->json($method, "/api/v1/companies/{$this->company->id}/{$uri}", $data);
    }

    public function test_the_profile_shows_the_company_data_and_saves_only_the_site_fields(): void
    {
        $r = $this->api('GET', 'consultant-site')->assertOk();
        $this->assertSame('Consultor Exemplo', $r->json('data.company.public_name'));
        $this->assertSame('+351900000000', $r->json('data.company.whatsapp_number'));
        $this->assertTrue($r->json('data.controller_is_company'));
        $this->assertSame([], $r->json('data.publish_missing'));

        $this->api('PUT', 'consultant-site', [
            'hero_promise' => 'Vender a sua casa com um plano claro.', 'intro_youtube' => 'https://youtu.be/AbCdEfGhIjK', 'intro_video_enabled' => true,
            'ga4_id' => 'G-ABC123', 'colors' => ['amber' => '#FFB300', 'outra' => '#000000'], 'sections' => ['credit' => true, 'desconhecida' => true],
            'parish_ids' => [$this->parish], 'whatsapp_messages' => ['property' => ['popup' => 'Pergunte-me o que quiser.']],
            'public_name' => 'Ignorado', 'meta_pixel_id' => '1',
        ])->assertOk()->assertJsonPath('data.intro_youtube_id', 'AbCdEfGhIjK')->assertJsonPath('data.colors.amber', '#FFB300')
            ->assertJsonPath('data.sections.credit', true)->assertJsonPath('data.whatsapp_messages.property.popup', 'Pergunte-me o que quiser.')
            ->assertJsonPath('data.company.public_name', 'Consultor Exemplo');
        $s = $this->site->fresh();
        $this->assertArrayNotHasKey('outra', $s->colors);
        $this->assertArrayNotHasKey('desconhecida', $s->sections);
        $this->assertSame([$this->parish], $s->parishes()->pluck('parishes.id')->all());
        $this->api('PUT', 'consultant-site', ['intro_video_enabled' => true, 'intro_youtube' => ''])->assertStatus(422);
        $this->api('PUT', 'consultant-site', ['ga4_id' => 'UA-1'])->assertStatus(422)->assertJsonValidationErrors('ga4_id');
        $this->api('PUT', 'consultant-site', ['controller_nif' => '12'])->assertStatus(422)->assertJsonValidationErrors('controller_nif');
    }

    public function test_the_site_does_not_publish_without_brokerage_data_controller_and_legal_links(): void
    {
        $this->site->forceFill(['site_status' => 'rascunho', 'brokerage_ami' => null, 'legal_links' => null])->save();
        $this->company->forceFill(['nipc' => '', 'email' => null])->save();
        $missing = implode(' | ', $this->api('POST', 'consultant-site/publish')->assertStatus(422)->json('errors.publish'));
        foreach (['licença AMI', 'o NIF do responsável', 'o email para os pedidos', 'livro de reclamações', 'resolução alternativa'] as $needle) {
            $this->assertStringContainsString($needle, $missing);
        }
        // O responsável é outra entidade (por exemplo, a agência): os campos do perfil.
        $this->api('PUT', 'consultant-site', ['controller_is_company' => false, 'controller_legal_name' => 'Agência Exemplo (fictícia)', 'controller_nif' => '999999990',
            'controller_address' => 'Avenida Exemplo, Porto', 'controller_email' => 'rgpd@agencia-exemplo.invalid', 'brokerage_ami' => '12345',
            'legal_links' => ['complaints_book_url' => 'https://www.livroreclamacoes.pt', 'ral' => [['name' => 'Entidade Exemplo', 'url' => 'https://ral.exemplo.invalid']]]])->assertOk();
        $this->api('POST', 'consultant-site/publish')->assertOk()->assertJsonPath('data.site_status', 'publicado');
        // Apagar um obrigatório tira o site do ar.
        $this->api('PUT', 'consultant-site', ['controller_is_company' => false, 'controller_email' => null])->assertOk()->assertJsonPath('data.site_status', 'rascunho');
    }

    public function test_the_data_controller_has_no_default_and_publishing_requires_the_choice(): void
    {
        $other = $this->makeConsultantCompany('Sem Escolha');
        $admin = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $r = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$other->id}/consultant-site")->assertOk();
        $this->assertNull($r->json('data.controller_is_company'));
        $this->assertSame(['legal_name' => null, 'nif' => null, 'address' => null, 'email' => null, 'is_company' => null], $r->json('data.data_controller'));
        $this->assertContains('Escolha o responsável pelo tratamento dos dados: a própria empresa ou outra entidade.', $r->json('data.publish_missing'));
        $site = ConsultantSite::where('company_id', $other->id)->sole();
        $site->hosts()->create(['host' => 'sem-escolha.test', 'is_primary' => true]);
        $site->forceFill(['brokerage_legal_name' => 'Mediação Exemplo (fictícia)', 'brokerage_ami' => '1', 'privacy_policy_version' => '2026-10',
            'legal_links' => ['complaints_book_url' => 'https://www.livroreclamacoes.pt', 'ral' => [['name' => 'Exemplo', 'url' => 'https://ral.exemplo.invalid']]]])->save();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/companies/{$other->id}/consultant-site/publish")->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/companies/{$other->id}/consultant-site", ['controller_is_company' => true])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/companies/{$other->id}/consultant-site/publish")->assertOk();
        // Voltar a "por escolher" tira o site do ar.
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/companies/{$other->id}/consultant-site", ['controller_is_company' => null])->assertOk()
            ->assertJsonPath('data.site_status', 'rascunho');
    }

    public function test_preview_opens_a_draft_site_by_a_signed_temporary_link_without_measurement(): void
    {
        $this->site->forceFill(['site_status' => 'rascunho'])->save();
        $p = $this->publishedProperty();
        $this->siteApi('GET', 'profile')->assertNotFound(); // sem o link, o rascunho continua a dar 404

        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        \App\Access\CompatibilityMigration::run();
        $link = $this->api('POST', 'consultant-site/preview', as: $user)->assertOk()->json('data'); // site_consultor.ver chega
        $this->assertStringStartsWith('https://' . self::HOST . '/pre-visualizar?token=', $link['url']);
        $token = $link['token'];
        $preview = fn (string $method, string $uri, array $data = [], ?string $t = null) => $this->json($method, '/api/public/site/v1/' . $uri, $data, [
            'Authorization' => 'Bearer ' . $this->token, 'X-Site-Host' => self::HOST, 'X-Site-Preview' => $t ?? $token, 'Accept' => 'application/json']);

        $r = $preview('GET', 'profile')->assertOk();
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $r->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $r->headers->get('X-Robots-Tag'));
        $this->assertSame('active', $r->headers->get('X-Site-Preview'));
        $preview('GET', "properties/{$p->slug}")->assertOk();
        // Sem medição nem contactos em pré-visualização.
        $preview('POST', 'events', ['events' => [['event' => 'view', 'subject' => 'property', 'slug' => $p->slug]]])->assertStatus(202)->assertJsonPath('data.counted', 0);
        $this->assertSame(0, \App\Models\SiteDailyCount::count());
        $preview('POST', 'contacts', ['type' => 'contacto', 'name' => 'Teste', 'email' => 'x@exemplo.invalid', 'consent_privacy' => true, 'policy_version' => '1'])
            ->assertStatus(202)->assertJsonPath('data.preview', true);
        $this->assertSame(0, \App\Models\InboundContact::count());

        // Assinatura alterada, link de outro site e link caducado: 404.
        $preview('GET', 'profile', t: $token . 'x')->assertNotFound();
        $other = $this->makeConsultantCompany('Outro');
        $otherSite = $this->makeSite($other, 'outro.test', 'rascunho');
        $preview('GET', 'profile', t: \App\Services\ConsultantSite\SitePreview::issue($otherSite->load('hosts'))['token'])->assertNotFound();
        $this->travel(61)->minutes();
        $preview('GET', 'profile')->assertNotFound();
    }

    public function test_preview_needs_a_host_and_the_site_module(): void
    {
        $this->site->hosts()->delete();
        $this->api('POST', 'consultant-site/preview')->assertStatus(422);
        app(\App\Services\CompanyModuleService::class)->disable($this->company->id, 'site_consultor');
        $this->api('POST', 'consultant-site/preview')->assertForbidden();
    }

    public function test_publishing_the_site_is_a_decision_of_the_administrator(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        \App\Access\CompatibilityMigration::run();
        $this->api('GET', 'consultant-site', as: $user)->assertOk();
        $this->api('PUT', 'consultant-site', ['hero_promise' => 'x'], $user)->assertForbidden();
        $this->api('POST', 'consultant-site/publish', as: $user)->assertForbidden();
    }

    public function test_profile_files_are_checked_by_content_and_unsafe_svg_is_refused(): void
    {
        $svg = fn (string $body) => UploadedFile::fake()->createWithContent('assinatura.svg', $body);
        $post = fn (string $slot, UploadedFile $f) => $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/companies/{$this->company->id}/consultant-site/assets/{$slot}", ['file' => $f], ['Accept' => 'application/json']);
        $post('signature', $svg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422);
        $post('signature', $svg('<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><path d="M0 0"/></svg>'))->assertStatus(422);
        $post('signature', UploadedFile::fake()->createWithContent('assinatura.png', "\x89PNG\r\n"))->assertStatus(422);
        $path = $post('signature', $svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg>'))->assertOk()->json('data.assets.signature');
        $this->assertStringStartsWith("/storage/company_{$this->company->id}/consultor-site/signature-", $path);
        Storage::disk('public')->assertExists(substr($path, strlen('/storage/')));
        $post('hero_video_mp4', UploadedFile::fake()->createWithContent('topo.mp4', 'não é um vídeo'))->assertStatus(422);
        $post('hero_video_mp4', UploadedFile::fake()->createWithContent('topo.mp4', "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 32)))->assertOk();
        $post('photo', UploadedFile::fake()->image('foto.png', 800, 1200))->assertOk();
        $this->api('DELETE', 'consultant-site/assets/signature')->assertOk()->assertJsonPath('data.assets.signature', null);
        Storage::disk('public')->assertMissing(substr($path, strlen('/storage/')));
        $this->api('POST', 'consultant-site/assets/outra')->assertNotFound();
    }

    public function test_hosts_are_only_for_root_and_unique_across_sites(): void
    {
        $root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/companies/{$this->company->id}/consultant-site/hosts", ['hosts' => []])->assertForbidden();
        $other = $this->makeConsultantCompany('Outro');
        $this->makeSite($other, 'ocupado.test');
        $this->actingAs($root, 'sanctum')->putJson("/api/v1/admin/companies/{$this->company->id}/consultant-site/hosts", ['hosts' => [['host' => 'ocupado.test']]])->assertStatus(422);
        $this->actingAs($root, 'sanctum')->putJson("/api/v1/admin/companies/{$this->company->id}/consultant-site/hosts", ['hosts' => [['host' => 'https://x.test/a']]])->assertStatus(422);
        $this->actingAs($root, 'sanctum')->putJson("/api/v1/admin/companies/{$this->company->id}/consultant-site/hosts", [
            'hosts' => [['host' => 'Consultor.Exemplo.Test'], ['host' => 'www.consultor-exemplo.test', 'is_primary' => true]],
        ])->assertOk()->assertJsonPath('data.hosts.0.host', 'www.consultor-exemplo.test')->assertJsonPath('data.hosts.0.is_primary', true);
        $hosts = $this->site->hosts()->pluck('host')->sort()->values()->all();
        $this->assertSame(['consultor.exemplo.test', 'www.consultor-exemplo.test'], $hosts);
    }

    public function test_changes_ask_the_site_to_revalidate_with_a_signed_request(): void
    {
        config(['consultor_site.revalidate_url' => 'http://xplendor-consultor:3000/api/revalidate', 'consultor_site.revalidate_secret' => 'segredo-de-teste']);
        Http::fake(['xplendor-consultor:3000/*' => Http::response(['ok' => true])]);
        $p = $this->publishedProperty(['slug' => 'apartamento-revalidar']);
        Http::assertSent(function (HttpRequest $r) {
            $body = $r->body();
            $data = json_decode($body, true);

            return $r->url() === 'http://xplendor-consultor:3000/api/revalidate'
                && $data['hosts'] === [self::HOST] && in_array('property:apartamento-revalidar', $data['tags'], true)
                && hash_equals(hash_hmac('sha256', $r->header('X-Revalidate-Timestamp')[0] . '.' . $body, 'segredo-de-teste'), $r->header('X-Revalidate-Signature')[0]);
        });
        // As contagens de interesse não mudam o site: nenhum pedido.
        $count = count(Http::recorded());
        $p->forceFill(['contacts_30' => 3, 'interest_updated_at' => now()->toDateString()])->save();
        $this->assertCount($count, Http::recorded(), json_encode(array_map(fn ($x) => $x[0]->body(), Http::recorded()->all())));
        // Um artigo do blog também revalida.
        \App\Models\Blog::create(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'title' => 'Artigo', 'slug' => 'artigo-x', 'content' => '<p>x</p>', 'status' => 'draft']);
        Http::assertSent(fn (HttpRequest $r) => in_array('blog:artigo-x', json_decode($r->body(), true)['tags'] ?? [], true));
    }

    public function test_without_a_revalidation_url_nothing_is_sent(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->publishedProperty();
        \Illuminate\Support\Facades\Queue::assertNotPushed(RevalidateConsultantSiteJob::class);
    }

    public function test_the_create_command_simulates_by_default_and_creates_the_company_with_the_module(): void
    {
        $before = \App\Models\Company::count();
        $this->artisan('consultor:site-create', ['name' => 'Consultor Novo', '--host' => 'consultor-novo.test'])->assertSuccessful();
        $this->assertSame($before, \App\Models\Company::count());
        $this->artisan('consultor:site-create', ['name' => 'Consultor Novo', '--host' => 'consultor-novo.test', '--execute' => true])->assertSuccessful();
        $site = ConsultantSite::whereHas('hosts', fn ($q) => $q->where('host', 'consultor-novo.test'))->sole();
        $this->assertSame('Consultor Novo', $site->publicName());
        $this->assertTrue(app(\App\Services\CompanyModuleService::class)->isEnabled($site->company_id, 'site_consultor'));
        $this->assertTrue(app(\App\Services\CompanyModuleService::class)->isEnabled($site->company_id, 'imoveis'));
        $this->assertSame('rascunho', $site->site_status);
        $this->artisan('consultor:site-create', ['name' => 'Repetido', '--host' => 'consultor-novo.test', '--execute' => true])->assertFailed();
        $this->artisan('consultor:site-token', ['--site' => [$site->company_id], '--name' => 'contentor'])->assertSuccessful();
        $this->assertSame(1, $site->fresh()->belongsToMany(\App\Models\SiteServerToken::class, 'site_server_token_sites')->count());
    }
}
