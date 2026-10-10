<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Jobs\MediaRetentionJob;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\FileFolder;
use App\Models\MediaAsset;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Files\FilesService;
use App\Services\Files\RootFileAccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ficheiros (F1): o catálogo, as pastas (só na base de dados), o envio com os tipos confirmados
 * pelo conteúdo, a reciclagem, o isolamento entre empresas, a regra de cada pasta do sistema
 * (a Agência convidada não vê faturas), o módulo e o registo dos acessos do root.
 */
class FilesTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $adminA;
    private User $userA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('media');
        Storage::fake('public');
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '501100001', 'fiscal_name' => 'Empresa A', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '501100002', 'fiscal_name' => 'Empresa B', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'ficheiros');
        }
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
    }

    private function url(Company $c, string $suffix = ''): string
    {
        return "/api/v1/companies/{$c->id}/files{$suffix}";
    }

    private function pdf(string $name = 'contrato.pdf', string $body = 'conteúdo'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$body}\n%%EOF");
    }

    private function upload(User $u, Company $c, UploadedFile $file, ?int $folderId = null)
    {
        return $this->actingAs($u, 'sanctum')->post($this->url($c, '/documents'), array_filter(['file' => $file, 'folder_id' => $folderId]), ['Accept' => 'application/json']);
    }

    /** Uma pasta do sistema com um ficheiro (o registo dos módulos chega na F3). */
    private function systemFile(Company $c, string $key, string $name = 'fatura.pdf'): StoredFile
    {
        $folder = FileFolder::firstOrCreate(['company_id' => $c->id, 'system_key' => $key], ['name' => $key]);
        Storage::disk('local')->put("sys/{$c->id}/{$name}", '%PDF-1.4 sistema');

        return StoredFile::create(['company_id' => $c->id, 'folder_id' => $folder->id, 'name' => $name, 'extension' => 'pdf', 'mime' => 'application/pdf',
            'size_bytes' => 16, 'disk' => 'local', 'path' => "sys/{$c->id}/{$name}", 'source' => 'teste', 'source_ref' => "teste:{$key}:{$name}", 'is_system' => true]);
    }

    // ── Pastas, envio, mover sem copiar, reciclagem ──────────────────────────

    public function test_folders_upload_rename_and_move_without_copying_then_the_recycle_bin(): void
    {
        $f1 = $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, '/folders'), ['name' => 'Contratos'])->assertCreated()->json('data.id');
        $f2 = $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, '/folders'), ['name' => '2026', 'parent_id' => $f1])->assertCreated()->json('data.id');
        $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, '/folders'), ['name' => 'contratos'])->assertStatus(422);
        $this->actingAs($this->adminA, 'sanctum')->putJson($this->url($this->a, "/folders/{$f1}"), ['parent_id' => $f2])->assertStatus(422); // dentro de si própria

        $id = $this->upload($this->adminA, $this->a, $this->pdf('../../etc/Contrato final.pdf'), $f2)->assertCreated()->json('data.id');
        $file = StoredFile::findOrFail($id);
        $this->assertSame(['Contrato final.pdf', 'local', 'application/pdf'], [$file->name, $file->disk, $file->mime]);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
        $this->upload($this->adminA, $this->a, $this->pdf('Contrato final.pdf'), $f2)->assertCreated()->assertJsonPath('data.name', 'Contrato final (2).pdf');

        // Mudar o nome e mover: só a base de dados (o caminho no disco não muda).
        $path = $file->path;
        $this->actingAs($this->adminA, 'sanctum')->putJson($this->url($this->a, "/{$id}"), ['name' => 'Assinado.exe', 'folder_id' => $f1])->assertOk()
            ->assertJsonPath('data.name', 'Assinado.pdf');
        $this->assertSame([$path, $f1], [$file->fresh()->path, $file->fresh()->folder_id]);
        $this->actingAs($this->adminA, 'sanctum')->putJson($this->url($this->a, "/folders/{$f2}"), ['name' => 'Arquivo 2026', 'parent_id' => null])->assertOk();

        // Reciclagem: apagar a pasta leva o que tem dentro; restaurar traz tudo; 30 dias depois apaga de vez.
        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->a, "/folders/{$f1}"))->assertOk();
        $this->assertNotNull($file->fresh()->deleted_at);
        $trash = $this->actingAs($this->adminA, 'sanctum')->getJson($this->url($this->a, '/trash'))->assertOk();
        $this->assertSame(['Contratos'], collect($trash->json('data.folders'))->pluck('name')->all());
        $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, "/folders/{$f1}/restore"))->assertOk();
        $this->assertNull($file->fresh()->deleted_at);

        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->a, "/{$id}"))->assertOk();
        $this->travel(31)->days();
        $stats = app(FilesService::class)->purgeRecycle();
        $this->assertSame(1, $stats['ficheiros']);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull(StoredFile::find($id));
    }

    public function test_the_types_are_checked_by_content_not_only_by_extension(): void
    {
        $bad = [
            'programa.pdf' => "MZ\x90\x00 programa do Windows",
            'script.pdf' => "#!/bin/sh\nrm -rf /",
            'pagina.docx' => '<!DOCTYPE html><html><script>alert(1)</script></html>',
            'codigo.pdf' => '<?php system($_GET["c"]);',
            'desenho.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'evento.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            'falso.xlsx' => "PK\x03\x04 não é um Excel",
            'pagina.html' => '<html></html>',
            'programa.exe' => "MZ",
        ];
        foreach ($bad as $name => $content) {
            $this->upload($this->adminA, $this->a, UploadedFile::fake()->createWithContent($name, $content))->assertStatus(422);
        }
        $this->assertSame(0, StoredFile::count());

        // Um Word a sério (OOXML) e um SVG sem script entram.
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();
        $this->upload($this->adminA, $this->a, new UploadedFile($tmp, 'proposta.docx', null, null, true))->assertCreated()->assertJsonPath('data.kind', 'documento');
        $svg = $this->upload($this->adminA, $this->a, UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="5" height="5"/></svg>'))->assertCreated();

        // O SVG nunca abre como página: descarrega sempre, com CSP sandbox.
        $this->get($svg->json('data.url'))->assertOk()->assertHeader('Content-Security-Policy', 'sandbox')
            ->assertHeader('Content-Disposition', 'attachment; filename=logo.svg');
    }

    public function test_the_quota_is_checked_before_the_upload(): void
    {
        config(['media.company_quota_mb' => 0]);
        $this->upload($this->adminA, $this->a, $this->pdf())->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'espaço da empresa está cheio'));
    }

    // ── Isolamento entre empresas ────────────────────────────────────────────

    public function test_a_company_never_sees_the_files_of_another(): void
    {
        $folder = FileFolder::create(['company_id' => $this->a->id, 'name' => 'Privada A']);
        $id = $this->upload($this->adminA, $this->a, $this->pdf('segredo-a.pdf'), $folder->id)->assertCreated()->json('data.id');

        $list = $this->actingAs($this->adminB, 'sanctum')->getJson($this->url($this->b))->assertOk();
        $this->assertSame([], $list->json('data.files'));
        $this->assertSame([], $list->json('data.tree'));
        $this->actingAs($this->adminB, 'sanctum')->getJson($this->url($this->b, "/{$id}"))->assertNotFound();
        $this->actingAs($this->adminB, 'sanctum')->putJson($this->url($this->b, "/{$id}"), ['name' => 'x.pdf'])->assertNotFound();
        $this->actingAs($this->adminB, 'sanctum')->deleteJson($this->url($this->b, "/{$id}"))->assertNotFound();
        $this->actingAs($this->adminB, 'sanctum')->getJson($this->url($this->b, "?folder_id={$folder->id}"))->assertNotFound();
        $this->upload($this->adminB, $this->b, $this->pdf(), $folder->id)->assertNotFound();
        $this->actingAs($this->adminB, 'sanctum')->getJson($this->url($this->a))->assertForbidden(); // a rota de outra empresa
        $this->assertSame('Privada A', $folder->fresh()->name);
    }

    // ── Pastas do sistema: a regra da área de origem ─────────────────────────

    public function test_each_system_folder_follows_the_permission_of_its_area(): void
    {
        $billing = $this->systemFile($this->a, 'xplendor:cobrancas', 'cobranca.pdf');
        $support = $this->systemFile($this->a, 'suporte', 'captura.pdf');

        // A faturação da XPLENDOR é só do Administrador; o suporte é base.
        $asUser = collect($this->actingAs($this->userA, 'sanctum')->getJson($this->url($this->a))->json('data.tree'))->pluck('system_key')->all();
        $this->assertSame(['suporte'], $asUser);
        $this->actingAs($this->userA, 'sanctum')->getJson($this->url($this->a, "/{$billing->id}"))->assertNotFound();
        $asAdmin = collect($this->actingAs($this->adminA, 'sanctum')->getJson($this->url($this->a))->json('data.tree'))->pluck('system_key')->sort()->values()->all();
        $this->assertSame(['suporte', 'xplendor:cobrancas'], $asAdmin);
        $url = $this->actingAs($this->adminA, 'sanctum')->getJson($this->url($this->a, "/{$billing->id}"))->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Os ficheiros do sistema não se apagam nem se movem aqui.
        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->a, "/{$support->id}"))->assertStatus(422);
        $this->actingAs($this->adminA, 'sanctum')->putJson($this->url($this->a, "/{$support->id}"), ['name' => 'outro.pdf'])->assertStatus(422);
        $this->upload($this->adminA, $this->a, $this->pdf(), $support->folder_id)->assertStatus(422); // não recebe envios

        // As faturas de fornecedores: o módulo, a Restauração e as Finanças.
        $ocr = $this->systemFile($this->a, 'ocr:2026-10', 'fornecedor.pdf');
        $this->actingAs($this->adminA, 'sanctum')->getJson($this->url($this->a, "/{$ocr->id}"))->assertNotFound(); // módulo desligado: a pasta não existe
        app(CompanyModuleService::class)->enable($this->a->id, 'restauracao_faturas');
        $this->actingAs($this->adminA, 'sanctum')->getJson($this->url($this->a, "/{$ocr->id}"))->assertOk();
    }

    public function test_the_guest_agency_never_sees_invoices(): void
    {
        $agency = Company::create(['nipc' => '501100003', 'fiscal_name' => 'Agência', 'plan_id' => $this->a->plan_id, 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->a->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->a->id, 'team_scope' => 'all', 'requested_at' => now()]);
        $member = User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']);
        app(CompanyModuleService::class)->enable($this->a->id, 'restauracao_faturas');
        app(CompanyModuleService::class)->enable($this->a->id, 'aftersales');
        $ocr = $this->systemFile($this->a, 'ocr:2026-10', 'fornecedor.pdf');
        $charge = $this->systemFile($this->a, 'xplendor:cobrancas', 'cobranca.pdf');
        $report = $this->systemFile($this->a, 'satisfacao', 'cliente.pdf');
        $support = $this->systemFile($this->a, 'suporte', 'captura.pdf');

        $tree = collect($this->actingAs($member, 'sanctum')->getJson($this->url($this->a))->assertOk()->json('data.tree'))->pluck('system_key')->all();
        $this->assertSame(['suporte'], $tree, 'nem faturas de fornecedores, nem faturação, nem relatórios de clientes');
        foreach ([$ocr, $charge, $report] as $f) {
            $this->actingAs($member, 'sanctum')->getJson($this->url($this->a, "/{$f->id}"))->assertNotFound();
        }
        $this->actingAs($member, 'sanctum')->getJson($this->url($this->a, "/{$support->id}"))->assertOk();
        // O Administrador do cliente vê-as todas.
        $this->assertCount(4, $this->actingAs($this->adminA, 'sanctum')->getJson($this->url($this->a))->json('data.tree'));
        // E a agência (teto: ver, criar e editar) envia, muda o nome e move, mas não apaga nem restaura.
        $id = $this->upload($member, $this->a, $this->pdf('da-agencia.pdf'))->assertCreated()->json('data.id');
        $folder = $this->actingAs($member, 'sanctum')->postJson($this->url($this->a, '/folders'), ['name' => 'Da agência'])->assertCreated()->json('data.id');
        $this->actingAs($member, 'sanctum')->putJson($this->url($this->a, "/{$id}"), ['name' => 'Proposta da agência.pdf', 'folder_id' => $folder])->assertOk();
        $this->assertSame(['Proposta da agência.pdf', $folder], [StoredFile::find($id)->name, StoredFile::find($id)->folder_id]);
        $this->actingAs($member, 'sanctum')->putJson($this->url($this->a, "/folders/{$folder}"), ['name' => 'Agência'])->assertOk();
        $this->actingAs($member, 'sanctum')->deleteJson($this->url($this->a, "/{$id}"))->assertForbidden();
        $this->actingAs($member, 'sanctum')->deleteJson($this->url($this->a, "/folders/{$folder}"))->assertForbidden();
        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->a, "/{$id}"))->assertOk();
        $this->actingAs($member, 'sanctum')->postJson($this->url($this->a, "/{$id}/restore"))->assertForbidden();
    }

    public function test_the_guest_agency_suggestion_and_the_ceiling_have_edit_but_not_delete(): void
    {
        $suggestion = \App\Models\PermissionProfile::where('system_key', \App\Access\ProfileSuggestions::GUEST_AGENCY)->firstOrFail();
        $ceiling = \App\Models\PermissionProfile::where('system_key', \App\Models\PermissionProfile::CEILING_COMPAT)->firstOrFail();
        foreach ([$suggestion, $ceiling] as $profile) {
            $actions = \App\Models\ProfilePermission::where('profile_id', $profile->id)->where('area', 'ficheiros')->orderBy('action')->pluck('action')->all();
            $this->assertSame(['criar', 'editar', 'ver'], $actions, $profile->name);
        }
    }

    public function test_the_module_starts_off_and_closes_everything(): void
    {
        $c = Company::create(['nipc' => '501100004', 'fiscal_name' => 'Sem módulo', 'plan_id' => $this->a->plan_id, 'subscription_status' => 'active']);
        $admin = User::factory()->create(['company_id' => $c->id, 'role' => 'admin']);
        $this->assertFalse(app(CompanyModuleService::class)->isEnabled($c->id, 'ficheiros'));
        $this->actingAs($admin, 'sanctum')->getJson($this->url($c))->assertForbidden()->assertJsonPath('reason', 'modulo');
    }

    public function test_the_root_access_to_another_company_is_logged(): void
    {
        $xplendor = Company::create(['nipc' => '501100005', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $this->a->plan_id, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($xplendor->id, 'ficheiros');
        $root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root']);
        $id = $this->upload($this->adminA, $this->a, $this->pdf())->json('data.id');

        $this->actingAs($root, 'sanctum')->getJson($this->url($this->a))->assertOk();
        $this->actingAs($root, 'sanctum')->getJson($this->url($this->a, "/{$id}"))->assertOk();
        $this->actingAs($root, 'sanctum')->getJson($this->url($xplendor))->assertOk(); // a própria empresa não fica registada

        $logs = DB::table('audits')->where('event', RootFileAccessLog::EVENT)->orderBy('id')->get();
        $this->assertSame(['listar', 'ver_ficheiro'], $logs->map(fn ($l) => json_decode($l->new_values, true)['acao'])->all());
        $this->assertSame([$root->id, StoredFile::class], [(int) $logs[1]->user_id, $logs[1]->auditable_type]);
    }

    // ── Imagens e vídeos: o envio da Linha Editorial, e a retenção respeita os Ficheiros ──

    public function test_images_use_the_editorial_upload_and_the_retention_keeps_them(): void
    {
        app(CompanyModuleService::class)->enable($this->a->id, 'linha_editorial');
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        $start = $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, '/uploads'), ['name' => 'Montra.jpg', 'size' => strlen($bytes), 'mime' => 'image/jpeg'])->assertCreated();
        $done = $this->actingAs($this->adminA, 'sanctum')->post($this->url($this->a, '/uploads/' . $start->json('data.id') . '/chunk'),
            ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('parte', $bytes)], ['Accept' => 'application/json'])->assertOk();
        $file = StoredFile::findOrFail($done->json('data.file.id'));
        $this->assertSame(['Montra.jpg', 'imagem'], [$file->name, $file->kind()]);
        $this->assertNotNull($file->media_asset_id);

        // Oito dias depois, a retenção ("enviados e nunca usados") não apaga o que está nos Ficheiros.
        $other = MediaAsset::create(['company_id' => $this->a->id, 'kind' => MediaAsset::IMAGE, 'disk' => 'media', 'dir' => "company_{$this->a->id}/solta",
            'original_name' => 'solta.jpg', 'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'sha256' => str_repeat('c', 64), 'status' => MediaAsset::READY]);
        $this->travel(8)->days();
        app(MediaRetentionJob::class)->handle(app(\App\Services\Media\MediaService::class), app(\App\Services\Media\DiskUsage::class), app(\App\Services\AlertService::class));
        $this->assertNotNull(MediaAsset::find($file->media_asset_id), 'guardada nos Ficheiros');
        $this->assertNull(MediaAsset::find($other->id), 'a solta é apagada, como antes');
    }

    public function test_the_signed_address_ends_with_the_file_name_and_the_header_carries_it(): void
    {
        $id = $this->upload($this->adminA, $this->a, $this->pdf('Tabela de preços (exemplo).pdf'))->assertCreated()->json('data.id');
        $file = $this->actingAs($this->adminA, 'sanctum')->getJson($this->url($this->a, "/{$id}"))->assertOk()->json('data');

        // O leitor de PDF do browser mostra o fim do endereço: o nome, não o identificador.
        $this->assertStringContainsString("/api/files/stored/{$id}/Tabela%20de%20pre%C3%A7os%20%28exemplo%29.pdf?", $file['url']);
        $inline = $this->get($file['url'])->assertOk();
        $this->assertSame("inline; filename=\"Tabela de precos (exemplo).pdf\"; filename*=utf-8''Tabela%20de%20pre%C3%A7os%20%28exemplo%29.pdf",
            $inline->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('attachment; filename=', (string) $this->get($file['download_url'])->assertOk()->headers->get('Content-Disposition'));
        // A assinatura cobre o nome: outro nome no endereço não abre.
        $this->get(str_replace('Tabela%20de', 'Outra%20de', $file['url']))->assertForbidden();

        // No R2: ver o PDF passa pela aplicação (o endereço acaba no nome); descarregar redireciona para o R2, com o nome.
        $adapter = new \League\Flysystem\InMemory\InMemoryFilesystemAdapter();
        Storage::set('r2', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['driver' => 's3']));
        config(['filesystems.disks.r2' => ['driver' => 's3', 'key' => 'chave', 'secret' => 'segredo', 'region' => 'auto', 'bucket' => 'xplendor-media',
            'endpoint' => 'https://conta.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true]]);
        $stored = StoredFile::find($id);
        Storage::disk('r2')->put($stored->path, Storage::disk($stored->disk)->get($stored->path));
        $stored->forceFill(['disk' => 'r2'])->save();
        $fromR2 = $this->get($file['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $fromR2->streamedContent());
        $this->assertStringContainsString("filename*=utf-8''Tabela%20de%20pre%C3%A7os%20%28exemplo%29.pdf", (string) $fromR2->headers->get('Content-Disposition'));
        $redirect = (string) $this->get($file['download_url'])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('response-content-disposition=' . rawurlencode("attachment; filename=\"Tabela de precos (exemplo).pdf\"; filename*=utf-8''Tabela%20de%20pre%C3%A7os%20%28exemplo%29.pdf"), $redirect);
    }
}
