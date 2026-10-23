<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Models\Development;
use App\Models\ListingImage;
use App\Models\Property;
use App\Models\SiteDailyCount;
use App\Models\StoredFile;
use App\Services\Files\SystemFileCatalog;
use App\Support\Images\ImageMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsConsultantSite;
use Tests\TestCase;

/**
 * Site do consultor, na app: o site de cada imóvel (campos, fotografias, publicar) e os
 * empreendimentos. Sem classe energética não se publica; as fotografias perdem os metadados e
 * ficam nos Ficheiros (pastas "Imóveis" e "Empreendimentos"), como as dos veículos.
 */
class ListingSiteTest extends TestCase
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

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->json($method, "/api/v1/companies/{$this->company->id}/{$uri}", $data);
    }

    private function upload(Property|Development $owner, int $n = 2)
    {
        $files = [];
        for ($i = 0; $i < $n; $i++) {
            $files[] = UploadedFile::fake()->image("foto{$i}.jpg", 2400, 1600);
        }
        $base = $owner instanceof Property ? "properties/{$owner->id}" : "developments/{$owner->id}";

        return $this->actingAs($this->admin, 'sanctum')->post("/api/v1/companies/{$this->company->id}/{$base}/images", ['images' => $files], ['Accept' => 'application/json']);
    }

    public function test_a_property_without_energy_class_does_not_publish(): void
    {
        $p = $this->makeProperty(['energy_class' => null, 'slug' => null]);
        $this->upload($p, 1)->assertCreated();
        $r = $this->api('POST', "properties/{$p->id}/publish")->assertStatus(422);
        $this->assertStringContainsString('classe energética', implode(' ', $r->json('errors.publish')));
        $p->forceFill(['energy_class' => 'em_processo'])->save();
        $this->api('POST', "properties/{$p->id}/publish")->assertStatus(422);
        $p->forceFill(['energy_class' => 'C'])->save();
        $this->api('POST', "properties/{$p->id}/publish")->assertOk()->assertJsonPath('data.site_status', 'publicado');
        $this->assertNotNull($p->fresh()->slug, 'o endereço cria-se ao publicar');
        $this->assertNotNull($p->fresh()->published_at);
    }

    public function test_publishing_needs_parish_price_and_a_photo(): void
    {
        $p = $this->makeProperty(['parish_id' => null, 'price' => 0]);
        $missing = $this->api('POST', "properties/{$p->id}/publish")->assertStatus(422)->json('errors.publish');
        $this->assertCount(3, $missing);
        // A lista item a item, para a app levar ao campo em falta.
        $this->assertSame(['parish_id', 'price', 'photos'], array_column($this->api('GET', "properties/{$p->id}/site")->json('data.publish_checklist'), 'field'));
        $this->assertSame('fotografia', $this->api('GET', "properties/{$p->id}/site")->json('data.publish_checklist.2.label'));
        $p->forceFill(['parish_id' => $this->parish, 'price_on_request' => true])->save();
        $this->upload($p, 1)->assertCreated();
        $this->api('POST', "properties/{$p->id}/publish")->assertOk();
        // Fechado sem autorização do proprietário: sai do site ao gravar.
        $p->forceFill(['status' => 'fechado', 'closed_at' => now()->toDateString()])->save();
        $this->api('PUT', "properties/{$p->id}/site", ['show_as_sold' => false])->assertOk()->assertJsonPath('data.site_status', 'rascunho');
    }

    public function test_photos_get_three_widths_an_original_without_metadata_order_and_cover(): void
    {
        $p = $this->makeProperty();
        $images = $this->upload($p, 3)->assertCreated()->json('data');
        $this->assertCount(3, $images);
        $this->assertTrue($images[0]['is_cover']);
        $first = ListingImage::find($images[0]['id']);
        foreach ([$first->path, $first->path_960, $first->path_480, $first->original_path] as $path) {
            Storage::disk('public')->assertExists(substr($path, strlen('/storage/')));
        }
        $this->assertSame([1600, 1067], [$first->width, $first->height]);
        $this->assertStringContainsString("company_{$this->company->id}/imoveis/imovel-{$p->id}/", $first->path);
        $this->assertFalse(ImageMetadata::inspect(Storage::disk('public')->path(substr($first->original_path, strlen('/storage/'))))['gps']);

        $ids = array_column($images, 'id');
        $after = $this->api('PUT', "properties/{$p->id}/images/order", ['ids' => array_reverse($ids), 'cover_id' => $ids[1]])->assertOk()->json('data');
        $this->assertSame($ids[1], $after[0]['id']);
        $this->assertTrue($after[0]['is_cover']);
        $cover = ListingImage::find($ids[1]);
        $this->api('DELETE', "properties/{$p->id}/images/{$ids[1]}")->assertOk();
        Storage::disk('public')->assertMissing(substr($cover->path, strlen('/storage/')));
        $this->assertSame(1, ListingImage::where('owner_id', $p->id)->where('is_cover', true)->count(), 'a capa passa para a seguinte');
        $this->upload($p, 1)->assertCreated();
        $this->api('PATCH', "properties/{$p->id}/images/{$ids[0]}", ['alt' => 'Sala com varanda'])->assertOk();
        $this->assertSame('Sala com varanda', ListingImage::find($ids[0])->alt);
        $this->api('DELETE', "properties/{$p->id}/images/999999")->assertNotFound();
    }

    public function test_photos_appear_in_the_files_like_the_vehicle_photos(): void
    {
        $p = $this->makeProperty(['reference' => 'REF-F', 'title' => 'Apartamento Exemplo']);
        $this->upload($p, 2)->assertCreated();
        // Sem o módulo Ficheiros, nada se regista (spike de 22/10).
        app(SystemFileCatalog::class)->sync($this->company->id, true);
        $this->assertSame(0, StoredFile::where('company_id', $this->company->id)->count());
        app(\App\Services\CompanyModuleService::class)->enable($this->company->id, 'ficheiros');
        app(SystemFileCatalog::class)->sync($this->company->id, true);
        $files = StoredFile::where('company_id', $this->company->id)->whereIn('source', ['imovel', 'imovel_original'])->get();
        $this->assertCount(4, $files);
        $this->assertTrue($files->every(fn ($f) => $f->is_system && $f->disk === 'public'));
        $folder = \App\Models\FileFolder::find($files->first()->folder_id);
        $this->assertSame('REF-F Apartamento Exemplo', $folder->name);
        $this->assertSame('Imóveis', \App\Models\FileFolder::find($folder->parent_id)->name);
    }

    public function test_site_fields_youtube_chave_nova_and_location(): void
    {
        $p = $this->makeProperty(['slug' => null]);
        $r = $this->api('PUT', "properties/{$p->id}/site", [
            'youtube' => 'https://youtube.com/shorts/AbCdEfGhIjK?feature=share', 'chave_nova_url' => 'https://www.chavenova.com/imovel/apartamento-t2-exemplo-ficticio/167849',
            'approx_lat' => 41.17, 'approx_lng' => -8.6, 'approx_radius_m' => 400, 'bedrooms' => 2, 'construction_state' => 'usado', 'featured' => true,
        ])->assertOk();
        $this->assertSame('AbCdEfGhIjK', $r->json('data.youtube_id'));
        $this->assertNotEmpty($r->json('data.slug'));
        $this->api('PUT', "properties/{$p->id}/site", ['youtube' => 'https://vimeo.com/123'])->assertStatus(422)->assertJsonValidationErrors('youtube');
        foreach (['https://chavenova.com.exemplo.invalid/x', 'http://www.chavenova.com/imovel/1', 'https://www.chavenova.pt/imovel/1', 'https://chavenova.pt/imovel/1',
            'https://outro.chavenova.com/imovel/1', 'https://www.chavenova.com:8443/imovel/1', 'https://utilizador@www.chavenova.com/imovel/1'] as $bad) {
            $this->api('PUT', "properties/{$p->id}/site", ['chave_nova_url' => $bad])->assertStatus(422)->assertJsonValidationErrors('chave_nova_url');
        }
        $this->api('PUT', "properties/{$p->id}/site", ['chave_nova_url' => 'https://chavenova.com/imovel/1'])->assertOk();
        $this->api('PUT', "properties/{$p->id}/site", ['approx_lat' => 41.17, 'approx_lng' => -8.6, 'approx_radius_m' => 50])->assertStatus(422)->assertJsonValidationErrors('approx_radius_m');
        $other = $this->makeProperty();
        $this->api('PUT', "properties/{$other->id}/site", ['slug' => $p->fresh()->slug])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->api('PUT', "properties/{$other->id}/site", ['slug' => 'Com Espaços'])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_the_list_summary_has_the_views_of_the_last_30_days(): void
    {
        $p = $this->publishedProperty();
        SiteDailyCount::create(['company_id' => $this->company->id, 'subject_type' => 'property', 'subject_id' => $p->id, 'day' => now()->toDateString(), 'event' => 'view', 'count' => 5]);
        SiteDailyCount::create(['company_id' => $this->company->id, 'subject_type' => 'property', 'subject_id' => $p->id, 'day' => now()->subDays(40)->toDateString(), 'event' => 'view', 'count' => 50]);
        SiteDailyCount::create(['company_id' => $this->company->id, 'subject_type' => 'property', 'subject_id' => $p->id, 'day' => now()->toDateString(), 'event' => 'whatsapp_click', 'count' => 9]);
        $this->api('GET', 'consultant-site/listings-summary')->assertOk()
            ->assertJsonPath("data.properties.{$p->id}.views_30", 5)->assertJsonPath("data.properties.{$p->id}.site_status", 'publicado');
    }

    public function test_developments_crud_publish_and_units(): void
    {
        $id = $this->api('POST', 'developments', ['name' => 'Empreendimento Exemplo', 'construction_phase' => 'estrutura', 'parish_id' => $this->parish,
            'expected_completion' => '2027-12', 'payment_plan' => 'Sinal no contrato-promessa.'])->assertCreated()->json('data.id');
        $d = Development::find($id);
        $this->assertSame('empreendimento-exemplo', $d->slug);
        $missing = $this->api('POST', "developments/{$id}/publish")->assertStatus(422)->json('errors.publish');
        $this->assertCount(3, $missing, 'localização, classe energética prevista e fotografia');
        $this->assertSame(['lat', 'energy_class_expected', 'photos'], array_column($this->api('GET', "developments/{$id}")->json('data.publish_checklist'), 'field'));
        $this->api('PUT', "developments/{$id}", ['name' => 'Empreendimento Exemplo', 'construction_phase' => 'estrutura', 'parish_id' => $this->parish,
            'lat' => 41.17, 'lng' => -8.6, 'energy_class_expected' => 'A'])->assertOk();
        $this->upload($d, 1)->assertCreated();
        $this->api('POST', "developments/{$id}/publish")->assertOk()->assertJsonPath('data.site_status', 'publicado');
        $unit = $this->makeProperty();
        $this->api('PUT', "properties/{$unit->id}/site", ['development_id' => $id])->assertOk();
        $this->assertSame(1, $this->api('GET', "developments/{$id}")->json('data.units_count'));
        $other = $this->makeConsultantCompany('Outra');
        $foreign = Development::create(['company_id' => $other->id, 'name' => 'De outra', 'slug' => 'de-outra', 'construction_phase' => 'projeto']);
        $this->api('PUT', "properties/{$unit->id}/site", ['development_id' => $foreign->id])->assertStatus(422);
        $this->api('GET', "developments/{$foreign->id}")->assertNotFound();
        $this->api('DELETE', "developments/{$id}")->assertOk();
        $this->assertNull($unit->fresh()->development_id, 'as frações ficam na carteira');
        $this->assertSame(0, ListingImage::where('owner_type', 'development')->where('owner_id', $id)->count());
    }

    public function test_without_the_site_module_the_routes_are_closed(): void
    {
        $p = $this->makeProperty();
        app(\App\Services\CompanyModuleService::class)->disable($this->company->id, 'site_consultor');
        $this->api('GET', "properties/{$p->id}/site")->assertForbidden();
        $this->api('GET', 'developments')->assertOk(); // os empreendimentos são da carteira (módulo imoveis)
        $this->api('GET', 'consultant-site')->assertForbidden();
        $this->api('GET', 'inbound-contacts')->assertForbidden();
        $this->api('GET', 'properties')->assertOk(); // a carteira (R3) continua
    }
}
