<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FileFolder;
use App\Models\MediaUpload;
use App\Models\StoredFile;
use App\Models\StoredFileShortcut;
use App\Services\Files\FileAccess;
use App\Services\Files\FilesService;
use App\Services\Files\RootFileAccessLog;
use App\Services\Files\SystemFolders;
use App\Services\Media\MediaService;
use App\Services\Storage\CompanyStorageUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ficheiros: pastas, lista e pesquisa, envio (documentos; imagens e vídeos pelo envio em partes
 * da Linha Editorial), mudar o nome e mover, reciclagem, "Guardar numa pasta" e o detalhe de um
 * ficheiro (onde está em uso). As permissões da área "ficheiros" verificam-se na rota; as das
 * pastas do sistema, aqui (FileAccess). Os acessos do root a outra empresa ficam registados.
 */
class FilesController extends Controller
{
    public function __construct(private readonly FilesService $files, private readonly FileAccess $access) {}

    // GET /companies/{id}/files?folder_id=&q=
    public function index(Request $request, int $companyId)
    {
        $data = $request->validate(['folder_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:120']]);
        $user = $request->user();
        app(\App\Services\Files\SystemFileCatalog::class)->syncIfStale($companyId); // os ficheiros novos dos módulos (de 5 em 5 minutos)
        $folderId = isset($data['folder_id']) ? (int) $data['folder_id'] : null;
        $contents = $this->files->contents($user, $companyId, $folderId, $data['q'] ?? null);
        $tree = $this->files->folders($user, $companyId);
        $current = $folderId ? $tree->firstWhere('id', $folderId) : null;
        RootFileAccessLog::record($user, $companyId, $folderId ? 'abrir_pasta' : 'listar', $current);
        $usage = CompanyStorageUsage::forCompany($companyId);

        return ApiResponse::success([
            'tree' => $tree->map(fn (FileFolder $f) => $this->presentFolder($f))->values(),
            'folder' => $current ? $this->presentFolder($current) + ['path' => $this->breadcrumbs($current, $tree)] : null,
            'folders' => $contents['folders']->map(fn (FileFolder $f) => $this->presentFolder($f))->values(),
            'files' => $this->presentFiles($contents['files']),
            'shortcuts' => $contents['shortcuts']->map(fn (StoredFileShortcut $s) => ['shortcut_id' => $s->id] + $this->presentFiles(collect([$s->file]))[0])->values(),
            'usage' => ['bytes' => $usage['bytes'], 'total' => $usage['total'], 'quota' => MediaService::quotaBytes(), 'kinds' => CompanyStorageUsage::KINDS],
            'can' => [
                'create' => $this->can($companyId, 'ficheiros.criar'), 'edit' => $this->can($companyId, 'ficheiros.editar'),
                'delete' => $this->can($companyId, 'ficheiros.apagar'), 'editorial' => $this->can($companyId, 'editorial.criar'),
            ],
            'limits' => ['document_bytes' => \App\Services\Files\FileTypeGuard::MAX_DOCUMENT_BYTES, 'image_bytes' => (int) config('media.max_image_bytes'),
                'video_bytes' => (int) config('media.max_video_bytes'), 'chunk_bytes' => (int) config('media.chunk_bytes')],
        ], 'Ficheiros.');
    }

    // GET /companies/{id}/files/trash
    public function trash(Request $request, int $companyId)
    {
        $t = $this->files->trash($request->user(), $companyId);
        RootFileAccessLog::record($request->user(), $companyId, 'abrir_reciclagem');

        return ApiResponse::success([
            'folders' => $t['folders']->map(fn (FileFolder $f) => $this->presentFolder($f))->values(),
            'files' => $this->presentFiles($t['files']),
            'shortcuts' => $t['shortcuts']->map(fn (StoredFileShortcut $s) => ['shortcut_id' => $s->id, 'shortcut_deleted_at' => optional($s->deleted_at)->toIso8601String()] + $this->presentFiles(collect([$s->file]))[0])->values(),
            'days' => FilesService::RECYCLE_DAYS,
        ], 'Reciclagem.');
    }

    // GET /companies/{id}/files/{fileId}
    public function show(Request $request, int $companyId, int $fileId)
    {
        $file = $this->files->file($companyId, $fileId);
        abort_unless($this->access->canSeeFile($request->user(), $file), 404, 'Ficheiro não encontrado.');
        RootFileAccessLog::record($request->user(), $companyId, 'ver_ficheiro', $file);

        return ApiResponse::success($this->presentFiles(collect([$file]))[0] + [
            'usage' => $this->files->usage($file),
            'shortcuts' => $file->shortcuts()->whereNull('deleted_at')->with('folder')->get()->map(fn ($s) => ['id' => $s->id, 'folder_id' => $s->folder_id, 'folder' => $s->folder?->name])->values(),
        ], 'Ficheiro.');
    }

    // POST /companies/{id}/files/folders   { name, parent_id? }
    public function storeFolder(Request $request, int $companyId)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'parent_id' => ['nullable', 'integer']]);

        return ApiResponse::success($this->presentFolder($this->files->createFolder($request->user(), $companyId, $data['name'], $data['parent_id'] ?? null)), 'Pasta criada.', 201);
    }

    // PUT /companies/{id}/files/folders/{folderId}   { name?, parent_id? }
    public function updateFolder(Request $request, int $companyId, int $folderId)
    {
        $data = $request->validate(['name' => ['sometimes', 'nullable', 'string', 'max:120'], 'parent_id' => ['sometimes', 'nullable', 'integer']]);

        return ApiResponse::success($this->presentFolder($this->files->updateFolder($companyId, $folderId, $data)), 'Pasta guardada.');
    }

    public function destroyFolder(Request $request, int $companyId, int $folderId)
    {
        $this->files->deleteFolder($request->user(), $companyId, $folderId);

        return ApiResponse::success(null, 'Pasta enviada para a reciclagem.');
    }

    public function restoreFolder(int $companyId, int $folderId)
    {
        return ApiResponse::success($this->presentFolder($this->files->restoreFolder($companyId, $folderId)), 'Pasta restaurada.');
    }

    // POST /companies/{id}/files/documents   multipart { file, folder_id? }
    public function storeDocument(Request $request, int $companyId)
    {
        $data = $request->validate(['file' => ['required', 'file'], 'folder_id' => ['nullable', 'integer']]);
        $file = $this->files->uploadDocument($request->user(), $companyId, $request->file('file'), isset($data['folder_id']) ? (int) $data['folder_id'] : null);

        return ApiResponse::success($this->presentFiles(collect([$file]))[0], 'Ficheiro enviado.', 201);
    }

    // POST /companies/{id}/files/uploads   { name, size, mime, folder_id? } (imagens e vídeos)
    public function startUpload(Request $request, int $companyId)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1'], 'mime' => ['required', 'string', 'max:60'], 'folder_id' => ['nullable', 'integer']]);
        $upload = $this->files->startMediaUpload($request->user(), $companyId, $data['name'], (int) $data['size'], $data['mime'], isset($data['folder_id']) ? (int) $data['folder_id'] : null);

        return ApiResponse::success(['id' => $upload->id, 'received_bytes' => 0, 'size_bytes' => $upload->size_bytes, 'chunk_bytes' => (int) config('media.chunk_bytes')], 'Envio iniciado.', 201);
    }

    // POST /companies/{id}/files/uploads/{uploadId}/chunk   { offset, chunk }
    public function chunk(Request $request, int $companyId, string $uploadId)
    {
        $data = $request->validate(['offset' => ['required', 'integer', 'min:0'], 'chunk' => ['required', 'file']]);
        $upload = MediaUpload::where('company_id', $companyId)->where('for_files', true)->find($uploadId);
        abort_if(! $upload, 404, 'Envio não encontrado.');
        try {
            $upload = app(MediaService::class)->appendChunk($upload, (int) $data['offset'], $request->file('chunk'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() === 409) {
                return ApiResponse::error('A parte não continua o envio; retome do ponto indicado.', 409, ['received_bytes' => $upload->fresh()->received_bytes]);
            }
            throw $e;
        }
        $file = $this->files->finishMediaUpload($request->user(), $upload);

        return ApiResponse::success(['id' => $upload->id, 'received_bytes' => (int) $upload->received_bytes, 'completed' => $upload->completed_at !== null,
            'file' => $file ? $this->presentFiles(collect([$file]))[0] : null], $file ? 'Ficheiro recebido.' : 'Parte recebida.');
    }

    // PUT /companies/{id}/files/{fileId}   { name?, folder_id? }
    public function update(Request $request, int $companyId, int $fileId)
    {
        $data = $request->validate(['name' => ['sometimes', 'nullable', 'string', 'max:200'], 'folder_id' => ['sometimes', 'nullable', 'integer']]);
        $this->assertVisible($request, $companyId, $fileId);

        return ApiResponse::success($this->presentFiles(collect([$this->files->updateFile($companyId, $fileId, $data)]))[0], 'Ficheiro guardado.');
    }

    public function destroy(Request $request, int $companyId, int $fileId)
    {
        $this->assertVisible($request, $companyId, $fileId);
        $this->files->deleteFile($request->user(), $companyId, $fileId);

        return ApiResponse::success(null, 'Ficheiro enviado para a reciclagem.');
    }

    public function restore(int $companyId, int $fileId)
    {
        return ApiResponse::success($this->presentFiles(collect([$this->files->restoreFile($companyId, $fileId)]))[0], 'Ficheiro restaurado.');
    }

    // POST /companies/{id}/files/{fileId}/shortcuts   { folder_id }  "Guardar numa pasta"
    public function saveToFolder(Request $request, int $companyId, int $fileId)
    {
        $data = $request->validate(['folder_id' => ['required', 'integer']]);
        $s = $this->files->saveToFolder($request->user(), $companyId, $fileId, (int) $data['folder_id']);

        return ApiResponse::success(['shortcut_id' => $s->id, 'folder_id' => $s->folder_id], 'Guardado na pasta.', 201);
    }

    public function destroyShortcut(int $companyId, int $shortcutId)
    {
        $this->files->deleteShortcut($companyId, $shortcutId);

        return ApiResponse::success(null, 'Atalho enviado para a reciclagem (o ficheiro continua onde estava).');
    }

    public function restoreShortcut(int $companyId, int $shortcutId)
    {
        $this->files->restoreShortcut($companyId, $shortcutId);

        return ApiResponse::success(null, 'Atalho restaurado.');
    }

    // ── Apresentação ─────────────────────────────────────────────────────────

    private function assertVisible(Request $request, int $companyId, int $fileId): void
    {
        abort_unless($this->access->canSeeFile($request->user(), $this->files->file($companyId, $fileId)), 404, 'Ficheiro não encontrado.');
    }

    private function presentFolder(FileFolder $f): array
    {
        return ['id' => $f->id, 'parent_id' => $f->parent_id, 'name' => $f->name, 'system' => $f->system_key !== null, 'system_key' => $f->system_key,
            'system_root' => $f->system_key ? SystemFolders::root($f->system_key) : null, 'deleted_at' => optional($f->deleted_at)->toIso8601String(),
            'purge_after' => optional($f->purge_after)->toDateString()];
    }

    private function breadcrumbs(FileFolder $folder, Collection $tree): array
    {
        $out = [];
        for ($f = $folder; $f !== null; $f = $f->parent_id ? $tree->firstWhere('id', $f->parent_id) : null) {
            array_unshift($out, ['id' => $f->id, 'name' => $f->name]);
        }

        return $out;
    }

    /** Os ficheiros, com os endereços assinados e quantas publicações os usam (numa só consulta). */
    private function presentFiles(Collection $files): array
    {
        $assets = $files->pluck('media_asset_id')->filter()->unique()->values();
        $uses = $assets->isEmpty() ? collect() : DB::table('editorial_post_version_media as vm')
            ->join('editorial_post_versions as v', 'v.id', '=', 'vm.version_id')
            ->join('editorial_posts as p', 'p.id', '=', 'v.editorial_post_id')
            ->whereIn('vm.media_asset_id', $assets)
            ->where(fn ($q) => $q->whereColumn('p.current_version_id', 'v.id')->orWhereColumn('p.approved_version_id', 'v.id'))
            ->selectRaw('vm.media_asset_id, count(distinct p.id) as n')->groupBy('vm.media_asset_id')->pluck('n', 'media_asset_id');
        $files = (new \Illuminate\Database\Eloquent\Collection($files->all()))->loadMissing('mediaAsset');
        // Documentos de financiamento: sem endereços diretos; descarregam-se pela rota que regista.
        $financing = \App\Models\FinancingDocument::whereIn('stored_file_id', $files->where('source', \App\Services\Financing\FinancingFiles::SOURCE)->pluck('id'))
            ->pluck('id', 'stored_file_id');

        return $files->map(function (StoredFile $f) use ($uses, $financing) {
            if ($f->source === \App\Services\Financing\FinancingFiles::SOURCE) {
                return [
                    'id' => $f->id, 'name' => $f->name, 'extension' => $f->extension, 'mime' => $f->mime, 'kind' => $f->kind(),
                    'size_bytes' => $f->size_bytes, 'folder_id' => $f->folder_id, 'system' => true, 'source' => $f->source,
                    'created_at' => optional($f->created_at)->toIso8601String(), 'deleted_at' => null, 'purge_after' => null,
                    'media_asset_id' => null, 'media_status' => null, 'in_use' => 0, 'thumb_url' => null, 'preview_url' => null,
                    'url' => null, 'download_url' => null, 'financing_document_id' => $financing[$f->id] ?? null,
                ];
            }
            $asset = $f->mediaAsset;
            $url = $f->deleted_at ? null : $this->files->signedUrl($f);
            // Uma imagem fora da media (ex.: as da viatura): a miniatura é o próprio ficheiro, se for leve.
            $selfThumb = ! $asset && $url && in_array($f->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && (int) $f->size_bytes <= 3 * 1024 * 1024;

            return [
                'id' => $f->id, 'name' => $f->name, 'extension' => $f->extension, 'mime' => $f->mime, 'kind' => $f->kind(),
                'size_bytes' => $f->size_bytes, 'folder_id' => $f->folder_id, 'system' => (bool) $f->is_system, 'source' => $f->source,
                'created_at' => optional($f->created_at)->toIso8601String(), 'deleted_at' => optional($f->deleted_at)->toIso8601String(),
                'purge_after' => optional($f->purge_after)->toDateString(),
                'media_asset_id' => $f->media_asset_id, 'media_status' => $asset?->status,
                'in_use' => (int) ($uses[$f->media_asset_id] ?? 0),
                'thumb_url' => $asset && $asset->pathFor('thumb') ? $asset->signedUrl('thumb') : ($selfThumb ? $url : null),
                'preview_url' => $asset && $asset->pathFor('preview') ? $asset->signedUrl('preview') : null,
                'url' => $url,
                'download_url' => $f->deleted_at ? null : $this->files->signedUrl($f, 'original', true),
            ];
        })->values()->all();
    }
}
