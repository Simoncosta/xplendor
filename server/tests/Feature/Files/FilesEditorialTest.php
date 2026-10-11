<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Jobs\MediaRetentionJob;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\FileFolder;
use App\Models\MediaAsset;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Files\SystemFileCatalog;
use App\Services\Media\MediaService;
use App\Support\Storage\StorageMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ficheiros (F4): a Linha Editorial nos dois sentidos. A media das publicações aparece nos
 * Ficheiros; "Guardar numa pasta" sem cópia; "Escolher dos Ficheiros" usa o mesmo ficheiro
 * (variantes geradas uma vez, o original fica onde está); "em uso" impede apagar; a retenção
 * nunca apaga o que está guardado numa pasta, em uso ou de outro módulo.
 */
class FilesEditorialTest extends TestCase
{
    use RefreshDatabase;

    private Company $c;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        foreach (['local', 'public', 'media'] as $d) {
            Storage::fake($d);
        }
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->c = Company::create(['nipc' => '501300001', 'fiscal_name' => 'Editorial Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach (['ficheiros', 'linha_editorial', 'stock'] as $m) {
            app(CompanyModuleService::class)->enable($this->c->id, $m);
        }
        $this->admin = User::factory()->create(['company_id' => $this->c->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(60, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 60));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private function url(string $suffix): string
    {
        return "/api/v1/companies/{$this->c->id}/{$suffix}";
    }

    private function makePost(): EditorialPost
    {
        DB::table('editorial_months')->insert(['company_id' => $this->c->id, 'year' => 2026, 'month' => 11, 'state' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        return EditorialPost::create(['company_id' => $this->c->id, 'publish_date' => '2026-11-10', 'title' => 'Promoção de outono', 'format' => 'post',
            'status' => 'planned', 'stage' => EditorialPost::STAGE_IDEA, 'channel' => EditorialPost::CHANNEL_SOCIAL]);
    }

    /** Uma imagem de viatura no disco público, registada nas pastas do sistema. */
    private function carImage(): StoredFile
    {
        $car = \App\Models\Car::factory()->create(['company_id' => $this->c->id]);
        Storage::disk('public')->put("company_{$this->c->id}/cars/x-{$car->id}/images/1.jpg", $this->jpeg());
        DB::table('car_images')->insert(['car_id' => $car->id, 'company_id' => $this->c->id, 'image' => "/storage/company_{$this->c->id}/cars/x-{$car->id}/images/1.jpg",
            'order' => 0, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(SystemFileCatalog::class)->sync($this->c->id, true);

        return StoredFile::where('source', 'viatura')->firstOrFail();
    }

    public function test_choosing_a_car_image_from_the_files_uses_it_without_copying_and_marks_it_in_use(): void
    {
        $file = $this->carImage();
        $publicBefore = Storage::disk('public')->allFiles();

        // Uma imagem fora da media tem como miniatura o próprio ficheiro (endereço assinado).
        $listed = $this->actingAs($this->admin, 'sanctum')->getJson($this->url("files?folder_id={$file->folder_id}"))->assertOk()->json('data.files.0');
        $this->assertStringContainsString("/api/files/stored/{$file->id}", (string) $listed['thumb_url']);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('editorial/media/from-file'), ['stored_file_id' => $file->id])->assertOk();
        $asset = MediaAsset::findOrFail($res->json('data.id'));
        $this->assertSame(['public', $file->path], [$asset->original_disk, $asset->original_path], 'o original fica onde está');
        $this->assertSame($asset->id, $file->fresh()->media_asset_id);
        Queue::assertPushed(\App\Jobs\ProcessMediaAssetJob::class);

        // As variantes geram-se uma vez, no disco da media; o original não é copiado.
        app(MediaService::class)->process($asset->id);
        $asset->refresh();
        $this->assertSame(MediaAsset::READY, $asset->status);
        $this->assertSame([], array_values(array_filter(Storage::disk('media')->allFiles(), fn ($p) => str_contains($p, 'original'))));
        $this->assertNotEmpty(Storage::disk('media')->allFiles($asset->dir));
        $this->assertSame($publicBefore, Storage::disk('public')->allFiles());

        // O registo noturno dos Ficheiros mantém a ligação à media (antes apagava-a).
        app(SystemFileCatalog::class)->sync($this->c->id, true);
        $this->assertSame($asset->id, $file->fresh()->media_asset_id, 'o registo não desfaz o "Escolher dos Ficheiros"');

        // Escolher outra vez devolve a mesma media.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('editorial/media/from-file'), ['stored_file_id' => $file->id])->assertJsonPath('data.id', $asset->id);

        // Na publicação: "em uso em 1 publicação", com o link.
        $post = $this->makePost();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("editorial/posts/{$post->id}/media"), ['items' => [$asset->id]])->assertOk();
        $detail = $this->actingAs($this->admin, 'sanctum')->getJson($this->url("files/{$file->id}"))->assertOk();
        $this->assertSame([[$post->id, 'Promoção de outono']], collect($detail->json('data.usage'))->map(fn ($u) => [$u['id'], $u['title']])->all());
        $this->assertSame(1, $detail->json('data.in_use'));

        // A retenção nunca apaga um original de outro módulo, nem o copia para o R2.
        app(MediaService::class)->purgeOriginal($asset);
        Storage::disk('public')->assertExists($file->path);
        $paths = (fn () => $this->assetPaths($asset))->call(new StorageMigration('r2'));
        $this->assertNotContains($file->path, $paths);
    }

    public function test_a_file_uploaded_in_the_files_is_used_in_a_post_and_cannot_be_deleted_while_in_use(): void
    {
        $bytes = $this->jpeg();
        $start = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('files/uploads'), ['name' => 'Montra.jpg', 'size' => strlen($bytes), 'mime' => 'image/jpeg'])->assertCreated();
        $fileId = $this->actingAs($this->admin, 'sanctum')->post($this->url('files/uploads/' . $start->json('data.id') . '/chunk'),
            ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('parte', $bytes)], ['Accept' => 'application/json'])->json('data.file.id');
        $file = StoredFile::findOrFail($fileId);

        $assetId = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('editorial/media/from-file'), ['stored_file_id' => $file->id])->json('data.id');
        $this->assertSame($file->media_asset_id, $assetId, 'a mesma media (nada novo)');
        $post = $this->makePost();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("editorial/posts/{$post->id}/media"), ['items' => [$assetId]])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("files/{$file->id}"))->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'em uso em 1 publicação'));
    }

    public function test_editorial_media_appears_in_the_month_folder_and_saving_it_to_a_folder_protects_it_from_retention(): void
    {
        $asset = MediaAsset::create(['company_id' => $this->c->id, 'kind' => MediaAsset::IMAGE, 'disk' => 'media', 'dir' => "company_{$this->c->id}/ed",
            'original_name' => 'Campanha.jpg', 'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 9, 'sha256' => str_repeat('d', 64),
            'status' => MediaAsset::READY, 'created_at' => now()->subDays(10)]);
        Storage::disk('media')->put("company_{$this->c->id}/ed/original.jpg", $this->jpeg());
        $other = MediaAsset::create(['company_id' => $this->c->id, 'kind' => MediaAsset::IMAGE, 'disk' => 'media', 'dir' => "company_{$this->c->id}/solta",
            'original_name' => 'Solta.jpg', 'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 9, 'sha256' => str_repeat('e', 64),
            'status' => MediaAsset::READY, 'created_at' => now()->subDays(10)]);
        Storage::disk('media')->put("company_{$this->c->id}/solta/original.jpg", $this->jpeg());
        MediaAsset::whereIn('id', [$asset->id, $other->id])->update(['created_at' => now()->subDays(10)]);

        $tree = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('files'))->assertOk()->json('data.tree');
        $month = collect($tree)->first(fn ($f) => str_starts_with((string) $f['system_key'], 'editorial:'));
        $this->assertNotNull($month, 'a pasta da Linha Editorial do mês');
        $file = StoredFile::where('source', 'media')->where('media_asset_id', $asset->id)->firstOrFail();
        $this->assertSame($month['id'], $file->folder_id);

        // "Guardar numa pasta": aparece nas duas, sem cópia.
        $folder = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('files/folders'), ['name' => 'Campanhas'])->json('data.id');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("files/{$file->id}/shortcuts"), ['folder_id' => $folder])->assertCreated();
        $inFolder = $this->actingAs($this->admin, 'sanctum')->getJson($this->url("files?folder_id={$folder}"))->json('data.shortcuts');
        $this->assertSame([$file->id], collect($inFolder)->pluck('id')->all());
        $this->assertSame(2, count(Storage::disk('media')->allFiles()), 'nenhuma cópia');

        // A retenção ("enviados e nunca usados" há mais de 7 dias): a guardada fica, a solta sai.
        app(MediaRetentionJob::class)->handle(app(MediaService::class), app(\App\Services\Media\DiskUsage::class), app(\App\Services\AlertService::class));
        $this->assertNotNull(MediaAsset::find($asset->id));
        $this->assertNull(MediaAsset::find($other->id));
    }

    public function test_choose_from_files_refuses_documents_files_the_person_cannot_see_and_the_module_off(): void
    {
        $doc = $this->actingAs($this->admin, 'sanctum')->post($this->url('files/documents'), ['file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\nx")], ['Accept' => 'application/json'])->json('data.id');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('editorial/media/from-file'), ['stored_file_id' => $doc])->assertStatus(422);

        // Um ficheiro da faturação da XPLENDOR, para um utilizador: não existe para ele.
        $billing = FileFolder::create(['company_id' => $this->c->id, 'system_key' => 'xplendor:cobrancas', 'name' => 'Cobranças']);
        Storage::disk('local')->put('x.jpg', $this->jpeg());
        $hidden = StoredFile::create(['company_id' => $this->c->id, 'folder_id' => $billing->id, 'name' => 'x.jpg', 'mime' => 'image/jpeg', 'disk' => 'local',
            'path' => 'x.jpg', 'source' => 'teste', 'source_ref' => 'teste:x', 'is_system' => true]);
        $user = User::factory()->create(['company_id' => $this->c->id, 'role' => 'user']);
        $this->actingAs($user, 'sanctum')->postJson($this->url('editorial/media/from-file'), ['stored_file_id' => $hidden->id])->assertNotFound();

        app(CompanyModuleService::class)->disable($this->c->id, 'ficheiros');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('editorial/media/from-file'), ['stored_file_id' => $doc])->assertForbidden();
    }
}
