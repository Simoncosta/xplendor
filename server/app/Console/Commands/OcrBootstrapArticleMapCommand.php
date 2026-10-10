<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplier;
use App\Services\SupplierArticleMapService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — F2b: (re)constrói o mapa "NIF do fornecedor + código do fornecedor → artigo" a partir
 * das linhas reais do PingWin (F4) e das linhas de fornecedor dos artigos. Idempotente; nunca
 * mexe nas entradas aprendidas à mão (manual / ocr_link). Só a nossa BD (não fala com o PingWin).
 *
 *   php artisan ocr:bootstrap-article-map 5
 */
class OcrBootstrapArticleMapCommand extends Command
{
    protected $signature = 'ocr:bootstrap-article-map {company : ID da empresa (ex.: 5 = Yuko)}';

    protected $description = 'Constrói o mapa de aprendizagem fornecedor+código → artigo (F4 + fichas dos artigos).';

    public function handle(SupplierArticleMapService $map): int
    {
        $companyId = (int) $this->argument('company');
        $s = $map->bootstrap($companyId);
        $this->table(['pares', 'fornecedores', 'conflitos', 'novos', 'atualizados', 'manuais intocados'],
            [[$s['pairs'], $s['suppliers'], $s['conflicts'], $s['inserted'], $s['updated'], $s['kept_manual']]]);

        $names = PingwinSupplier::where('company_id', $companyId)->get(['tax_number', 'name'])
            ->mapWithKeys(fn ($x) => [SupplierArticleMapService::nif($x->tax_number) => $x->name]);
        foreach (array_slice($s['conflict_examples'], 0, 3) as $c) {
            $arts = collect($c['articles'])->map(function ($a) {
                $i = PingwinCatalogItem::find($a['article_id']);

                return "{$i?->code} {$i?->description} (×{$a['seen']}, {$a['last']})";
            })->implode('  |  ');
            $this->line("  conflito: " . ($names[$c['nif']] ?? $c['nif']) . " código {$c['code']} → {$arts}");
        }

        return self::SUCCESS;
    }
}
