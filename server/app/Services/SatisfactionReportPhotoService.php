<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SatisfactionReport;
use App\Models\SatisfactionReportPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * DMS Pós-venda — upload PÚBLICO de fotos do cliente (Incremento 2).
 *
 * Segurança: o ficheiro é SEMPRE re-encodado via Intervention (->toWebp()) — o
 * original cru NUNCA é guardado, o que descarta EXIF/metadados e qualquer
 * payload malicioso embebido. Teto de 3 contado aqui (defesa no servidor).
 */
class SatisfactionReportPhotoService
{
    public const MAX_PHOTOS = 3;
    private const MAX_EDGE = 1600; // downscale — chega para redes sociais.

    public function count(SatisfactionReport $report): int
    {
        return $report->photos()->count();
    }

    /**
     * Re-encoda e guarda a foto; regista o consentimento (momento do upload).
     *
     * @throws \DomainException quando o teto de 3 já foi atingido.
     */
    public function upload(SatisfactionReport $report, UploadedFile $file): SatisfactionReportPhoto
    {
        if ($this->count($report) >= self::MAX_PHOTOS) {
            throw new \DomainException('Limite de fotos atingido.');
        }

        $folder = "company_{$report->company_id}/reports/{$report->id}";
        Storage::disk('public')->makeDirectory($folder);

        $basename = now()->format('YmdHisv') . substr(md5(uniqid('', true)), 0, 6) . '.webp';
        $diskPath = "{$folder}/{$basename}";

        // Re-encode defensivo: lê, reduz e converte para WebP (sem original cru).
        $binary = (new ImageManager(new Driver()))
            ->read($file->getRealPath())
            ->scaleDown(self::MAX_EDGE, self::MAX_EDGE)
            ->toWebp(82)
            ->toString();

        Storage::disk('public')->put($diskPath, $binary);

        return SatisfactionReportPhoto::create([
            'satisfaction_report_id' => $report->id,
            'path'                   => Storage::url($diskPath),
            'order'                  => $this->count($report) + 1,
            'social_consent_at'      => now(),
        ]);
    }

    /**
     * Apaga a foto — ficheiro do storage E registo (RGPD: direito ao
     * esquecimento; nada de cópia escondida).
     */
    public function delete(SatisfactionReportPhoto $photo): void
    {
        $diskPath = ltrim(str_replace('/storage', '', (string) $photo->path), '/');
        if ($diskPath !== '') {
            Storage::disk('public')->delete($diskPath);
        }
        $photo->delete();
    }
}
