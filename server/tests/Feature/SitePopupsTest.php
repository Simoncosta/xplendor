<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Access\Access;
use App\Access\ProfileSuggestions;
use App\Models\Company;
use App\Models\PermissionProfile;
use App\Models\SitePopup;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsConsultantSite;
use Tests\TestCase;

/**
 * Avisos (pop-up) nos sites dos clientes: o ecrã (criar, editar, apagar, com a imagem publicada
 * como cópia e a antiga apagada), a API pública (uma lista por prioridade, só os ativos e dentro
 * das datas, cada um com as suas páginas), a validação (sem "sempre", ligações seguras), as
 * contagens anónimas (sem IP nem cookies), a API dos sites dos consultores, tenancy e ACL.
 */
class SitePopupsTest extends TestCase
{
    use BuildsConsultantSite;
    use RefreshDatabase;

    private Company $stand;
    private Company $otherStand;
    private User $standAdmin;
    private User $standUser;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        $this->buildConsultantSite();
        $this->stand = Company::create(['nipc' => '500019361', 'fiscal_name' => 'Stand Avisos Lda', 'trade_name' => 'Stand', 'plan_id' => $this->planId,
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        $this->otherStand = Company::create(['nipc' => '500019362', 'fiscal_name' => 'Outro Stand Lda', 'plan_id' => $this->planId,
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        foreach ([$this->stand, $this->otherStand] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'stock');
            app(CompanyModuleService::class)->enable($c->id, 'ficheiros');
        }
        $this->standAdmin = User::factory()->create(['company_id' => $this->stand->id, 'role' => 'admin']);
        $this->standUser = User::factory()->create(['company_id' => $this->stand->id, 'role' => 'user']);
        \App\Access\CompatibilityMigration::run();
        ProfileSuggestions::ensure();
    }

    private function base(?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->stand)->id;
    }

    private function image(int $w = 1600, int $h = 900): StoredFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 200));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $path = 'files/company_' . $this->stand->id . '/' . Str::uuid() . '.png';
        Storage::disk('local')->put($path, $png);

        return StoredFile::create(['company_id' => $this->stand->id, 'name' => 'campanha.png', 'extension' => 'png', 'mime' => 'image/png',
            'size_bytes' => strlen($png), 'disk' => 'local', 'path' => $path, 'source' => 'upload', 'source_ref' => 'upload:' . Str::uuid(), 'is_system' => false]);
    }

    private function payload(array $over = []): array
    {
        return array_replace(['title' => 'Feira de outono', 'body' => "Descontos em todo o stock.\nAté domingo.", 'button_label' => 'Ver viaturas',
            'button_url' => 'https://stand.example/viaturas', 'pages' => ['home', 'vehicles'], 'delay_seconds' => 5, 'frequency' => 'once_per_visitor',
            'priority' => 0, 'is_active' => true], $over);
    }

    private function create(array $over = [], ?User $as = null): SitePopup
    {
        $res = $this->actingAs($as ?? $this->standAdmin, 'sanctum')->postJson($this->base() . '/site-popups', $this->payload($over))->assertCreated();

        return SitePopup::findOrFail($res->json('data.id'));
    }

    private function publicList(?Company $c = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/public/popup?token=' . ($c ?? $this->stand)->public_api_token);
    }

    public function test_the_image_is_published_as_a_reduced_copy_and_the_old_one_is_deleted(): void
    {
        $popup = $this->create(['image_file_id' => $this->image()->id]);
        Storage::disk('public')->assertExists($popup->image_path);
        $this->assertStringStartsWith("company_{$this->stand->id}/popups/", $popup->image_path);
        // Sem recorte, o centro 4:3 da imagem (como o site a mostraria), a 960 × 720 (23/10).
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($popup->image_path));
        $this->assertSame([960, 720], [$w, $h]);
        $first = $popup->image_path;

        $this->actingAs($this->standAdmin, 'sanctum')->putJson($this->base() . "/site-popups/{$popup->id}", $this->payload(['image_file_id' => $this->image(800, 600)->id]))->assertOk();
        $second = $popup->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($this->standAdmin, 'sanctum')->deleteJson($this->base() . "/site-popups/{$popup->id}")->assertOk();
        Storage::disk('public')->assertMissing($second);
        $this->assertSame(0, SitePopup::count());
    }

    public function test_the_editor_crop_is_applied_and_the_copy_is_960_by_720_up_to_200_kb(): void
    {
        // Metade esquerda vermelha e metade direita azul: o recorte da metade direita fica todo azul.
        $img = imagecreatetruecolor(1600, 1200);
        imagefilledrectangle($img, 0, 0, 799, 1199, imagecolorallocate($img, 220, 20, 20));
        imagefilledrectangle($img, 800, 0, 1599, 1199, imagecolorallocate($img, 20, 20, 220));
        ob_start();
        imagepng($img);
        $path = 'files/company_' . $this->stand->id . '/' . Str::uuid() . '.png';
        Storage::disk('local')->put($path, (string) ob_get_clean());
        $file = StoredFile::create(['company_id' => $this->stand->id, 'name' => 'metades.png', 'extension' => 'png', 'mime' => 'image/png', 'size_bytes' => 1,
            'disk' => 'local', 'path' => $path, 'source' => 'upload', 'source_ref' => 'upload:' . Str::uuid(), 'is_system' => false]);

        $popup = $this->create(['image_file_id' => $file->id, 'image_crop' => ['x' => 0.5, 'y' => 0.25, 'width' => 0.5, 'height' => 0.5]]);
        $copy = imagecreatefromstring(Storage::disk('public')->get($popup->image_path));
        $this->assertSame([960, 720], [imagesx($copy), imagesy($copy)]);
        foreach ([[10, 10], [950, 710], [480, 360]] as [$x, $y]) {
            $rgb = imagecolorat($copy, $x, $y);
            $this->assertGreaterThan(150, $rgb & 0xFF, 'azul');
            $this->assertLessThan(80, ($rgb >> 16) & 0xFF, 'sem vermelho');
        }

        // Recortar de novo a mesma imagem publica outra vez (a cópia antiga sai).
        $old = $popup->image_path;
        $this->actingAs($this->standAdmin, 'sanctum')->putJson($this->base() . "/site-popups/{$popup->id}",
            $this->payload(['image_file_id' => $file->id, 'image_crop' => ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 0.5]]))->assertOk();
        $this->assertNotSame($old, $popup->fresh()->image_path);
        Storage::disk('public')->assertMissing($old);
        $rgb = imagecolorat(imagecreatefromstring(Storage::disk('public')->get($popup->fresh()->image_path)), 480, 360);
        $this->assertGreaterThan(150, ($rgb >> 16) & 0xFF, 'agora a metade vermelha');

        // Uma imagem com muito pormenor (ruído em blocos de 6 px, mais do que uma fotografia) fica até 200 KB.
        $noise = imagecreatetruecolor(1600, 1200);
        for ($y = 0; $y < 1200; $y += 6) {
            for ($x = 0; $x < 1600; $x += 6) {
                imagefilledrectangle($noise, $x, $y, $x + 5, $y + 5, imagecolorallocate($noise, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        ob_start();
        imagepng($noise);
        $noisePath = 'files/company_' . $this->stand->id . '/' . Str::uuid() . '.png';
        Storage::disk('local')->put($noisePath, (string) ob_get_clean());
        $noiseFile = StoredFile::create(['company_id' => $this->stand->id, 'name' => 'ruido.png', 'extension' => 'png', 'mime' => 'image/png', 'size_bytes' => 1,
            'disk' => 'local', 'path' => $noisePath, 'source' => 'upload', 'source_ref' => 'upload:' . Str::uuid(), 'is_system' => false]);
        $noisy = $this->create(['title' => 'Ruído', 'image_file_id' => $noiseFile->id]);
        $this->assertLessThanOrEqual(200 * 1024, strlen(Storage::disk('public')->get($noisy->image_path)));

        // Um recorte fora de 0 a 1 é recusado.
        $this->actingAs($this->standAdmin, 'sanctum')->postJson($this->base() . '/site-popups',
            $this->payload(['image_file_id' => $file->id, 'image_crop' => ['x' => 0.8, 'y' => 0, 'width' => 1.4, 'height' => 0.5]]))->assertStatus(422);
    }

    public function test_the_public_api_lists_only_the_live_popups_by_priority_with_their_pages(): void
    {
        $this->publicList()->assertOk()->assertExactJson(['popups' => []]);
        $low = $this->create(['title' => 'Baixa', 'priority' => 1, 'pages' => ['all']]);
        $high = $this->create(['title' => 'Alta', 'priority' => 5, 'pages' => ['vehicle'], 'frequency' => 'once_per_day', 'button_label' => null, 'button_url' => null]);
        $this->create(['title' => 'Desligado', 'is_active' => false, 'priority' => 9]);
        $this->create(['title' => 'Futuro', 'starts_at' => now()->addDay()->toIso8601String(), 'priority' => 9]);
        $this->create(['title' => 'Terminado', 'starts_at' => now()->subDays(3)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String(), 'priority' => 9]);
        $otherAdmin = User::factory()->create(['company_id' => $this->otherStand->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->actingAs($otherAdmin, 'sanctum')->postJson($this->base($this->otherStand) . '/site-popups', $this->payload(['title' => 'De outro stand']))->assertCreated();
        $this->stand->refresh();

        $res = $this->publicList()->assertOk();
        $res->assertHeader('Cache-Control', 'max-age=60, public');
        $popups = $res->json('popups');
        $this->assertSame(['Alta', 'Baixa'], array_column($popups, 'title'));
        $this->assertSame(['id', 'version', 'title', 'body', 'image_url', 'button', 'pages', 'delay_seconds', 'frequency', 'ends_at'], array_keys($popups[0]));
        $this->assertSame(['vehicle'], $popups[0]['pages']);
        $this->assertNull($popups[0]['button']);
        $this->assertSame('once_per_day', $popups[0]['frequency']);
        $this->assertSame(['all'], $popups[1]['pages']);
        $this->assertSame(['label' => 'Ver viaturas', 'url' => 'https://stand.example/viaturas'], $popups[1]['button']);
        $this->assertSame($low->id, $popups[1]['id']);

        // Editar muda a versão (o site volta a mostrar a quem já o fechou).
        $v = $popups[0]['version'];
        $this->travel(1)->minutes();
        $this->actingAs($this->standAdmin, 'sanctum')->putJson($this->base() . "/site-popups/{$high->id}", $this->payload(['title' => 'Alta 2', 'priority' => 5, 'pages' => ['vehicle']]))->assertOk();
        $this->assertNotSame($v, $this->publicList()->json('popups.0.version'));

        // Fora das datas: sai da lista quando termina.
        $this->actingAs($this->standAdmin, 'sanctum')->putJson($this->base() . "/site-popups/{$high->id}", $this->payload(['ends_at' => now()->addHour()->toIso8601String(), 'priority' => 5]))->assertOk();
        $this->travel(2)->hours();
        $this->assertSame(['Baixa'], array_column($this->publicList()->json('popups'), 'title'));
        $this->getJson('/api/public/popup?token=nao-existe')->assertStatus(401);
    }

    public function test_validation_without_always_and_with_safe_links(): void
    {
        $url = $this->base() . '/site-popups';
        $as = $this->actingAs($this->standAdmin, 'sanctum');
        $as->postJson($url, $this->payload(['frequency' => 'always']))->assertStatus(422)->assertJsonValidationErrors('frequency');
        $as->postJson($url, $this->payload(['button_url' => 'javascript:alert(1)']))->assertStatus(422)->assertJsonValidationErrors('button_url');
        $as->postJson($url, $this->payload(['button_url' => 'http://stand.example']))->assertStatus(422)->assertJsonValidationErrors('button_url');
        $as->postJson($url, $this->payload(['pages' => ['contactos']]))->assertStatus(422)->assertJsonValidationErrors('pages.0');
        $as->postJson($url, $this->payload(['starts_at' => now()->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()]))->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $as->postJson($url, $this->payload(['button_url' => '/viaturas', 'pages' => ['home', 'all']]))->assertCreated()->assertJsonPath('data.pages', ['all']);
    }

    public function test_views_clicks_and_closes_are_anonymous_numbers(): void
    {
        $popup = $this->create();
        $event = fn (string $type, ?Company $c = null, ?int $id = null) => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/public/popup/' . ($id ?? $popup->id) . '/event?token=' . ($c ?? $this->stand)->public_api_token, ['type' => $type]);
        $event('view')->assertNoContent();
        $event('view')->assertNoContent();
        $event('click')->assertNoContent();
        $event('close')->assertNoContent();
        $event('hover')->assertStatus(422);
        $event('view', $this->otherStand)->assertNotFound();

        $this->assertSame(['id', 'site_popup_id', 'day', 'views', 'clicks', 'closes'], Schema::getColumnListing('site_popup_daily_counts'));
        $row = (array) DB::table('site_popup_daily_counts')->sole();
        $this->assertSame([2, 1, 1], [(int) $row['views'], (int) $row['clicks'], (int) $row['closes']]);
        $this->assertStringNotContainsString('203.0.113.9', json_encode($row));

        $stats = $this->actingAs($this->standAdmin, 'sanctum')->getJson($this->base() . '/site-popups')->json('data.popups.0.stats');
        $this->assertSame(['days' => 30, 'views' => 2, 'clicks' => 1, 'closes' => 1, 'click_rate' => 50], $stats);
        $series = $this->actingAs($this->standAdmin, 'sanctum')->getJson($this->base() . "/site-popups/{$popup->id}")->json('data.stats.series');
        $this->assertCount(30, $series);

        $this->actingAs($this->standAdmin, 'sanctum')->putJson($this->base() . "/site-popups/{$popup->id}", $this->payload(['is_active' => false]))->assertOk();
        $event('view')->assertNotFound();
    }

    public function test_overlapping_popups_are_flagged_in_the_screen(): void
    {
        $a = $this->create(['pages' => ['home']]);
        $b = $this->create(['pages' => ['all']]);
        $c = $this->create(['pages' => ['vehicle'], 'starts_at' => now()->addMonth()->toIso8601String()]);
        $list = collect($this->actingAs($this->standAdmin, 'sanctum')->getJson($this->base() . '/site-popups')->json('data.popups'))->keyBy('id');
        $this->assertSame([$b->id], $list[$a->id]['overlaps']);
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $list[$b->id]['overlaps']);
        $this->assertSame('programado', $list[$c->id]['state']);
    }

    public function test_the_consultant_sites_get_the_same_list(): void
    {
        $popup = SitePopup::create(['company_id' => $this->company->id, 'title' => 'Novo empreendimento', 'pages' => ['all'], 'frequency' => 'once_per_session', 'is_active' => true]);
        $this->siteApi('GET', 'popup')->assertOk()->assertJsonPath('popups.0.id', $popup->id)->assertJsonPath('popups.0.frequency', 'once_per_session');
        $this->siteApi('POST', "popup/{$popup->id}/event", ['type' => 'view'])->assertNoContent();
        $this->assertSame(1, (int) DB::table('site_popup_daily_counts')->value('views'));
        $this->siteApi('GET', 'popup', token: '')->assertStatus(401);
    }

    public function test_tenancy_and_acl(): void
    {
        $popup = $this->create();
        $stranger = User::factory()->create(['company_id' => $this->otherStand->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->actingAs($stranger, 'sanctum')->getJson($this->base() . '/site-popups')->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->putJson($this->base($this->otherStand) . "/site-popups/{$popup->id}", $this->payload())->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson($this->base($this->otherStand) . "/site-popups/{$popup->id}")->assertNotFound();

        $this->actingAs($this->standUser, 'sanctum')->getJson($this->base() . '/site-popups')->assertOk();
        $this->actingAs($this->standUser, 'sanctum')->postJson($this->base() . '/site-popups', $this->payload())->assertCreated();
        $this->actingAs($this->standUser, 'sanctum')->putJson($this->base() . "/site-popups/{$popup->id}", $this->payload(['title' => 'Editado']))->assertOk();
        $this->actingAs($this->standUser, 'sanctum')->deleteJson($this->base() . "/site-popups/{$popup->id}")->assertForbidden();
        $this->actingAs($this->standAdmin, 'sanctum')->deleteJson($this->base() . "/site-popups/{$popup->id}")->assertOk();

        $keys = fn (string $k) => array_values(array_filter(PermissionProfile::where('system_key', $k)->firstOrFail()->permissionKeys(), fn ($p) => str_starts_with($p, 'avisos_site.')));
        $this->assertSame(['avisos_site.ver'], $keys(ProfileSuggestions::READ_ONLY));
        $this->assertEqualsCanonicalizing(['avisos_site.ver', 'avisos_site.criar', 'avisos_site.editar'], $keys(ProfileSuggestions::GUEST_AGENCY));
        foreach ([PermissionProfile::CEILING_COMPAT, PermissionProfile::AGENCY_ADMIN_COMPAT, PermissionProfile::AGENCY_MEMBER_COMPAT, PermissionProfile::USER_COMPAT] as $k) {
            $this->assertEqualsCanonicalizing(['avisos_site.ver', 'avisos_site.criar', 'avisos_site.editar'], $keys($k), $k);
        }
        $this->assertTrue(app(Access::class)->can($this->standAdmin, $this->stand->id, 'avisos_site.apagar')->allowed);
    }
}
