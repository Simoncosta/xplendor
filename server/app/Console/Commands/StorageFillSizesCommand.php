<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExpenseCharge;
use App\Models\MediaAsset;
use App\Models\OcrInvoice;
use App\Models\SatisfactionReportPhoto;
use App\Models\SupportTicket;
use App\Services\Media\MediaService;
use App\Support\Storage\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Espaço por empresa: preenche o tamanho dos ficheiros guardados antes de a contagem existir
 * (variantes da Linha Editorial, faturas do OCR, cobranças e comprovativos, faturas dos tickets
 * e fotografias dos relatórios). Pergunta o tamanho de cada ficheiro ao disco (no R2, um pedido
 * por ficheiro, sem listar o bucket).
 *
 *   php artisan storage:fill-sizes             simulação: quantos faltam e quantos MB
 *   php artisan storage:fill-sizes --execute   grava os tamanhos (um ficheiro em falta fica com 0)
 *
 * Idempotente: só toca nos que ainda não têm tamanho.
 */
class StorageFillSizesCommand extends Command
{
    protected $signature = 'storage:fill-sizes {--execute : Grava os tamanhos (sem isto, só simula)}';

    protected $description = 'Preenche o tamanho dos ficheiros já guardados, para o espaço por empresa.';

    /** @var array<string, array{ficheiros: int, bytes: int, em_falta: int}> */
    private array $stats = [];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->line($execute ? 'A preencher os tamanhos…' : 'SIMULAÇÃO (nada muda). Use --execute para gravar.');

        foreach (MediaAsset::where('status', MediaAsset::READY)->whereNull('variants_bytes')->orderBy('id')->cursor() as $asset) {
            $disk = MediaService::diskFor($asset);
            $bytes = 0;
            $missing = false;
            foreach (['thumb', 'preview', 'poster'] as $variant) {
                $path = isset($asset->variants[$variant]) ? $asset->pathFor($variant) : null;
                if ($path === null) {
                    continue;
                }
                $size = $this->size($disk, $path);
                $missing = $missing || $size === null;
                $bytes += (int) $size;
            }
            $this->count('Linha Editorial (variantes)', $bytes, $missing);
            $execute && $asset->forceFill(['variants_bytes' => $bytes])->saveQuietly();
        }

        $ocrDisk = Storage::disk((string) config('services.openai.ocr_disk', 'local'));
        foreach (OcrInvoice::whereNotNull('image_path')->whereNull('image_size_bytes')->orderBy('id')->cursor() as $o) {
            $size = $this->size($ocrDisk, (string) $o->image_path);
            $this->count('Faturas do OCR', (int) $size, $size === null);
            $execute && $o->forceFill(['image_size_bytes' => (int) $size])->saveQuietly();
        }

        $private = Storage::disk(PrivateFiles::disk());
        foreach (ExpenseCharge::query()->where(fn ($q) => $q->whereNotNull('invoice_path')->whereNull('invoice_size_bytes'))
            ->orWhere(fn ($q) => $q->whereNotNull('proof_path')->whereNull('proof_size_bytes'))->orderBy('id')->cursor() as $c) {
            $fill = [];
            foreach (['invoice' => 'Cobranças', 'proof' => 'Comprovativos'] as $field => $label) {
                if ($c->{"{$field}_path"} !== null && $c->{"{$field}_size_bytes"} === null) {
                    $size = $this->size($private, (string) $c->{"{$field}_path"});
                    $this->count($label, (int) $size, $size === null);
                    $fill["{$field}_size_bytes"] = (int) $size;
                }
            }
            $execute && $fill !== [] && $c->forceFill($fill)->saveQuietly();
        }

        foreach (SupportTicket::whereNotNull('invoice_path')->whereNull('invoice_size_bytes')->orderBy('id')->cursor() as $t) {
            $size = $this->privateOrLegacy((string) $t->invoice_path);
            $this->count('Faturas dos pedidos de suporte', (int) $size, $size === null);
            $execute && $t->forceFill(['invoice_size_bytes' => (int) $size])->saveQuietly();
        }
        foreach (SatisfactionReportPhoto::whereNull('size_bytes')->orderBy('id')->cursor() as $p) {
            $size = $this->privateOrLegacy((string) $p->path);
            $this->count('Fotografias dos relatórios de satisfação', (int) $size, $size === null);
            $execute && $p->forceFill(['size_bytes' => (int) $size])->saveQuietly();
        }

        $rows = [];
        foreach ($this->stats as $kind => $s) {
            $rows[] = [$kind, $s['ficheiros'], number_format($s['bytes'] / 1048576, 1, ',', ' '), $s['em_falta']];
        }
        $this->table(['Tipo', 'Ficheiros sem tamanho', 'MB', 'Em falta no disco'], $rows ?: [['Nada a preencher', 0, '0,0', 0]]);

        return self::SUCCESS;
    }

    private function count(string $kind, int $bytes, bool $missing): void
    {
        $this->stats[$kind] ??= ['ficheiros' => 0, 'bytes' => 0, 'em_falta' => 0];
        $this->stats[$kind]['ficheiros']++;
        $this->stats[$kind]['bytes'] += $bytes;
        $this->stats[$kind]['em_falta'] += $missing ? 1 : 0;
    }

    private function size(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): ?int
    {
        try {
            return $disk->exists($path) ? (int) $disk->size($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** As faturas e as fotografias ainda no caminho público antigo (antes do files:make-private). */
    private function privateOrLegacy(string $path): ?int
    {
        return PrivateFiles::isLegacyPublic($path)
            ? $this->size(Storage::disk('public'), ltrim(substr($path, strlen('/storage')), '/'))
            : $this->size(Storage::disk(PrivateFiles::disk()), $path);
    }
}
