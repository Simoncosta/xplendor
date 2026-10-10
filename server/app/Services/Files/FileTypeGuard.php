<?php

declare(strict_types=1);

namespace App\Services\Files;

use Illuminate\Validation\ValidationException;

/**
 * Ficheiros: os documentos aceites, confirmados pelo CONTEÚDO (não só pela extensão), e os
 * nomes limpos. As imagens (JPEG, PNG, WebP) e os vídeos (MP4, MOV) seguem o envio da Linha
 * Editorial (MediaService), que confirma o tipo real no fim. Programas, scripts e HTML são
 * sempre recusados. O SVG só entra sem script e nunca abre no browser como página.
 */
final class FileTypeGuard
{
    public const MAX_DOCUMENT_BYTES = 50 * 1024 * 1024;

    /** extensão => tipo MIME com que se guarda e serve */
    public const DOCUMENTS = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml',
    ];

    /** As imagens e os vídeos do envio em partes (Linha Editorial). */
    public const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'];

    private const OOXML_DIR = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];

    /**
     * Confirma um documento pelo conteúdo. Devolve [extensão, mime] ou recusa (422).
     *
     * @return array{0: string, 1: string}
     */
    public static function document(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, self::MEDIA_EXTENSIONS, true)) {
            self::reject('As imagens e os vídeos enviam-se pelo envio de media (com miniaturas).');
        }
        if (! isset(self::DOCUMENTS[$ext])) {
            self::reject('Tipo de ficheiro não aceite. Aceites: imagens, vídeos, PDF e documentos do Office (Word, Excel, PowerPoint e OpenDocument).');
        }
        $size = (int) @filesize($path);
        if ($size <= 0) {
            self::reject('O ficheiro está vazio.');
        }
        if ($size > self::MAX_DOCUMENT_BYTES) {
            self::reject('Os documentos podem ter até ' . intdiv(self::MAX_DOCUMENT_BYTES, 1048576) . ' MB.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        if (self::looksExecutable($head)) {
            self::reject('Este ficheiro parece um programa ou um script: não é aceite.');
        }
        $ok = match ($ext) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'doc', 'xls', 'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'docx', 'xlsx', 'pptx' => self::isOoxml($path, self::OOXML_DIR[$ext]),
            'odt', 'ods', 'odp' => self::isOpenDocument($path, self::DOCUMENTS[$ext]),
            'gif' => str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a'),
            'svg' => self::isSafeSvg($path),
            default => false,
        };
        if (! $ok) {
            self::reject("O conteúdo do ficheiro não corresponde a um .{$ext}.");
        }

        return [$ext, self::DOCUMENTS[$ext]];
    }

    /** Programas, scripts e HTML, pelos primeiros bytes. */
    public static function looksExecutable(string $head): bool
    {
        $start = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        foreach (["MZ", "\x7FELF", "\xCA\xFE\xBA\xBE", "\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE", '#!'] as $magic) {
            if (str_starts_with($start, $magic)) {
                return true;
            }
        }
        $lower = strtolower(substr($start, 0, 1024));

        return str_starts_with($lower, '<?php') || str_starts_with($lower, '<!doctype html') || str_starts_with($lower, '<html')
            || str_starts_with($lower, '<script') || str_contains($lower, '<?php');
    }

    /** Nome limpo: sem caminhos, sem caracteres de controlo nem reservados, até 200 carateres. */
    public static function cleanName(string $name, ?string $forceExtension = null): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");
        $ext = $forceExtension ?? strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = trim(pathinfo($name, PATHINFO_FILENAME), " .");
        if ($base === '') {
            $base = 'ficheiro';
        }
        $base = mb_substr($base, 0, 200 - ($ext !== '' ? mb_strlen($ext) + 1 : 0));

        return $ext !== '' ? "{$base}.{$ext}" : $base;
    }

    private static function isOoxml(string $path, string $dir): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->locateName('[Content_Types].xml') !== false;
        $hasDir = false;
        for ($i = 0; $ok && $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (str_starts_with($n, $dir)) {
                $hasDir = true;
            }
            if (preg_match('/\.(exe|dll|bat|cmd|ps1|js|vbs|sh)$/i', $n)) {
                $ok = false; // um executável escondido dentro do documento
            }
        }
        $zip->close();

        return $ok && $hasDir;
    }

    private static function isOpenDocument(string $path, string $mime): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = trim((string) $zip->getFromName('mimetype')) === $mime;
        $zip->close();

        return $ok;
    }

    private static function isSafeSvg(string $path): bool
    {
        $svg = (string) file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
        $lower = strtolower($svg);
        if (! str_contains($lower, '<svg')) {
            return false;
        }

        return ! preg_match('/<script|<foreignobject|<!entity|javascript:|\son[a-z]+\s*=/i', $svg);
    }

    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
