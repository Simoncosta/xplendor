<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\FileFolder;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\StoredFile;
use App\Services\Leads\LeadRecipients;
use App\Support\Storage\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Os ficheiros dos documentos de financiamento: no disco privado (o local ou o R2, nunca o
 * público) e no catálogo dos Ficheiros, na pasta do sistema "Documentos de financiamento" (uma
 * subpasta por pedido, sem o nome do cliente). Os nomes são limpos e dizem só o documento
 * ("Cartão de Cidadão 1.jpg"): o nome original do ficheiro do cliente não se guarda.
 */
final class FinancingFiles
{
    public const SOURCE = 'financiamento';
    public const ROOT_KEY = 'financiamento';

    public static function folderFor(FinancingLink $link): FileFolder
    {
        if ($link->folder_id && ($folder = FileFolder::find($link->folder_id))) {
            return $folder;
        }
        $root = FileFolder::firstOrCreate(['company_id' => $link->company_id, 'system_key' => self::ROOT_KEY],
            ['name' => \App\Services\Files\SystemFolders::ROOTS[self::ROOT_KEY]['name'], 'parent_id' => null]);
        $name = mb_substr("Pedido n.º {$link->id} · " . LeadRecipients::carLabel($link->car), 0, 190);
        $folder = FileFolder::firstOrCreate(['company_id' => $link->company_id, 'system_key' => self::ROOT_KEY . ":link_{$link->id}"],
            ['name' => $name, 'parent_id' => $root->id]);
        $link->forceFill(['folder_id' => $folder->id])->save();

        return $folder;
    }

    public static function store(FinancingLink $link, string $item, UploadedFile $upload, string $ext, string $mime): FinancingDocument
    {
        $folder = self::folderFor($link);
        $n = FinancingDocument::where('financing_link_id', $link->id)->where('kind', $item)->count() + 1;
        $label = FinancingDocumentTypes::label($item, $item === 'outro' ? ($link->requested['other'] ?? null) : null);
        $name = \App\Services\Files\FileTypeGuard::cleanName("{$label} {$n}.{$ext}");
        $disk = PrivateFiles::disk();
        $path = "financiamento/company_{$link->company_id}/link_{$link->id}/" . Str::uuid() . ".{$ext}";
        Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path));
        $size = (int) $upload->getSize();
        $file = StoredFile::create([
            'company_id' => $link->company_id, 'folder_id' => $folder->id, 'name' => $name, 'extension' => $ext, 'mime' => $mime,
            'size_bytes' => $size, 'disk' => $disk, 'path' => $path, 'source' => self::SOURCE, 'source_ref' => self::SOURCE . ':' . Str::uuid(),
            'is_system' => true,
        ]);

        return FinancingDocument::create([
            'company_id' => $link->company_id, 'financing_link_id' => $link->id, 'kind' => $item, 'stored_file_id' => $file->id,
            'name' => $name, 'extension' => $ext, 'mime' => $mime, 'size_bytes' => $size,
        ]);
    }

    /** Apaga o ficheiro (do disco e do catálogo); o registo do documento fica se $keepRow (ex.: infetado). */
    public static function deleteFile(FinancingDocument $doc, bool $keepRow = false): void
    {
        $file = $doc->stored_file_id ? StoredFile::find($doc->stored_file_id) : null;
        if ($file) {
            Storage::disk($file->disk)->delete($file->path);
            $file->delete();
        }
        $keepRow ? $doc->forceFill(['stored_file_id' => null])->save() : $doc->delete();
    }

    /** Apaga todos os ficheiros de um pedido e a sua subpasta. */
    public static function deleteAll(FinancingLink $link): int
    {
        $n = 0;
        foreach (FinancingDocument::where('financing_link_id', $link->id)->get() as $doc) {
            self::deleteFile($doc);
            $n++;
        }
        if ($link->folder_id && ($folder = FileFolder::find($link->folder_id))) {
            StoredFile::where('folder_id', $folder->id)->where('source', self::SOURCE)->get()->each(function (StoredFile $f) {
                Storage::disk($f->disk)->delete($f->path);
                $f->delete();
            });
            if (! StoredFile::where('folder_id', $folder->id)->exists() && ! FileFolder::where('parent_id', $folder->id)->exists()) {
                $link->forceFill(['folder_id' => null])->save();
                $folder->delete();
            }
        }
        // No disco local ficava a pasta vazia do pedido (no R2 não há pastas).
        Storage::disk(PrivateFiles::disk())->deleteDirectory("financiamento/company_{$link->company_id}/link_{$link->id}");

        return $n;
    }
}
