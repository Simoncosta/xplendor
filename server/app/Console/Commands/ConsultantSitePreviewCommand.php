<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConsultantSite;
use App\Services\ConsultantSite\SitePreview;
use Illuminate\Console\Command;

/**
 * Site do consultor: o link de pré-visualização do site de uma empresa (também em rascunho),
 * assinado e temporário, para a equipa (o mesmo da página "Site do consultor").
 *
 *   php artisan consultor:preview-link 51 --base=http://simoncosta.localhost:3001
 */
class ConsultantSitePreviewCommand extends Command
{
    protected $signature = 'consultor:preview-link {company : ID da empresa} {--base= : base do endereço (por omissão, https:// + o host principal)}';

    protected $description = 'Site do consultor: o link assinado e temporário da pré-visualização do site.';

    public function handle(): int
    {
        $site = ConsultantSite::with('hosts')->where('company_id', (int) $this->argument('company'))->first();
        if (! $site || $site->hosts->isEmpty()) {
            $this->error('Esta empresa não tem perfil do site com endereço.');

            return self::FAILURE;
        }
        if ($base = (string) $this->option('base')) {
            config(['consultor_site.preview_base_url' => $base]);
        }
        $link = SitePreview::issue($site);
        $this->line($link['url']);
        $this->info('Válido até ' . $link['expires_at'] . '.');

        return self::SUCCESS;
    }
}
