<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConsultantSite;
use App\Models\SiteServerToken;
use Illuminate\Console\Command;

/**
 * Site do consultor: o token do contentor do site (um por instalação). Emite um token novo e
 * mostra-o UMA vez (fica só o hash), liga um site a um token existente, ou revoga um token.
 *
 *   php artisan consultor:site-token --name="xplendor-consultor (produção)" --site=12
 *   php artisan consultor:site-token --attach=3 --site=15
 *   php artisan consultor:site-token --revoke=3
 */
class ConsultantSiteTokenCommand extends Command
{
    protected $signature = 'consultor:site-token {--name= : nome do token novo} {--site=* : ID da empresa cujo site o token pode servir}
        {--attach= : ID de um token existente a que ligar os sites} {--revoke= : ID do token a revogar}';

    protected $description = 'Site do consultor: emite, liga ou revoga o token do contentor do site.';

    public function handle(): int
    {
        if ($id = $this->option('revoke')) {
            $token = SiteServerToken::find((int) $id);
            if (! $token) {
                $this->error('Token não encontrado.');

                return self::FAILURE;
            }
            $token->forceFill(['revoked_at' => now()])->save();
            $this->info("Token #{$token->id} revogado.");

            return self::SUCCESS;
        }
        $sites = ConsultantSite::whereIn('company_id', array_map('intval', (array) $this->option('site')))->get();
        if ($sites->isEmpty()) {
            $this->error('Indique pelo menos um --site=<ID da empresa> com perfil do site.');

            return self::FAILURE;
        }
        if ($id = $this->option('attach')) {
            $token = SiteServerToken::whereNull('revoked_at')->find((int) $id);
            if (! $token) {
                $this->error('Token não encontrado ou revogado.');

                return self::FAILURE;
            }
            $token->sites()->syncWithoutDetaching($sites->pluck('id'));
            $this->info("Token #{$token->id} ligado a " . $sites->count() . ' site(s).');

            return self::SUCCESS;
        }
        [$token, $plain] = SiteServerToken::issue((string) ($this->option('name') ?: 'xplendor-consultor'));
        $token->sites()->sync($sites->pluck('id'));
        $this->info("Token #{$token->id} criado e ligado a " . $sites->count() . ' site(s). Guarde-o já no ambiente do contentor (CONSULTOR_API_TOKEN): não volta a ser mostrado.');
        $this->line($plain);

        return self::SUCCESS;
    }
}
