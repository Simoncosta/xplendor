<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\StoredFile;
use App\Support\Storage\PrivateFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Ficheiros: o registo dos ficheiros que os módulos já guardam, nas pastas do sistema. NADA é
 * movido, copiado ou apagado: cada ficheiro fica com o disco e o caminho de origem (stored_files,
 * is_system), com uma chave estável (source_ref). O que sai do módulo fica com missing_at (deixa
 * de aparecer); o que volta, volta. Corre pelo comando files:catalog (simulação por omissão), de
 * noite (files-catalog-nightly) e ao abrir os Ficheiros (no máximo de 5 em 5 minutos por empresa).
 */
class SystemFileCatalog
{
    /** Origens (para o comando e os relatórios) => rótulo */
    public const SOURCES = [
        'media' => 'Linha Editorial', 'ocr' => 'Faturas do OCR', 'cobranca' => 'Cobranças', 'comprovativo' => 'Comprovativos',
        'fatura_pedido' => 'Faturas dos pedidos de suporte', 'orcamento' => 'Orçamentos da XPLENDOR', 'captura' => 'Capturas dos pedidos de suporte',
        'foto_relatorio' => 'Fotografias dos relatórios de satisfação', 'viatura' => 'Imagens das viaturas', 'viatura_original' => 'Originais das viaturas',
        'viatura_360' => '360 das viaturas', 'logotipo' => 'Logótipo', 'banner' => 'Banners do blog', 'equipa' => 'Fotografias da equipa',
        'avatar' => 'Avatares', 'modelo' => 'Modelos de documento',
    ];

    private const MONTHS = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    private const MIME = [
        'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** @var array<string, int> pasta (empresa:chave) => id, por execução */
    private array $folders = [];

    /** Sincroniza se a última vez foi há mais de 5 minutos (ao abrir os Ficheiros). */
    public function syncIfStale(int $companyId): void
    {
        if (Cache::add("files:catalog:{$companyId}", 1, now()->addMinutes(5))) {
            try {
                $this->sync($companyId, true);
            } catch (\Throwable $e) {
                Log::warning('[Ficheiros] Registo dos ficheiros do sistema falhou', ['company_id' => $companyId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
    }

    /**
     * Regista (ou simula) os ficheiros de uma empresa.
     *
     * @return array<string, array{total: int, novos: int, atualizados: int, em_falta: int, sem_ficheiro: int}>
     */
    public function sync(int $companyId, bool $execute): array
    {
        $stats = [];
        $existing = StoredFile::where('company_id', $companyId)->where('is_system', true)->whereIn('source', array_keys(self::SOURCES))->get()->keyBy('source_ref');
        $seen = [];
        foreach ($this->items($companyId) as $item) {
            $source = $item['source'];
            $stats[$source] ??= ['total' => 0, 'novos' => 0, 'atualizados' => 0, 'em_falta' => 0, 'sem_ficheiro' => 0];
            $stats[$source]['total']++;
            $row = $existing->get($item['ref']);
            // Só se regista o que existe no disco (um registo sem ficheiro aparecia partido).
            if ((! $row || $row->path !== $item['path'] || $row->disk !== $item['disk']) && ! $this->exists($item['disk'], $item['path'])) {
                $stats[$source]['sem_ficheiro']++;

                continue;
            }
            $seen[$item['ref']] = true;
            if (! $row) {
                $stats[$source]['novos']++;
                if ($execute) {
                    StoredFile::create($this->attributes($companyId, $item, null) + ['source_ref' => $item['ref'], 'is_system' => true, 'company_id' => $companyId]);
                }

                continue;
            }
            $folderId = $execute ? $this->folder($companyId, $item['folder']) : $row->folder_id;
            $changed = $row->path !== $item['path'] || $row->disk !== $item['disk'] || $row->name !== $item['name'] || (int) $row->folder_id !== (int) $folderId
                || $row->missing_at !== null || ($item['media_asset_id'] ?? null) !== $row->media_asset_id;
            if ($changed) {
                $stats[$source]['atualizados']++;
                if ($execute) {
                    $row->forceFill($this->attributes($companyId, $item, $row) + ['missing_at' => null])->save();
                }
            }
        }
        foreach ($existing as $ref => $row) {
            if (! isset($seen[$ref]) && $row->missing_at === null) {
                $stats[$row->source] ??= ['total' => 0, 'novos' => 0, 'atualizados' => 0, 'em_falta' => 0, 'sem_ficheiro' => 0];
                $stats[$row->source]['em_falta']++;
                if ($execute) {
                    $row->forceFill(['missing_at' => now()])->save();
                }
            }
        }
        ksort($stats);

        return $stats;
    }

    private function attributes(int $companyId, array $item, ?StoredFile $row): array
    {
        $samePath = $row && $row->path === $item['path'] && $row->disk === $item['disk'];
        $size = $item['size'] ?? ($samePath ? $row->size_bytes : null) ?? $this->diskSize($item['disk'], $item['path']);
        $ext = strtolower(pathinfo($item['path'], PATHINFO_EXTENSION));

        return [
            'folder_id' => $this->folder($companyId, $item['folder']), 'name' => mb_substr($item['name'], 0, 200), 'extension' => $ext ?: null,
            'mime' => $item['mime'] ?? (self::MIME[$ext] ?? null), 'size_bytes' => $size, 'disk' => $item['disk'], 'path' => $item['path'],
            'source' => $item['source'], 'source_id' => $item['source_id'] ?? null, 'media_asset_id' => $item['media_asset_id'] ?? null,
        ];
    }

    private function exists(string $disk, string $path): bool
    {
        try {
            return $path !== '' && Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    private function diskSize(string $disk, string $path): ?int
    {
        try {
            return Storage::disk($disk)->exists($path) ? (int) Storage::disk($disk)->size($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A pasta do sistema (cria-a, e à de topo, se ainda não existir). @param array{0: string, 1: ?string} $folder [chave, nome da subpasta] */
    private function folder(int $companyId, array $folder): int
    {
        [$key, $childName] = $folder;
        $cacheKey = "{$companyId}:{$key}";
        if (isset($this->folders[$cacheKey])) {
            return $this->folders[$cacheKey];
        }
        $rootKey = SystemFolders::root($key);
        $rootName = SystemFolders::ROOTS[$rootKey]['name'];
        $root = FileFolder::firstOrCreate(['company_id' => $companyId, 'system_key' => $rootKey], ['name' => $rootName, 'parent_id' => null]);
        if ($root->name !== $rootName) {
            $root->forceFill(['name' => $rootName])->save();
        }
        $id = $root->id;
        if ($key !== $rootKey) {
            $child = FileFolder::firstOrCreate(['company_id' => $companyId, 'system_key' => $key], ['name' => (string) $childName, 'parent_id' => $root->id]);
            if ($childName !== null && $child->name !== $childName) {
                $child->forceFill(['name' => $childName])->save();
            }
            $id = $child->id;
        }

        return $this->folders[$cacheKey] = $id;
    }

    // ── Os registadores: cada um lê a tabela do módulo ───────────────────────

    /** @return \Generator<array> */
    private function items(int $companyId): \Generator
    {
        yield from $this->media($companyId);
        yield from $this->ocr($companyId);
        yield from $this->billing($companyId);
        yield from $this->support($companyId);
        yield from $this->reports($companyId);
        yield from $this->cars($companyId);
        yield from $this->brand($companyId);
        yield from $this->team($companyId);
        yield from $this->templates($companyId);
    }

    private function month(?string $date): array
    {
        $d = $date ? substr($date, 0, 7) : now()->format('Y-m');
        [$y, $m] = array_map('intval', explode('-', $d));

        return [$d, self::MONTHS[$m] . " de {$y}"];
    }

    /** "/storage/x" → ["public", "x"]; um caminho relativo do disco público fica como está. */
    private function publicPath(string $value): array
    {
        return ['public', ltrim(str_starts_with($value, '/storage/') ? substr($value, strlen('/storage/')) : $value, '/')];
    }

    private function clean(string $name): string
    {
        return FileTypeGuard::cleanName($name);
    }

    private function media(int $companyId): \Generator
    {
        // As media enviadas nas publicações (as dos Ficheiros ficam nas pastas das pessoas).
        $rows = DB::table('media_assets')->where('company_id', $companyId)->where('status', '!=', 'rejected')
            ->whereNotIn('id', DB::table('stored_files')->where('source', 'upload')->whereNotNull('media_asset_id')->select('media_asset_id'))
            ->get(['id', 'disk', 'dir', 'extension', 'mime', 'size_bytes', 'original_name', 'original_deleted_at', 'variants', 'original_path', 'original_disk', 'created_at']);
        foreach ($rows as $a) {
            $variants = json_decode((string) $a->variants, true) ?: [];
            if ($a->original_path) {
                continue; // o original é de outro módulo: já aparece na pasta de origem
            }
            $path = $a->original_deleted_at ? (isset($variants['preview']) ? "{$a->dir}/{$variants['preview']}" : null) : "{$a->dir}/original.{$a->extension}";
            if (! $path) {
                continue;
            }
            [$key, $label] = $this->month($a->created_at);
            yield ['ref' => "media:{$a->id}", 'source' => 'media', 'source_id' => $a->id, 'folder' => ["editorial:{$key}", ucfirst($label)],
                'name' => $this->clean((string) ($a->original_name ?: "media-{$a->id}.{$a->extension}")), 'disk' => (string) $a->disk, 'path' => $path,
                'mime' => $a->original_deleted_at ? 'image/webp' : $a->mime, 'size' => $a->original_deleted_at ? null : (int) $a->size_bytes, 'media_asset_id' => $a->id];
        }
    }

    private function ocr(int $companyId): \Generator
    {
        $disk = (string) config('services.openai.ocr_disk', 'local');
        $q = DB::table('ocr_invoices')->where('company_id', $companyId)->whereNotNull('image_path');
        if (\Illuminate\Support\Facades\Schema::hasColumn('ocr_invoices', 'deleted_at')) {
            $q->whereNull('deleted_at'); // as apagadas tratam-se na reciclagem do OCR
        }
        foreach ($q->get(['id', 'image_path', 'image_mime', 'image_size_bytes', 'supplier_name', 'number', 'issue_date', 'created_at']) as $o) {
            [$key, $label] = $this->month($o->issue_date ?: $o->created_at);
            $ext = strtolower(pathinfo((string) $o->image_path, PATHINFO_EXTENSION)) ?: 'pdf';
            $title = trim(($o->supplier_name ?: 'Fatura') . ' ' . ($o->number ?: "#{$o->id}"));
            yield ['ref' => "ocr:{$o->id}", 'source' => 'ocr', 'source_id' => $o->id, 'folder' => ["ocr:{$key}", ucfirst($label)],
                'name' => $this->clean("{$title}.{$ext}"), 'disk' => $disk, 'path' => (string) $o->image_path, 'mime' => $o->image_mime, 'size' => $o->image_size_bytes];
        }
    }

    private function billing(int $companyId): \Generator
    {
        $private = PrivateFiles::disk();
        foreach (DB::table('expense_charges as c')->leftJoin('expenses as e', 'e.id', '=', 'c.expense_id')->where('c.company_id', $companyId)
            ->get(['c.id', 'c.invoice_path', 'c.invoice_name', 'c.invoice_size_bytes', 'c.proof_path', 'c.proof_name', 'c.proof_size_bytes', 'c.proof_mime', 'e.description']) as $c) {
            if ($c->invoice_path) {
                yield ['ref' => "charge:{$c->id}:invoice", 'source' => 'cobranca', 'source_id' => $c->id, 'folder' => ['xplendor:cobrancas', 'Cobranças'],
                    'name' => $this->clean($c->invoice_name ?: (($c->description ?: "Cobrança {$c->id}") . '.pdf')), 'disk' => $private, 'path' => $c->invoice_path,
                    'mime' => 'application/pdf', 'size' => $c->invoice_size_bytes];
            }
            if ($c->proof_path) {
                yield ['ref' => "charge:{$c->id}:proof", 'source' => 'comprovativo', 'source_id' => $c->id, 'folder' => ['xplendor:comprovativos', 'Comprovativos'],
                    'name' => $this->clean($c->proof_name ?: "Comprovativo {$c->id}." . pathinfo($c->proof_path, PATHINFO_EXTENSION)), 'disk' => $private,
                    'path' => $c->proof_path, 'mime' => $c->proof_mime, 'size' => $c->proof_size_bytes];
            }
        }
        foreach (DB::table('support_tickets')->where('company_id', $companyId)->whereNotNull('invoice_path')->get(['id', 'invoice_path', 'invoice_size_bytes']) as $t) {
            [$disk, $path] = PrivateFiles::isLegacyPublic($t->invoice_path) ? $this->publicPath($t->invoice_path) : [$private, $t->invoice_path];
            yield ['ref' => "ticket:{$t->id}:invoice", 'source' => 'fatura_pedido', 'source_id' => $t->id, 'folder' => ['xplendor:pedidos', 'Pedidos de suporte'],
                'name' => "Pedido {$t->id}, fatura.pdf", 'disk' => $disk, 'path' => $path, 'mime' => 'application/pdf', 'size' => $t->invoice_size_bytes];
        }
        foreach (DB::table('quote_versions as v')->join('quotes as q', 'q.id', '=', 'v.quote_id')->where('q.company_id', $companyId)->whereNotNull('v.pdf_path')
            ->get(['v.id', 'v.pdf_path', 'v.number', 'v.version']) as $v) {
            yield ['ref' => "quote_version:{$v->id}", 'source' => 'orcamento', 'source_id' => $v->id, 'folder' => ['xplendor:orcamentos', 'Orçamentos'],
                'name' => $this->clean("Orçamento {$v->number} v{$v->version}.pdf"), 'disk' => 'local', 'path' => $v->pdf_path, 'mime' => 'application/pdf'];
        }
    }

    private function support(int $companyId): \Generator
    {
        foreach (DB::table('support_tickets')->where('company_id', $companyId)->whereNotNull('screenshot_path')->get(['id', 'screenshot_path']) as $t) {
            [$disk, $path] = $this->publicPath((string) $t->screenshot_path);
            yield ['ref' => "ticket:{$t->id}:screenshot", 'source' => 'captura', 'source_id' => $t->id, 'folder' => ['suporte', null],
                'name' => "Pedido {$t->id}, captura de ecrã." . (pathinfo($path, PATHINFO_EXTENSION) ?: 'webp'), 'disk' => $disk, 'path' => $path];
        }
    }

    private function reports(int $companyId): \Generator
    {
        $private = PrivateFiles::disk();
        foreach (DB::table('satisfaction_report_photos as p')->join('satisfaction_reports as r', 'r.id', '=', 'p.satisfaction_report_id')
            ->where('r.company_id', $companyId)->get(['p.id', 'p.path', 'p.order', 'p.size_bytes', 'r.id as report_id']) as $p) {
            [$disk, $path] = PrivateFiles::isLegacyPublic($p->path) ? $this->publicPath($p->path) : [$private, $p->path];
            yield ['ref' => "report_photo:{$p->id}", 'source' => 'foto_relatorio', 'source_id' => $p->id, 'folder' => ['satisfacao', null],
                'name' => "Relatório {$p->report_id}, fotografia {$p->order}.webp", 'disk' => $disk, 'path' => $path, 'mime' => 'image/webp', 'size' => $p->size_bytes];
        }
    }

    private function cars(int $companyId): \Generator
    {
        $cars = DB::table('cars as c')->leftJoin('car_brands as b', 'b.id', '=', 'c.car_brand_id')->leftJoin('car_models as m', 'm.id', '=', 'c.car_model_id')
            ->where('c.company_id', $companyId)->get(['c.id', 'c.license_plate', 'b.name as brand', 'm.name as model'])->keyBy('id');
        $label = fn ($id) => ($car = $cars->get($id)) ? trim(trim(($car->brand ?? '') . ' ' . ($car->model ?? '')) . ($car->license_plate ? " ({$car->license_plate})" : '')) ?: "Viatura {$id}" : "Viatura {$id}";
        foreach (DB::table('car_images')->where('company_id', $companyId)->orderBy('car_id')->orderBy('order')->get(['id', 'car_id', 'image', 'original_path', 'order']) as $i) {
            if ($i->image) {
                [$disk, $path] = $this->publicPath((string) $i->image);
                yield ['ref' => "car_image:{$i->id}:image", 'source' => 'viatura', 'source_id' => $i->id, 'folder' => ["viaturas:{$i->car_id}", $label($i->car_id)],
                    'name' => 'Imagem ' . ((int) $i->order + 1) . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'webp'), 'disk' => $disk, 'path' => $path];
            }
            if ($i->original_path) {
                [$disk, $path] = $this->publicPath((string) $i->original_path);
                yield ['ref' => "car_image:{$i->id}:original", 'source' => 'viatura_original', 'source_id' => $i->id, 'folder' => ["viaturas:{$i->car_id}", $label($i->car_id)],
                    'name' => 'Original ' . ((int) $i->order + 1) . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg'), 'disk' => $disk, 'path' => $path];
            }
        }
        foreach (DB::table('car_360_exterior_images')->where('company_id', $companyId)->get(['id', 'car_id', 'image', 'order']) as $i) {
            [$disk, $path] = $this->publicPath((string) $i->image);
            yield ['ref' => "car360:{$i->id}", 'source' => 'viatura_360', 'source_id' => $i->id, 'folder' => ["viaturas:{$i->car_id}", $label($i->car_id)],
                'name' => '360, ' . ((int) $i->order + 1) . '.webp', 'disk' => $disk, 'path' => $path];
        }
    }

    private function brand(int $companyId): \Generator
    {
        $logo = DB::table('companies')->where('id', $companyId)->value('logo_path');
        if ($logo) {
            [$disk, $path] = $this->publicPath((string) $logo);
            yield ['ref' => "company:{$companyId}:logo", 'source' => 'logotipo', 'source_id' => $companyId, 'folder' => ['marca', null],
                'name' => 'Logótipo.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'webp'), 'disk' => $disk, 'path' => $path];
        }
        foreach (DB::table('blogs')->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('banner')->get(['id', 'title', 'banner']) as $b) {
            [$disk, $path] = $this->publicPath((string) $b->banner);
            yield ['ref' => "blog:{$b->id}:banner", 'source' => 'banner', 'source_id' => $b->id, 'folder' => ['blog', null],
                'name' => $this->clean('Banner, ' . ($b->title ?: "artigo {$b->id}") . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'webp')), 'disk' => $disk, 'path' => $path];
        }
    }

    private function team(int $companyId): \Generator
    {
        foreach (DB::table('collaborators')->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('photo_path')->get(['id', 'name', 'photo_path']) as $c) {
            [$disk, $path] = $this->publicPath((string) $c->photo_path);
            yield ['ref' => "collaborator:{$c->id}", 'source' => 'equipa', 'source_id' => $c->id, 'folder' => ['equipa', null],
                'name' => $this->clean(($c->name ?: "Colaborador {$c->id}") . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'webp')), 'disk' => $disk, 'path' => $path];
        }
        foreach (DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('avatar')->where('avatar', '!=', '')->get(['id', 'name', 'avatar']) as $u) {
            [$disk, $path] = $this->publicPath((string) $u->avatar);
            yield ['ref' => "user:{$u->id}:avatar", 'source' => 'avatar', 'source_id' => $u->id, 'folder' => ['equipa', null],
                'name' => $this->clean('Avatar, ' . ($u->name ?: "utilizador {$u->id}") . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'webp')), 'disk' => $disk, 'path' => $path];
        }
    }

    private function templates(int $companyId): \Generator
    {
        foreach (DB::table('document_templates')->where('company_id', $companyId)->whereNotNull('original_path')->get(['id', 'name', 'original_path']) as $t) {
            yield ['ref' => "doc_template:{$t->id}", 'source' => 'modelo', 'source_id' => $t->id, 'folder' => ['modelos', null],
                'name' => $this->clean(($t->name ?: "Modelo {$t->id}") . '.docx'), 'disk' => 'local', 'path' => (string) $t->original_path];
        }
    }
}
