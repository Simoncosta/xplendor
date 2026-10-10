<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Models\StoredFile;
use App\Models\StoredFileShortcut;
use App\Models\User;
use App\Services\Media\MediaService;
use App\Services\Storage\CompanyStorageUsage;
use App\Support\Storage\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ficheiros: as pastas (só na base de dados), o envio, mudar o nome e mover (sem copiar), a
 * reciclagem (30 dias), "Guardar numa pasta" (atalho, sem cópia) e onde cada ficheiro está em
 * uso. Os ficheiros do sistema (dos módulos) não se apagam nem se movem aqui. Tudo por empresa:
 * um identificador de outra empresa não existe (404).
 */
class FilesService
{
    public const RECYCLE_DAYS = 30;

    public function __construct(private readonly FileAccess $access, private readonly MediaService $media) {}

    // ── Ler ──────────────────────────────────────────────────────────────────

    public function folder(int $companyId, int $folderId, bool $withTrashed = false): FileFolder
    {
        $folder = FileFolder::where('company_id', $companyId)->when(! $withTrashed, fn ($q) => $q->whereNull('deleted_at'))->find($folderId);
        if (! $folder) {
            throw new HttpException(404, 'Pasta não encontrada.');
        }

        return $folder;
    }

    public function file(int $companyId, int $fileId, bool $withTrashed = false): StoredFile
    {
        $file = StoredFile::where('company_id', $companyId)->whereNull('missing_at')->when(! $withTrashed, fn ($q) => $q->whereNull('deleted_at'))->find($fileId);
        if (! $file) {
            throw new HttpException(404, 'Ficheiro não encontrado.');
        }

        return $file;
    }

    /** As pastas a que a pessoa tem acesso (para a árvore): as das pessoas e as do sistema visíveis. */
    public function folders(User $user, int $companyId): Collection
    {
        return FileFolder::where('company_id', $companyId)->whereNull('deleted_at')->orderByRaw('system_key is null')->orderBy('name')->get()
            ->filter(fn (FileFolder $f) => $this->access->canSeeFolder($user, $f))->values();
    }

    /**
     * O conteúdo de uma pasta (ou da raiz): as subpastas e os ficheiros (com os atalhos), já
     * filtrados pelo acesso. $q pesquisa pelo nome em todas as pastas a que a pessoa tem acesso.
     *
     * @return array{folders: Collection, files: Collection, shortcuts: Collection}
     */
    public function contents(User $user, int $companyId, ?int $folderId, ?string $q = null): array
    {
        $visible = $this->folders($user, $companyId);
        $visibleIds = $visible->pluck('id')->all();
        if ($q !== null && trim($q) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($q)) . '%';
            $files = StoredFile::where('company_id', $companyId)->active()->where('name', 'like', $like)
                ->where(fn ($w) => $w->whereIn('folder_id', $visibleIds)->orWhere(fn ($r) => $r->whereNull('folder_id')->where('is_system', false)))
                ->orderBy('name')->limit(200)->get()->filter(fn ($f) => $this->access->canSeeFile($user, $f))->values();

            return ['folders' => $visible->filter(fn ($f) => mb_stripos($f->name, trim($q)) !== false)->values(), 'files' => $files, 'shortcuts' => collect()];
        }
        if ($folderId !== null) {
            $folder = $this->folder($companyId, $folderId);
            if (! $this->access->canSeeFolder($user, $folder)) {
                throw new HttpException(403, 'Não tem acesso a esta pasta.');
            }
        }
        $folders = $visible->where('parent_id', $folderId)->values();
        $files = StoredFile::where('company_id', $companyId)->active()->where('folder_id', $folderId)
            ->when($folderId === null, fn ($w) => $w->where('is_system', false))
            ->orderBy('name')->get();
        if ($folderId === null && ! $this->access->can($user, $companyId, 'ficheiros.ver')) {
            $files = collect(); // na raiz só estão os ficheiros das pessoas
        }
        $shortcuts = $folderId === null ? collect() : StoredFileShortcut::where('company_id', $companyId)->where('folder_id', $folderId)
            ->whereNull('deleted_at')->with('file.folder')->get()
            ->filter(fn ($s) => $s->file && $s->file->deleted_at === null && $s->file->missing_at === null && $this->access->canSeeFile($user, $s->file))->values();

        return ['folders' => $folders, 'files' => $files, 'shortcuts' => $shortcuts];
    }

    /** A reciclagem: o que foi apagado nos Ficheiros nos últimos 30 dias. */
    public function trash(User $user, int $companyId): array
    {
        return [
            // As pastas apagadas de topo (as de dentro voltam com elas).
            'folders' => FileFolder::where('company_id', $companyId)->whereNotNull('deleted_at')->whereNull('system_key')
                ->where(fn ($q) => $q->whereNull('parent_id')->orWhereIn('parent_id', FileFolder::where('company_id', $companyId)->whereNull('deleted_at')->select('id')))
                ->orderByDesc('deleted_at')->get(),
            'files' => StoredFile::where('company_id', $companyId)->whereNotNull('deleted_at')->whereNull('missing_at')->where('is_system', false)
                ->where(fn ($q) => $q->whereNull('folder_id')->orWhereIn('folder_id', FileFolder::where('company_id', $companyId)->whereNull('deleted_at')->select('id')))
                ->orderByDesc('deleted_at')->get(),
            'shortcuts' => StoredFileShortcut::where('company_id', $companyId)->whereNotNull('deleted_at')
                ->whereIn('folder_id', FileFolder::where('company_id', $companyId)->whereNull('deleted_at')->select('id'))->with('file')->get()
                ->filter(fn ($s) => $s->file && $this->access->canSeeFile($user, $s->file))->values(),
        ];
    }

    // ── Pastas ───────────────────────────────────────────────────────────────

    public function createFolder(User $user, int $companyId, string $name, ?int $parentId): FileFolder
    {
        $name = $this->folderName($name);
        $parent = $parentId ? $this->userFolder($companyId, $parentId) : null;
        $this->assertFreeFolderName($companyId, $parent?->id, $name);

        return FileFolder::create(['company_id' => $companyId, 'parent_id' => $parent?->id, 'name' => $name, 'created_by_user_id' => $user->id]);
    }

    public function updateFolder(int $companyId, int $folderId, array $data): FileFolder
    {
        $folder = $this->userFolder($companyId, $folderId);
        $name = array_key_exists('name', $data) && $data['name'] !== null ? $this->folderName((string) $data['name']) : $folder->name;
        $parentId = array_key_exists('parent_id', $data) ? ($data['parent_id'] === null ? null : (int) $data['parent_id']) : $folder->parent_id;
        if ($parentId !== null) {
            $parent = $this->userFolder($companyId, $parentId);
            for ($p = $parent; $p !== null; $p = $p->parent) {
                if ((int) $p->id === (int) $folder->id) {
                    throw ValidationException::withMessages(['parent_id' => ['Uma pasta não pode ir para dentro de si própria.']]);
                }
            }
        }
        if ($name !== $folder->name || $parentId !== $folder->parent_id) {
            $this->assertFreeFolderName($companyId, $parentId, $name, $folder->id);
        }
        $folder->forceFill(['name' => $name, 'parent_id' => $parentId])->save();

        return $folder;
    }

    /** Para a reciclagem, com o que tem dentro (os ficheiros em uso impedem). */
    public function deleteFolder(User $user, int $companyId, int $folderId): void
    {
        $folder = $this->userFolder($companyId, $folderId);
        $ids = $this->descendantIds($folder);
        $files = StoredFile::where('company_id', $companyId)->whereIn('folder_id', $ids)->whereNull('deleted_at')->get();
        foreach ($files as $f) {
            if ($this->usage($f) !== []) {
                throw ValidationException::withMessages(['folder' => ["A pasta tem ficheiros em uso na Linha Editorial (\"{$f->name}\"). Retire-os das publicações primeiro."]]);
            }
        }
        $now = now();
        $purge = $now->copy()->addDays(self::RECYCLE_DAYS)->toDateString();
        DB::transaction(function () use ($companyId, $ids, $user, $now, $purge, $folder) {
            FileFolder::whereIn('id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $now, 'deleted_by_user_id' => $user->id, 'purge_after' => $purge]);
            StoredFile::where('company_id', $companyId)->whereIn('folder_id', $ids)->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'deleted_by_user_id' => $user->id, 'purge_after' => $purge]);
            StoredFileShortcut::whereIn('folder_id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $now, 'purge_after' => $purge]);
            // A pasta de topo apagada é a que aparece na reciclagem; as de dentro voltam com ela.
            $folder->refresh();
        });
    }

    // ── Envio ────────────────────────────────────────────────────────────────

    /** Um documento (PDF, Office, GIF, SVG), confirmado pelo conteúdo, no disco privado. */
    public function uploadDocument(User $user, int $companyId, UploadedFile $upload, ?int $folderId): StoredFile
    {
        $folder = $folderId ? $this->userFolder($companyId, $folderId) : null;
        [$ext, $mime] = FileTypeGuard::document((string) $upload->getRealPath(), (string) $upload->getClientOriginalName());
        $size = (int) $upload->getSize();
        $this->assertQuota($companyId, $size);
        $disk = PrivateFiles::disk();
        $path = "files/company_{$companyId}/" . Str::uuid() . ".{$ext}";
        $stream = fopen((string) $upload->getRealPath(), 'rb');
        Storage::disk($disk)->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return StoredFile::create([
            'company_id' => $companyId, 'folder_id' => $folder?->id, 'name' => $this->freeFileName($companyId, $folder?->id, FileTypeGuard::cleanName((string) $upload->getClientOriginalName(), $ext)),
            'extension' => $ext, 'mime' => $mime, 'size_bytes' => $size, 'disk' => $disk, 'path' => $path,
            'source' => StoredFile::SOURCE_UPLOAD, 'uploaded_by_user_id' => $user->id,
        ]);
    }

    /** Imagens e vídeos: o envio em partes da Linha Editorial (os mesmos limites e a mesma quota). */
    public function startMediaUpload(User $user, int $companyId, string $name, int $size, string $mime, ?int $folderId): MediaUpload
    {
        $folder = $folderId ? $this->userFolder($companyId, $folderId) : null;
        $upload = $this->media->start($companyId, $user, FileTypeGuard::cleanName($name), $size, $mime);
        $upload->forceFill(['for_files' => true, 'files_folder_id' => $folder?->id])->save();

        return $upload;
    }

    /** Depois da última parte: o ficheiro entra no catálogo, ligado à media (as variantes geram-se na fila). */
    public function finishMediaUpload(User $user, MediaUpload $upload): ?StoredFile
    {
        $upload->refresh();
        if (! $upload->for_files || ! $upload->completed_at || ! $upload->media_asset_id) {
            return null;
        }
        $existing = StoredFile::where('company_id', $upload->company_id)->where('source', StoredFile::SOURCE_UPLOAD)
            ->where('media_asset_id', $upload->media_asset_id)->where('folder_id', $upload->files_folder_id)->whereNull('deleted_at')->first();
        if ($existing) {
            return $existing;
        }
        $asset = MediaAsset::findOrFail($upload->media_asset_id);
        $folderId = $upload->files_folder_id && FileFolder::whereKey($upload->files_folder_id)->whereNull('deleted_at')->exists() ? (int) $upload->files_folder_id : null;

        return StoredFile::create([
            'company_id' => $upload->company_id, 'folder_id' => $folderId,
            'name' => $this->freeFileName((int) $upload->company_id, $folderId, FileTypeGuard::cleanName((string) $upload->original_name, (string) $asset->extension)),
            'extension' => $asset->extension, 'mime' => $asset->mime, 'size_bytes' => (int) $asset->size_bytes,
            'disk' => (string) $asset->disk, 'path' => (string) $asset->pathFor('original'),
            'source' => StoredFile::SOURCE_UPLOAD, 'media_asset_id' => $asset->id, 'uploaded_by_user_id' => $user->id,
        ]);
    }

    // ── Mudar o nome, mover, apagar e restaurar ──────────────────────────────

    public function updateFile(int $companyId, int $fileId, array $data): StoredFile
    {
        $file = $this->file($companyId, $fileId);
        if ($file->is_system) {
            throw ValidationException::withMessages(['file' => ['Os ficheiros do sistema tratam-se no seu módulo: não se movem nem mudam de nome aqui.']]);
        }
        $folderId = array_key_exists('folder_id', $data) ? ($data['folder_id'] === null ? null : (int) $data['folder_id']) : $file->folder_id;
        if ($folderId !== null) {
            $this->userFolder($companyId, $folderId);
        }
        $name = array_key_exists('name', $data) && $data['name'] !== null
            ? FileTypeGuard::cleanName((string) $data['name'], (string) $file->extension) // a extensão não muda
            : $file->name;
        if ($name !== $file->name || $folderId !== $file->folder_id) {
            $name = $this->freeFileName($companyId, $folderId, $name, $file->id);
        }
        $file->forceFill(['name' => $name, 'folder_id' => $folderId])->save();

        return $file;
    }

    public function deleteFile(User $user, int $companyId, int $fileId): void
    {
        $file = $this->file($companyId, $fileId);
        if ($file->is_system) {
            throw ValidationException::withMessages(['file' => ['Os ficheiros do sistema não se apagam aqui: tratam-se no seu módulo (por exemplo, as faturas têm prazos legais de conservação).']]);
        }
        $uses = $this->usage($file);
        if ($uses !== []) {
            throw ValidationException::withMessages(['file' => ['Este ficheiro está em uso em ' . count($uses) . (count($uses) === 1 ? ' publicação' : ' publicações') . ' da Linha Editorial: retire-o primeiro.']]);
        }
        $file->forceFill(['deleted_at' => now(), 'deleted_by_user_id' => $user->id, 'purge_after' => now()->addDays(self::RECYCLE_DAYS)->toDateString()])->save();
    }

    public function restoreFile(int $companyId, int $fileId): StoredFile
    {
        $file = $this->file($companyId, $fileId, true);
        if ($file->deleted_at === null) {
            return $file;
        }
        $folderId = $file->folder_id && FileFolder::whereKey($file->folder_id)->whereNull('deleted_at')->exists() ? $file->folder_id : null;
        $file->forceFill(['deleted_at' => null, 'deleted_by_user_id' => null, 'purge_after' => null, 'folder_id' => $folderId,
            'name' => $this->freeFileName($companyId, $folderId, $file->name, $file->id)])->save();

        return $file;
    }

    public function restoreFolder(int $companyId, int $folderId): FileFolder
    {
        $folder = $this->folder($companyId, $folderId, true);
        if ($folder->deleted_at === null || $folder->system_key !== null) {
            return $folder;
        }
        $parentOk = $folder->parent_id === null || FileFolder::whereKey($folder->parent_id)->whereNull('deleted_at')->exists();
        $deletedAt = $folder->deleted_at;
        $ids = $this->descendantIds($folder, true);
        DB::transaction(function () use ($companyId, $ids, $deletedAt, $folder, $parentOk) {
            // Volta o que foi apagado com ela (o apagado antes, à parte, fica na reciclagem).
            FileFolder::whereIn('id', $ids)->where('deleted_at', $deletedAt)->update(['deleted_at' => null, 'deleted_by_user_id' => null, 'purge_after' => null]);
            StoredFile::where('company_id', $companyId)->whereIn('folder_id', $ids)->where('deleted_at', $deletedAt)->update(['deleted_at' => null, 'deleted_by_user_id' => null, 'purge_after' => null]);
            StoredFileShortcut::whereIn('folder_id', $ids)->where('deleted_at', $deletedAt)->update(['deleted_at' => null, 'purge_after' => null]);
            if (! $parentOk) {
                $folder->forceFill(['parent_id' => null])->save();
            }
        });
        $folder->refresh();
        $folder->forceFill(['name' => $this->freeFolderName($companyId, $folder->parent_id, $folder->name, $folder->id)])->save();

        return $folder;
    }

    // ── Guardar numa pasta (atalho) ──────────────────────────────────────────

    public function saveToFolder(User $user, int $companyId, int $fileId, int $folderId): StoredFileShortcut
    {
        $file = $this->file($companyId, $fileId);
        if (! $this->access->canSeeFile($user, $file)) {
            throw new HttpException(404, 'Ficheiro não encontrado.');
        }
        $folder = $this->userFolder($companyId, $folderId);
        if ((int) $file->folder_id === (int) $folder->id) {
            throw ValidationException::withMessages(['folder_id' => ['O ficheiro já está nesta pasta.']]);
        }
        $shortcut = StoredFileShortcut::firstOrNew(['stored_file_id' => $file->id, 'folder_id' => $folder->id]);
        $shortcut->forceFill(['company_id' => $companyId, 'created_by_user_id' => $shortcut->created_by_user_id ?? $user->id, 'deleted_at' => null, 'purge_after' => null])->save();

        return $shortcut;
    }

    public function deleteShortcut(int $companyId, int $shortcutId): void
    {
        $s = StoredFileShortcut::where('company_id', $companyId)->whereNull('deleted_at')->findOrFail($shortcutId);
        $s->forceFill(['deleted_at' => now(), 'purge_after' => now()->addDays(self::RECYCLE_DAYS)->toDateString()])->save();
    }

    public function restoreShortcut(int $companyId, int $shortcutId): void
    {
        StoredFileShortcut::where('company_id', $companyId)->findOrFail($shortcutId)->forceFill(['deleted_at' => null, 'purge_after' => null])->save();
    }

    // ── Linha Editorial: "Escolher dos Ficheiros" ────────────────────────────

    /**
     * A media da Linha Editorial de um ficheiro (para a usar numa publicação), sem copiar o
     * ficheiro: a que já tem, ou uma nova com o original no sítio onde ele está (original_disk e
     * original_path). As variantes (miniatura, pré-visualização, poster) geram-se uma vez, na
     * fila; o vídeo passa pelo mesmo processamento.
     */
    public function mediaFor(User $user, int $companyId, int $fileId): MediaAsset
    {
        $file = $this->file($companyId, $fileId);
        if (! $this->access->canSeeFile($user, $file)) {
            throw new HttpException(404, 'Ficheiro não encontrado.');
        }
        if ($file->media_asset_id && ($asset = MediaAsset::where('company_id', $companyId)->find($file->media_asset_id)) && $asset->status !== MediaAsset::REJECTED) {
            return $asset;
        }
        $mime = (string) $file->mime;
        $kind = match (true) {
            in_array($mime, (array) config('media.image_mimes'), true) => MediaAsset::IMAGE,
            in_array($mime, (array) config('media.video_mimes'), true) => MediaAsset::VIDEO,
            default => null,
        };
        if (! $kind) {
            throw ValidationException::withMessages(['file' => ['Na Linha Editorial só se usam imagens (JPEG, PNG ou WebP) e vídeos (MP4 ou MOV).']]);
        }
        if (! Storage::disk($file->disk)->exists($file->path)) {
            throw new HttpException(404, 'O ficheiro já não existe.');
        }
        $stream = Storage::disk($file->disk)->readStream($file->path);
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $asset = DB::transaction(function () use ($file, $user, $kind, $mime, $ctx, $companyId) {
            $asset = MediaAsset::create([
                'company_id' => $companyId, 'uploaded_by_user_id' => $user->id, 'kind' => $kind, 'disk' => (string) config('media.disk', 'media'),
                'dir' => "company_{$companyId}/" . Str::uuid(), 'original_disk' => $file->disk, 'original_path' => $file->path,
                'original_name' => $file->name, 'extension' => (string) ($file->extension === 'jpeg' ? 'jpg' : $file->extension), 'mime' => $mime,
                'size_bytes' => (int) ($file->size_bytes ?? Storage::disk($file->disk)->size($file->path)), 'sha256' => hash_final($ctx), 'status' => MediaAsset::PROCESSING,
            ]);
            $file->forceFill(['media_asset_id' => $asset->id])->saveQuietly();

            return $asset;
        });
        DB::afterCommit(fn () => \App\Jobs\ProcessMediaAssetJob::dispatch($asset->id));

        return $asset;
    }

    // ── Em uso ───────────────────────────────────────────────────────────────

    /**
     * As publicações da Linha Editorial que usam o ficheiro (pela media), numa versão que ainda
     * conta (a atual ou a aprovada; as substituídas não).
     *
     * @return array<int, array{id: int, title: string, publish_date: ?string, stage: string}>
     */
    public function usage(StoredFile $file): array
    {
        if (! $file->media_asset_id) {
            return [];
        }

        return DB::table('editorial_post_version_media as vm')
            ->join('editorial_post_versions as v', 'v.id', '=', 'vm.version_id')
            ->join('editorial_posts as p', 'p.id', '=', 'v.editorial_post_id')
            ->where('vm.media_asset_id', $file->media_asset_id)->where('p.company_id', $file->company_id)
            ->where(fn ($q) => $q->whereColumn('p.current_version_id', 'v.id')->orWhereColumn('p.approved_version_id', 'v.id'))
            ->select('p.id', 'p.title', 'p.publish_date', 'p.stage')->distinct()->orderBy('p.publish_date')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'title' => (string) $r->title, 'publish_date' => $r->publish_date ? substr((string) $r->publish_date, 0, 10) : null, 'stage' => (string) $r->stage])
            ->all();
    }

    // ── Endereços e leitura ──────────────────────────────────────────────────

    /** Endereço assinado e relativo (30 minutos), gerado só depois do acesso confirmado; acaba no nome do ficheiro. */
    public function signedUrl(StoredFile $file, string $variant = 'original', bool $download = false): ?string
    {
        if ($file->media_asset_id && $variant !== 'original' && ($asset = $file->mediaAsset) && $asset->pathFor($variant)) {
            return $asset->signedUrl($variant);
        }

        return URL::temporarySignedRoute('files.stored', now()->addMinutes(30),
            array_filter(['file' => $file->id, 'name' => str_replace(['/', '\\'], '-', (string) $file->name) ?: null, 'download' => $download ? 1 : null]), absolute: false);
    }

    // ── Reciclagem: apagar de vez (tarefa diária) ────────────────────────────

    /** @return array{ficheiros: int, pastas: int, atalhos: int} */
    public function purgeRecycle(): array
    {
        $today = now()->toDateString();
        $stats = ['ficheiros' => 0, 'pastas' => 0, 'atalhos' => 0];
        $stats['atalhos'] = StoredFileShortcut::whereNotNull('deleted_at')->where('purge_after', '<', $today)->delete();
        foreach (StoredFile::whereNotNull('deleted_at')->where('is_system', false)->where('purge_after', '<', $today)->orderBy('id')->cursor() as $file) {
            $this->purgeFile($file);
            $stats['ficheiros']++;
        }
        foreach (FileFolder::whereNotNull('deleted_at')->whereNull('system_key')->where('purge_after', '<', $today)->orderByDesc('id')->get() as $folder) {
            if (! StoredFile::where('folder_id', $folder->id)->exists() && ! FileFolder::where('parent_id', $folder->id)->exists()) {
                $folder->delete();
                $stats['pastas']++;
            }
        }

        return $stats;
    }

    /** Apaga de vez um ficheiro enviado: o documento do disco, ou a media (se nada mais a usar). */
    private function purgeFile(StoredFile $file): void
    {
        if ($file->media_asset_id) {
            $asset = MediaAsset::find($file->media_asset_id);
            $file->delete();
            $stillUsed = $asset && (StoredFile::where('media_asset_id', $asset->id)->exists()
                || DB::table('editorial_post_version_media')->where('media_asset_id', $asset->id)->exists());
            if ($asset && ! $stillUsed && $asset->original_path === null) {
                $this->media->purge($asset);
            }

            return;
        }
        if ($file->source === StoredFile::SOURCE_UPLOAD) {
            Storage::disk($file->disk)->delete($file->path);
        }
        $file->delete();
    }

    // ── Ajudas ───────────────────────────────────────────────────────────────

    /** Uma pasta das pessoas desta empresa (as do sistema não recebem envios nem se mexem). */
    private function userFolder(int $companyId, int $folderId): FileFolder
    {
        $folder = $this->folder($companyId, $folderId);
        if ($folder->system_key !== null) {
            throw ValidationException::withMessages(['folder' => ['As pastas do sistema são criadas e mantidas pela plataforma: não recebem ficheiros nem mudam aqui.']]);
        }

        return $folder;
    }

    private function descendantIds(FileFolder $folder, bool $withTrashed = false): array
    {
        $ids = [$folder->id];
        $frontier = [$folder->id];
        while ($frontier !== []) {
            $frontier = FileFolder::whereIn('parent_id', $frontier)->when(! $withTrashed, fn ($q) => $q->whereNull('deleted_at'))->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function folderName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Indique o nome da pasta.']]);
        }

        return mb_substr($name, 0, 120);
    }

    private function assertFreeFolderName(int $companyId, ?int $parentId, string $name, ?int $except = null): void
    {
        $exists = FileFolder::where('company_id', $companyId)->whereNull('deleted_at')->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['Já existe uma pasta com este nome aqui.']]);
        }
    }

    private function freeFolderName(int $companyId, ?int $parentId, string $name, ?int $except = null): string
    {
        $candidate = $name;
        for ($n = 2; FileFolder::where('company_id', $companyId)->whereNull('deleted_at')->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->when($except, fn ($q) => $q->whereKeyNot($except))->exists(); $n++) {
            $candidate = "{$name} ({$n})";
        }

        return $candidate;
    }

    /** Nome livre na pasta: "relatório.pdf" → "relatório (2).pdf". */
    private function freeFileName(int $companyId, ?int $folderId, string $name, ?int $except = null): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $candidate = $name;
        for ($n = 2; StoredFile::where('company_id', $companyId)->whereNull('deleted_at')->where('folder_id', $folderId)->where('is_system', false)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->when($except, fn ($q) => $q->whereKeyNot($except))->exists(); $n++) {
            $candidate = $base . " ({$n})" . ($ext !== '' ? ".{$ext}" : '');
        }

        return $candidate;
    }

    private function assertQuota(int $companyId, int $size): void
    {
        $quota = MediaService::quotaBytes();
        if (CompanyStorageUsage::totalBytes($companyId) + $size > $quota) {
            $label = $quota >= 1073741824 && $quota % 1073741824 === 0 ? intdiv($quota, 1073741824) . ' GB' : intdiv($quota, 1048576) . ' MB';
            throw ValidationException::withMessages(['file' => ["O espaço da empresa está cheio ({$label}). Apague ficheiros ou contacte a equipa XPLENDOR."]]);
        }
    }
}
