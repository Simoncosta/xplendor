<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\ConsultantSite;
use App\Models\ContentSector;
use App\Models\Development;
use App\Models\ListingImage;
use App\Models\Property;
use App\Models\SiteServerToken;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Support\Facades\DB;

/**
 * Site do consultor: a empresa de um consultor fictício (ramo Imobiliário, módulos imoveis e
 * site_consultor), o perfil do site publicado com o host, o token do contentor e as freguesias.
 * Só dados fictícios evidentes (".invalid", "Exemplo", "Fictício").
 */
trait BuildsConsultantSite
{
    protected Company $company;
    protected User $admin;
    protected ConsultantSite $site;
    protected string $token;
    protected int $parish;
    protected int $otherParish;
    protected int $planId;

    protected const HOST = 'consultor-exemplo.test';

    protected function buildConsultantSite(): void
    {
        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = $this->makeConsultantCompany('Consultor Exemplo');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'email' => 'admin@consultor-exemplo.invalid']);
        \App\Access\CompatibilityMigration::run();

        $district = DB::table('districts')->insertGetId(['name' => 'Porto', 'created_at' => now(), 'updated_at' => now()]);
        $porto = DB::table('municipalities')->insertGetId(['name' => 'Porto', 'district_id' => $district, 'dico' => '1312', 'created_at' => now(), 'updated_at' => now()]);
        $this->parish = DB::table('parishes')->insertGetId(['name' => 'Paranhos', 'municipality_id' => $porto, 'dicofre' => '131210', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherParish = DB::table('parishes')->insertGetId(['name' => 'Bonfim', 'municipality_id' => $porto, 'dicofre' => '131202', 'created_at' => now(), 'updated_at' => now()]);

        $this->site = $this->makeSite($this->company, self::HOST);
        [$token, $this->token] = SiteServerToken::issue('contentor de teste');
        $token->sites()->sync([$this->site->id]);
    }

    protected function makeConsultantCompany(string $name, array $modules = ['imoveis', 'site_consultor', 'linha_editorial']): Company
    {
        $c = Company::create(['nipc' => '999999990', 'fiscal_name' => "{$name} (fictício)", 'trade_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active',
            'address' => 'Rua Exemplo, 1', 'postal_code' => '4000-000', 'email' => 'geral@consultor-exemplo.invalid', 'mobile' => '+351 900 000 000',
            'instagram' => '@consultor.exemplo', 'facebook_pixel_id' => '123456789012',
            'content_sector_id' => ContentSector::where('slug', 'imobiliario')->value('id')]);
        foreach ($modules as $m) {
            app(CompanyModuleService::class)->enable($c->id, $m);
        }

        return $c;
    }

    protected function makeSite(Company $c, string $host, string $status = 'publicado'): ConsultantSite
    {
        $site = ConsultantSite::create(['company_id' => $c->id, 'network_label' => 'Consultor Chave Nova', 'controller_is_company' => true,
            'brokerage_legal_name' => 'Mediação Exemplo, Lda. (fictícia)', 'brokerage_ami' => '00000', 'privacy_policy_version' => '2026-10',
            'legal_links' => ['complaints_book_url' => 'https://www.livroreclamacoes.pt', 'ral' => [['name' => 'Entidade RAL Exemplo', 'url' => 'https://ral.exemplo.invalid']]]]);
        $site->forceFill(['site_status' => $status])->save();
        $site->hosts()->create(['host' => $host, 'is_primary' => true]);

        return $site;
    }

    protected function siteApi(string $method, string $uri, array $data = [], ?string $host = self::HOST, ?string $token = null)
    {
        $headers = ['Authorization' => 'Bearer ' . ($token ?? $this->token), 'Accept' => 'application/json'];
        if ($host !== null) {
            $headers['X-Site-Host'] = $host;
        }

        return $this->json($method, '/api/public/site/v1/' . ltrim($uri, '/'), $data, $headers);
    }

    protected function makeProperty(array $attrs = [], ?Company $c = null): Property
    {
        static $n = 0;
        $n++;
        $c ??= $this->company;

        return Property::create(array_merge([
            'company_id' => $c->id, 'reference' => "REF-{$n}", 'title' => "Apartamento T2 exemplo {$n}", 'kind' => 'apartamento', 'typology' => 'T2',
            'area_m2' => 90, 'parish_id' => $this->parish, 'listing' => 'venda', 'price' => 250000, 'energy_class' => 'B', 'status' => 'disponivel',
            'listed_at' => now()->subDays(10)->toDateString(), 'slug' => "apartamento-exemplo-{$n}",
        ], $attrs));
    }

    /** Publica sem passar pelo ecrã (com uma fotografia de mentira). */
    protected function publishedProperty(array $attrs = [], ?Company $c = null): Property
    {
        $p = $this->makeProperty($attrs, $c);
        $this->fakeImage($p);
        $p->forceFill(['site_status' => 'publicado', 'published_at' => now()])->save();

        return $p;
    }

    protected function fakeImage(Property|Development $owner): ListingImage
    {
        $type = $owner instanceof Property ? 'property' : 'development';

        return ListingImage::create(['company_id' => $owner->company_id, 'owner_type' => $type, 'owner_id' => $owner->id,
            'path' => "/storage/x/{$type}-{$owner->id}-1600.webp", 'path_960' => "/storage/x/{$type}-{$owner->id}-960.webp", 'path_480' => "/storage/x/{$type}-{$owner->id}-480.webp",
            'original_path' => "/storage/x/{$type}-{$owner->id}.jpg", 'width' => 1600, 'height' => 1067, 'order' => 1, 'is_cover' => true]);
    }
}
