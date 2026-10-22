<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\FileFolder;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\FinancingRequest;
use App\Models\StoredFile;
use App\Services\Files\SystemFolders;
use App\Services\Leads\LeadRecipients;
use App\Support\Storage\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Os ficheiros dos pedidos de documentos: no disco privado (o local ou o R2, nunca o público) e
 * no catálogo dos Ficheiros, uma subpasta por pedido, sem o nome do cliente:
 *  · os documentos de financiamento e o DUA da retoma (nome e morada do proprietário) na pasta
 *    "Documentos de financiamento" (área "financiamento", fechada à agência; source
 *    "financiamento": descarregam-se só pela rota que regista cada descarga);
 *  · as fotografias da retoma na pasta "Retomas" (seguem a lead; source "retoma").
 * Os nomes são limpos e dizem só o documento ("Titular 2 · Recibos de vencimento 1.pdf").
 */
final class FinancingFiles
{
    public const SOURCE = 'financiamento';
    public const SOURCE_TRADE_IN = 'retoma';
    public const ROOT_KEY = 'financiamento';
    public const TRADE_IN_ROOT_KEY = 'retomas';

    /** A subpasta do pedido para este item (cria-a, e à de topo, se ainda não existir). */
    public static function folderFor(FinancingRequest $request, string $item): FileFolder
    {
        $photos = $request->isTradeIn() && ! in_array($item, FinancingDocumentTypes::PERSONAL, true);
        $column = $photos ? 'photos_folder_id' : 'folder_id';
        if ($request->{$column} && ($folder = FileFolder::find($request->{$column}))) {
            return $folder;
        }
        $rootKey = $photos ? self::TRADE_IN_ROOT_KEY : self::ROOT_KEY;
        $root = FileFolder::firstOrCreate(['company_id' => $request->company_id, 'system_key' => $rootKey],
            ['name' => SystemFolders::ROOTS[$rootKey]['name'], 'parent_id' => null]);
        $car = LeadRecipients::carLabel($request->car);
        [$key, $name] = match (true) {
            $photos => ["{$rootKey}:pedido_{$request->id}", "Retoma · pedido n.º {$request->id} · {$car}"],
            $request->isTradeIn() => ["{$rootKey}:retoma_{$request->id}", "Retoma (DUA) · pedido n.º {$request->id} · {$car}"],
            default => ["{$rootKey}:pedido_{$request->id}", "Pedido n.º {$request->id} · {$car}"],
        };
        $folder = FileFolder::firstOrCreate(['company_id' => $request->company_id, 'system_key' => $key],
            ['name' => mb_substr($name, 0, 190), 'parent_id' => $root->id]);
        $request->forceFill([$column => $folder->id])->save();

        return $folder;
    }

    public static function store(FinancingLink $link, string $item, UploadedFile $upload, string $ext, string $mime): FinancingDocument
    {
        $request = $link->request;
        $folder = self::folderFor($request, $item);
        $n = FinancingDocument::where('financing_link_id', $link->id)->where('kind', $item)->count() + 1;
        $prefix = $link->holder === 2 ? 'Titular 2 · ' : '';
        $name = \App\Services\Files\FileTypeGuard::cleanName($prefix . FinancingDocumentTypes::label($item, $request) . " {$n}.{$ext}");
        $disk = PrivateFiles::disk();
        $path = "financiamento/company_{$link->company_id}/pedido_{$request->id}/link_{$link->id}/" . Str::uuid() . ".{$ext}";
        Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path));
        $size = (int) $upload->getSize();
        $source = $request->isTradeIn() && ! in_array($item, FinancingDocumentTypes::PERSONAL, true) ? self::SOURCE_TRADE_IN : self::SOURCE;
        $file = StoredFile::create([
            'company_id' => $link->company_id, 'folder_id' => $folder->id, 'name' => $name, 'extension' => $ext, 'mime' => $mime,
            'size_bytes' => $size, 'disk' => $disk, 'path' => $path, 'source' => $source, 'source_ref' => $source . ':' . Str::uuid(),
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

    /** Apaga todos os ficheiros de um link (um titular). */
    public static function deleteLinkFiles(FinancingLink $link): int
    {
        $n = 0;
        foreach (FinancingDocument::where('financing_link_id', $link->id)->get() as $doc) {
            self::deleteFile($doc);
            $n++;
        }
        $requestId = $link->financing_request_id ?: 0;
        Storage::disk(PrivateFiles::disk())->deleteDirectory("financiamento/company_{$link->company_id}/pedido_{$requestId}/link_{$link->id}");
        // Links criados antes dos pedidos (a pasta antiga do disco).
        Storage::disk(PrivateFiles::disk())->deleteDirectory("financiamento/company_{$link->company_id}/link_{$link->id}");

        return $n;
    }

    /** Apaga todos os ficheiros de um pedido (todos os titulares) e as suas subpastas. */
    public static function deleteAll(FinancingRequest $request): int
    {
        $n = 0;
        foreach (FinancingLink::where('financing_request_id', $request->id)->get() as $link) {
            $n += self::deleteLinkFiles($link);
        }
        foreach (['folder_id', 'photos_folder_id'] as $column) {
            if ($request->{$column} && ($folder = FileFolder::find($request->{$column}))) {
                StoredFile::where('folder_id', $folder->id)->whereIn('source', [self::SOURCE, self::SOURCE_TRADE_IN])->get()->each(function (StoredFile $f) {
                    Storage::disk($f->disk)->delete($f->path);
                    $f->delete();
                });
                if (! StoredFile::where('folder_id', $folder->id)->exists() && ! FileFolder::where('parent_id', $folder->id)->exists()) {
                    $request->forceFill([$column => null])->save();
                    $folder->delete();
                }
            }
        }
        Storage::disk(PrivateFiles::disk())->deleteDirectory("financiamento/company_{$request->company_id}/pedido_{$request->id}");

        return $n;
    }
}
