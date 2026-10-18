<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Models\Development;
use App\Models\SiteServerToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsConsultantSite;
use Tests\TestCase;

/**
 * Site do consultor: a API do site (servidor a servidor). O token do contentor e o host; só
 * conteúdo publicado; nenhum campo interno nem dado pessoal; cabeçalhos de cache.
 */
class SiteApiTest extends TestCase
{
    use BuildsConsultantSite;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildConsultantSite();
    }

    public function test_without_a_valid_token_the_api_answers_401(): void
    {
        $this->siteApi('GET', 'profile', token: '')->assertStatus(401);
        $this->siteApi('GET', 'profile', token: 'xst_nao-existe')->assertStatus(401);
        SiteServerToken::query()->update(['revoked_at' => now()]);
        $this->siteApi('GET', 'profile')->assertStatus(401);
    }

    public function test_the_consultant_comes_from_the_host_and_an_unknown_host_is_404(): void
    {
        $this->siteApi('GET', 'profile')->assertOk()->assertJsonPath('data.public_name', 'Consultor Exemplo');
        $this->siteApi('GET', 'profile', host: 'CONSULTOR-EXEMPLO.TEST:443')->assertOk(); // host normalizado
        $this->siteApi('GET', 'profile', host: 'outro.test')->assertNotFound();
        $this->siteApi('GET', 'profile', host: null)->assertNotFound();
    }

    public function test_a_host_of_a_site_not_linked_to_the_token_is_404(): void
    {
        $other = $this->makeConsultantCompany('Outro Consultor');
        $this->makeSite($other, 'outro-consultor.test');
        $this->siteApi('GET', 'profile', host: 'outro-consultor.test')->assertNotFound();
    }

    public function test_module_off_or_draft_site_is_404(): void
    {
        $this->site->forceFill(['site_status' => 'rascunho'])->save();
        $this->siteApi('GET', 'profile')->assertNotFound();
        $this->site->forceFill(['site_status' => 'publicado'])->save();
        app(\App\Services\CompanyModuleService::class)->disable($this->company->id, 'site_consultor');
        $this->siteApi('GET', 'profile')->assertNotFound();
    }

    public function test_the_profile_takes_name_whatsapp_socials_pixel_and_data_controller_from_the_company(): void
    {
        $data = $this->siteApi('GET', 'profile')->assertOk()->json('data');
        $this->assertSame('Consultor Exemplo', $data['public_name']);
        $this->assertSame('+351900000000', $data['whatsapp']['number']);
        $this->assertSame(['instagram' => 'https://www.instagram.com/consultor.exemplo'], $data['socials']);
        $this->assertSame('123456789012', $data['measurement']['meta_pixel_id']);
        $this->assertSame(24, $data['contact_retention_months']);
        $this->assertSame('publicado', $data['site_status']);
        $this->assertSame('#F7F1E7', $data['colors']['cream']);
        $this->assertSame(['legal_name' => 'Consultor Exemplo (fictício)', 'nif' => '999999990', 'address' => 'Rua Exemplo, 1, 4000-000', 'email' => 'geral@consultor-exemplo.invalid'], $data['data_controller']);
        $this->site->forceFill(['controller_is_company' => false, 'controller_legal_name' => 'Agência Exemplo (fictícia)', 'controller_nif' => '999999990',
            'controller_address' => 'Avenida Exemplo, Porto', 'controller_email' => 'rgpd@agencia-exemplo.invalid'])->save();
        $this->assertSame('Agência Exemplo (fictícia)', $this->siteApi('GET', 'profile')->json('data.data_controller.legal_name'));
    }

    public function test_reads_have_cache_headers(): void
    {
        $r = $this->siteApi('GET', 'profile')->assertOk();
        $this->assertStringContainsString('public', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=60', (string) $r->headers->get('Cache-Control'));
        $this->assertNotEmpty($r->headers->get('ETag'));
    }

    public function test_only_published_and_visible_properties_leave_the_api(): void
    {
        $visible = $this->publishedProperty(['title' => 'Visível']);
        $reserved = $this->publishedProperty(['title' => 'Reservado', 'status' => 'reservado']);
        $draft = $this->makeProperty(['title' => 'Rascunho']);
        $this->fakeImage($draft);
        $closedHidden = $this->publishedProperty(['title' => 'Fechado sem autorização', 'status' => 'fechado', 'closed_at' => now()->toDateString()]);
        $closedShown = $this->publishedProperty(['title' => 'Vendido com autorização', 'status' => 'fechado', 'closed_at' => now()->toDateString(), 'show_as_sold' => true]);
        $noEnergy = $this->publishedProperty(['title' => 'Sem classe']);
        $noEnergy->forceFill(['energy_class' => 'em_processo'])->save(); // apagada depois de publicar
        $other = $this->makeConsultantCompany('Outra Empresa');
        $this->publishedProperty(['title' => 'De outra empresa'], $other);

        $titles = collect($this->siteApi('GET', 'properties')->assertOk()->json('data.items'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Reservado', 'Vendido com autorização', 'Visível'], $titles);
        $this->siteApi('GET', "properties/{$draft->slug}")->assertNotFound();
        $this->siteApi('GET', "properties/{$closedHidden->slug}")->assertNotFound();
        $this->siteApi('GET', "properties/{$noEnergy->slug}")->assertNotFound();
        $this->siteApi('GET', "properties/{$visible->slug}")->assertOk()->assertJsonPath('data.availability', 'disponivel');
        $this->siteApi('GET', "properties/{$reserved->slug}")->assertOk()->assertJsonPath('data.availability', 'reservado');
        $this->siteApi('GET', "properties/{$closedShown->slug}")->assertOk()->assertJsonPath('data.availability', 'vendido');
    }

    public function test_no_internal_field_leaves_the_api(): void
    {
        $p = $this->publishedProperty(['price_on_request' => true, 'contacts_30' => 7, 'visits_30' => 3, 'approx_lat' => 41.168421, 'approx_lng' => -8.604157, 'approx_radius_m' => 100,
            'chave_nova_url' => 'https://www.chavenova.com/imovel/apartamento-t2-exemplo-ficticio/123', 'chave_nova_reference' => 'CN-123', 'description' => 'Texto.']);
        $json = $this->siteApi('GET', "properties/{$p->slug}")->assertOk()->json('data');
        foreach (['id', 'company_id', 'contacts_30', 'visits_30', 'interest_updated_at', 'listed_at', 'closed_at', 'site_status', 'show_as_sold', 'development_id', 'parish_id', 'approx_lat'] as $k) {
            $this->assertArrayNotHasKey($k, $json, "campo interno {$k}");
        }
        $this->assertNull($json['price'], 'preço sob consulta não sai');
        $this->assertTrue($json['price_on_request']);
        $this->assertSame('CN-123', $json['reference']);
        $this->assertSame(['lat' => 41.168, 'lng' => -8.604, 'radius_m' => 300, 'exact' => false], $json['location'], 'só o centro do círculo, arredondado, com 300 m no mínimo');
        $this->assertStringStartsWith('http', $json['cover']['src']);
        $this->assertStringContainsString('480w', $json['cover']['srcset']);
    }

    public function test_no_personal_data_leaves_the_api(): void
    {
        $this->publishedProperty();
        \App\Models\InboundContact::create(['company_id' => $this->company->id, 'channel' => 'site', 'consultant_site_id' => $this->site->id, 'type' => 'contacto', 'name' => 'Pessoa Fictícia',
            'email' => 'pessoa@exemplo.invalid', 'phone' => '+351900000001', 'consent_privacy_at' => now(), 'policy_version' => '2026-10', 'event_id' => (string) \Illuminate\Support\Str::uuid()]);
        $body = '';
        foreach (['profile', 'properties', 'developments', 'blog'] as $uri) {
            $body .= $this->siteApi('GET', $uri)->assertOk()->getContent();
        }
        foreach (['Pessoa Fictícia', 'pessoa@exemplo.invalid', '+351900000001', 'admin@consultor-exemplo.invalid', $this->admin->name] as $pii) {
            $this->assertStringNotContainsString($pii, $body);
        }
        $this->assertStringNotContainsString('token', strtolower((string) json_encode($this->siteApi('GET', 'profile')->json('data'))));
    }

    public function test_developments_show_only_published_and_the_price_from_comes_from_available_units(): void
    {
        $d = Development::create(['company_id' => $this->company->id, 'name' => 'Empreendimento Exemplo', 'slug' => 'empreendimento-exemplo', 'parish_id' => $this->parish,
            'lat' => 41.17, 'lng' => -8.6, 'construction_phase' => 'estrutura', 'expected_completion' => '2027-12-01', 'energy_class_expected' => 'A', 'payment_plan' => '20% no contrato-promessa.']);
        $this->fakeImage($d);
        $d->forceFill(['site_status' => 'publicado', 'published_at' => now()])->save();
        Development::create(['company_id' => $this->company->id, 'name' => 'Rascunho', 'slug' => 'rascunho', 'construction_phase' => 'projeto']);
        $this->publishedProperty(['development_id' => $d->id, 'price' => 300000, 'construction_state' => 'em_construcao']);
        $this->publishedProperty(['development_id' => $d->id, 'price' => 280000, 'status' => 'reservado']);
        $this->publishedProperty(['development_id' => $d->id, 'price' => 200000, 'status' => 'fechado', 'closed_at' => now()->toDateString(), 'show_as_sold' => true]);
        $this->publishedProperty(['development_id' => $d->id, 'price' => 100000, 'price_on_request' => true]);
        $this->makeProperty(['development_id' => $d->id, 'price' => 50000]); // fração por publicar

        $items = $this->siteApi('GET', 'developments')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame(280000, $items[0]['price_from']);
        $this->assertSame(3, $items[0]['units_available']);
        $this->assertSame(['lat' => 41.17, 'lng' => -8.6, 'exact' => true], $items[0]['location']);
        $detail = $this->siteApi('GET', 'developments/empreendimento-exemplo')->assertOk()->json('data');
        $this->assertCount(4, $detail['units']);
        $this->assertSame('2027-12', $detail['expected_completion']);
        $this->siteApi('GET', 'developments/rascunho')->assertNotFound();
    }

    public function test_the_blog_only_shows_published_articles_of_the_company(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $mk = fn (string $slug, string $status, $publishedAt, ?int $company = null) => DB::table('blogs')->insert(['company_id' => $company ?? $this->company->id, 'user_id' => $user->id,
            'title' => "Artigo {$slug}", 'slug' => $slug, 'content' => '<p>Texto</p><script>x()</script>', 'status' => $status, 'published_at' => $publishedAt,
            'category' => 'Vender casa', 'created_at' => now(), 'updated_at' => now()]);
        $mk('publicado', 'published', now()->subDay());
        $mk('rascunho', 'draft', null);
        $mk('em-revisao', 'in_review', null);
        $mk('agendado', 'approved', now()->addDay());
        $mk('futuro', 'published', now()->addDay());
        $other = $this->makeConsultantCompany('Outra');
        $mk('de-outra', 'published', now()->subDay(), $other->id);

        $items = $this->siteApi('GET', 'blog')->assertOk()->json('data.items');
        $this->assertSame(['publicado'], array_column($items, 'slug'));
        $this->assertSame('Consultor Exemplo', $items[0]['author']['name']);
        $this->assertSame('vender-casa', $items[0]['category_slug']);
        $this->assertArrayNotHasKey('content', $items[0]);
        $this->assertSame(['publicado'], array_column($this->siteApi('GET', 'blog?category=vender-casa')->json('data.items'), 'slug'));
        $this->assertSame([], $this->siteApi('GET', 'blog?category=outra')->json('data.items'));
        $post = $this->siteApi('GET', 'blog/publicado')->assertOk()->json('data');
        $this->assertStringNotContainsString('<script', $post['content']);
        foreach (['rascunho', 'em-revisao', 'agendado', 'futuro', 'de-outra'] as $slug) {
            $this->siteApi('GET', "blog/{$slug}")->assertNotFound();
        }
    }

    public function test_property_filters(): void
    {
        $this->publishedProperty(['title' => 'Moradia', 'kind' => 'moradia', 'typology' => 'T4', 'price' => 600000]);
        $this->publishedProperty(['title' => 'T1 Bonfim', 'typology' => 'T1', 'price' => 180000, 'parish_id' => $this->otherParish, 'construction_state' => 'usado']);
        $this->publishedProperty(['title' => 'Sob consulta', 'price_on_request' => true]);
        $titles = fn (string $q) => collect($this->siteApi('GET', "properties?{$q}")->assertOk()->json('data.items'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Moradia'], $titles('kind=moradia'));
        $this->assertSame(['T1 Bonfim'], $titles('parish=131202'));
        $this->assertSame(['T1 Bonfim'], $titles('price_max=200000'));
        $this->assertSame(['Moradia'], $titles('price_min=500000'));
        $this->assertSame(['T1 Bonfim'], $titles('construction_state=usado'));
        $this->assertCount(3, $titles('municipality=1312'));
        $this->siteApi('GET', 'properties?kind=castelo')->assertStatus(422);
    }

    public function test_drafts_only_appear_in_preview_with_the_draft_flag(): void
    {
        $published = $this->publishedProperty(['title' => 'Publicado']);
        $draft = $this->makeProperty(['title' => 'Rascunho sem endereço', 'slug' => null, 'energy_class' => null]);
        $closedHidden = $this->makeProperty(['title' => 'Fechado sem autorização', 'status' => 'fechado', 'closed_at' => now()->toDateString()]);
        $dev = Development::create(['company_id' => $this->company->id, 'name' => 'Empreendimento em rascunho', 'slug' => 'empreendimento-rascunho', 'construction_phase' => 'projeto']);

        // Fora da pré-visualização: nunca.
        $this->assertSame(['Publicado'], collect($this->siteApi('GET', 'properties')->json('data.items'))->pluck('title')->all());
        $this->siteApi('GET', "properties/rascunho-{$draft->id}")->assertNotFound();
        $this->assertSame([], $this->siteApi('GET', 'developments')->json('data.items'));

        // Na pré-visualização: também os rascunhos, marcados; o fechado sem autorização continua fora.
        $token = \App\Services\ConsultantSite\SitePreview::issue($this->site->load('hosts'))['token'];
        $preview = fn (string $uri) => $this->json('GET', '/api/public/site/v1/' . $uri, [], ['Authorization' => 'Bearer ' . $this->token, 'X-Site-Host' => self::HOST, 'X-Site-Preview' => $token]);
        $items = collect($preview('properties')->assertOk()->json('data.items'));
        $this->assertEqualsCanonicalizing(['Publicado', 'Rascunho sem endereço'], $items->pluck('title')->all());
        $this->assertFalse($items->firstWhere('title', 'Publicado')['draft']);
        $this->assertTrue($items->firstWhere('title', 'Rascunho sem endereço')['draft']);
        $this->assertSame("rascunho-{$draft->id}", $items->firstWhere('title', 'Rascunho sem endereço')['slug']);
        $preview("properties/rascunho-{$draft->id}")->assertOk()->assertJsonPath('data.draft', true);
        $preview("properties/rascunho-{$published->id}")->assertNotFound(); // um publicado só pelo endereço dele
        $this->assertNotContains($closedHidden->title, $items->pluck('title')->all());
        $preview('developments')->assertOk()->assertJsonPath('data.items.0.draft', true);
        $preview('developments/empreendimento-rascunho')->assertOk();
    }

    public function test_the_list_brings_the_possible_filters(): void
    {
        $this->publishedProperty(['kind' => 'moradia', 'typology' => 'T4', 'price' => 600000, 'construction_state' => 'usado']);
        $this->publishedProperty(['typology' => 'T1', 'price' => 180000, 'parish_id' => $this->otherParish]);
        $this->publishedProperty(['price_on_request' => true]);
        $f = $this->siteApi('GET', 'properties')->assertOk()->json('data.facets');
        $this->assertSame(['apartamento' => 'Apartamento', 'moradia' => 'Moradia'], $f['kinds']);
        $this->assertSame(['T1', 'T2', 'T4'], $f['typologies']);
        $this->assertSame(['usado' => 'Usado'], $f['construction_states']);
        $this->assertSame(['Bonfim', 'Paranhos'], array_column($f['parishes'], 'name'));
        $this->assertSame([180000, 600000], [$f['price_min'], $f['price_max']]);
    }
}
