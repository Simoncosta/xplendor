<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Models\IneHousingPrice;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsConsultantSite;
use Tests\TestCase;

/**
 * Site do consultor: o que a página inicial mostra em vez de explicar. Os vídeos em destaque
 * (links ou IDs do YouTube, por ordem), as fotografias das zonas (sem EXIF nem GPS, nos
 * Ficheiros) e o mercado num número (o valor mediano por m² do INE de cada freguesia de atuação,
 * só quando o INE o divulga, nunca o do concelho).
 */
class HomeContentTest extends TestCase
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

    public function test_featured_videos_take_links_or_ids_in_order_without_repeats(): void
    {
        $this->api('PUT', 'consultant-site', ['featured_videos' => [
            'https://www.youtube.com/shorts/AbCdEfGhIjK', 'ZyXwVuTsRqP', 'https://youtu.be/AbCdEfGhIjK', '',
        ]])->assertOk()->assertJsonPath('data.featured_videos', ['AbCdEfGhIjK', 'ZyXwVuTsRqP']);

        $this->assertSame([['youtube_id' => 'AbCdEfGhIjK'], ['youtube_id' => 'ZyXwVuTsRqP']], $this->siteApi('GET', 'profile')->json('data.featured_videos'));

        $this->api('PUT', 'consultant-site', ['featured_videos' => ['AbCdEfGhIjK', 'https://vimeo.com/123']])->assertStatus(422)->assertJsonValidationErrors('featured_videos.1');
        $this->api('PUT', 'consultant-site', ['featured_videos' => array_fill(0, 13, 'AbCdEfGhIjK')])->assertStatus(422)->assertJsonValidationErrors('featured_videos');
        $this->assertSame(['AbCdEfGhIjK', 'ZyXwVuTsRqP'], $this->site->fresh()->featuredVideoIds());

        // Vazio: a fila sai da página inicial.
        $this->api('PUT', 'consultant-site', ['featured_videos' => []])->assertOk()->assertJsonPath('data.featured_videos', []);
        $this->assertSame([], $this->siteApi('GET', 'profile')->json('data.featured_videos'));
    }

    public function test_zone_photos_lose_exif_and_gps_and_are_registered_in_files(): void
    {
        $jpeg = $this->jpegWithGps();
        $this->assertStringContainsString('GPSLatitude', $jpeg);
        $this->site->parishes()->sync([$this->parish]);
        // O registo nos Ficheiros só acontece com o módulo ligado (spike de 22/10).
        app(\App\Services\CompanyModuleService::class)->enable($this->company->id, 'ficheiros');

        $r = $this->actingAs($this->admin, 'sanctum')->post("/api/v1/companies/{$this->company->id}/consultant-site/zone-photos",
            ['file' => UploadedFile::fake()->createWithContent('rua.jpg', $jpeg), 'alt' => 'Rua de Paranhos', 'parish_id' => $this->parish], ['Accept' => 'application/json'])
            ->assertOk();
        $photo = $r->json('data.zone_photos.0');
        $this->assertSame('Rua de Paranhos', $photo['alt']);
        $this->assertSame(1200, $photo['width']);
        foreach ([$photo['path'], $photo['path_960']] as $path) {
            $bytes = Storage::disk('public')->get(substr($path, strlen('/storage/')));
            $this->assertSame('WEBP', substr($bytes, 8, 4));
            $this->assertStringNotContainsString('GPS', $bytes);
            $this->assertStringNotContainsString('Exif', $bytes);
            $this->assertStringNotContainsString('EXIF', $bytes);
        }
        $this->assertTrue(StoredFile::where('company_id', $this->company->id)->where('source_ref', "consultant_site:{$this->site->id}:zone:{$photo['id']}")->exists());

        $public = $this->siteApi('GET', 'profile')->json('data.zone_photos.0');
        $this->assertSame('Paranhos', $public['parish']);
        $this->assertSame('Rua de Paranhos', $public['alt']);
        $this->assertStringEndsWith($photo['path_960'], $public['src_960']);
        $this->assertArrayNotHasKey('id', $public);

        // Recusa o que não é uma fotografia.
        $this->actingAs($this->admin, 'sanctum')->post("/api/v1/companies/{$this->company->id}/consultant-site/zone-photos",
            ['file' => UploadedFile::fake()->createWithContent('rua.jpg', 'não é uma fotografia')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->api('DELETE', 'consultant-site/zone-photos/naoexiste0')->assertNotFound();
        $this->api('DELETE', "consultant-site/zone-photos/{$photo['id']}")->assertOk()->assertJsonPath('data.zone_photos', []);
        Storage::disk('public')->assertMissing(substr($photo['path'], strlen('/storage/')));
        Storage::disk('public')->assertMissing(substr($photo['path_960'], strlen('/storage/')));
        $this->assertSame([], $this->siteApi('GET', 'profile')->json('data.zone_photos'));
    }

    public function test_zone_photos_need_permission_to_edit_the_site(): void
    {
        $other = $this->makeConsultantCompany('Outra Empresa');
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'email' => 'admin@outra-empresa.invalid']);
        $this->actingAs($intruder, 'sanctum')->post("/api/v1/companies/{$this->company->id}/consultant-site/zone-photos",
            ['file' => UploadedFile::fake()->image('rua.jpg', 800, 600)], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame([], $this->site->fresh()->zonePhotos());
    }

    public function test_the_hero_poster_also_gets_a_960_px_version_for_phones(): void
    {
        $r = $this->actingAs($this->admin, 'sanctum')->post("/api/v1/companies/{$this->company->id}/consultant-site/assets/hero_poster",
            ['file' => UploadedFile::fake()->image('capa.jpg', 1920, 1080)], ['Accept' => 'application/json'])->assertOk();
        $site = $this->site->fresh();
        $this->assertNotNull($site->hero_poster_960_path);
        [$w] = getimagesizefromstring(Storage::disk('public')->get(substr($site->hero_poster_960_path, strlen('/storage/'))));
        $this->assertSame(960, $w);
        $this->assertStringEndsWith($site->hero_poster_960_path, (string) $this->siteApi('GET', 'profile')->json('data.hero.poster_960_url'));

        $small = $site->hero_poster_960_path;
        $this->api('DELETE', 'consultant-site/assets/hero_poster')->assertOk();
        Storage::disk('public')->assertMissing(substr($small, strlen('/storage/')));
        $this->assertNull($this->site->fresh()->hero_poster_960_path);
        $this->assertNotNull($r->json('data.assets.hero_poster'));
    }

    public function test_the_market_number_is_the_latest_parish_value_and_hidden_without_value(): void
    {
        $this->site->parishes()->sync([$this->parish, $this->otherParish]);
        $row = fn (string $level, string $code, string $period, string $label, ?int $value) => IneHousingPrice::create([
            'geo_level' => $level, 'geo_code' => "X{$code}", 'geo_name' => 'Exemplo', 'official_code' => $code, 'period_code' => $period,
            'period_label' => $label, 'median_m2' => $value, 'has_value' => $value !== null, 'fetched_at' => now(),
        ]);
        $row('freguesia', '131210', 'S5A20254', '4.º Trimestre de 2025', 3300);
        $row('freguesia', '131210', 'S5A20261', '1.º Trimestre de 2026', 3424);
        // Bonfim: o INE não divulga o valor no período mais recente; não se mostra o anterior.
        $row('freguesia', '131202', 'S5A20254', '4.º Trimestre de 2025', 2950);
        $row('freguesia', '131202', 'S5A20261', '1.º Trimestre de 2026', null);
        $row('municipio', '1312', 'S5A20261', '1.º Trimestre de 2026', 3777);

        $profile = $this->siteApi('GET', 'profile')->json('data');
        $parishes = collect($profile['parishes'])->keyBy('name');
        $this->assertSame(3424, $parishes['Paranhos']['market']['median_m2']);
        $this->assertSame('1.º Trimestre de 2026', $parishes['Paranhos']['market']['period']);
        $this->assertStringStartsWith('Fonte: Instituto Nacional de Estatística', $parishes['Paranhos']['market']['attribution']);
        $this->assertNull($parishes['Bonfim']['market']);
        $this->assertStringNotContainsString('3777', json_encode($profile));
    }

    /** Um JPEG com um bloco EXIF que leva coordenadas GPS (dados fictícios). */
    private function jpegWithGps(): string
    {
        $img = imagecreatetruecolor(1200, 800);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 120, 140, 160));
        ob_start();
        imagejpeg($img, null, 90);
        $jpg = (string) ob_get_clean();
        $tiff = "II*\x00" . "\x08\x00\x00\x00" . "\x00\x00" . "\x00\x00\x00\x00";
        $payload = "Exif\x00\x00" . $tiff . 'GPSLatitude 41.0000 N GPSLongitude 8.0000 W';
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($jpg, 0, 2) . $app1 . substr($jpg, 2);
    }
}
