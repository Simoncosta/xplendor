<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Varrimento de TODAS as rotas de empresa para a gestão por agências (F1a).
 *  · Estático: toda a rota /companies/{id}|{company} tem o tenant; toda a rota /admin tem
 *    o ensure_super_admin; qualquer outra rota da API está numa lista explícita.
 *  · Matriz: uma pessoa da agência sem relação, com relação pendente, recusada, retirada,
 *    terminada, expirada, com um pedido de gestão pendente, recusado, expirado ou retirado,
 *    de outra agência ou sem estar atribuída ao cliente recebe 403
 *    em TODAS as rotas de empresa. Com relação ativa, nenhuma rota dá 403, salvo as do
 *    cliente (aprovar, acessos, decisões sobre orçamentos, dados da empresa, cobranças da
 *    XPLENDOR), que dão 403;
 *    e ligar ou desligar integrações, que é só para os admins da agência.
 *  · Registos filhos de outra empresa, pedidos debaixo da empresa gerida: 404.
 */
class AgencyTenancySweepTest extends TestCase
{
    use RefreshDatabase;

    /** Rotas da API sem empresa no endereço e fora do /admin: cada uma com o porquê. */
    private const NON_COMPANY_ROUTES = [
        'POST api/market/snapshots' => 'scraper (token próprio)',
        'GET api/media/{asset}/{variant}' => 'ficheiros por URL assinado',
        'GET api/media/{path}' => 'armazenamento público',
        'GET api/oauth/meta/callback' => 'OAuth (empresa no nonce)',
        'GET api/oauth/meta/social/callback' => 'OAuth (empresa no nonce)',
        'GET api/social-avatar/{account}' => 'URL assinado',
        'GET api/user' => 'o próprio utilizador',
        'GET api/v1/car-brands' => 'referência',
        'GET api/v1/car-categories' => 'referência',
        'GET api/v1/car-models' => 'referência',
        'GET api/v1/districts' => 'referência',
        'GET api/v1/districts/{id}/municipalities' => 'referência',
        'GET api/v1/municipalities/{id}/parishes' => 'referência',
        'POST api/v1/companies' => 'só o root cria empresas',
        'GET api/v1/companies' => 'a própria e as geridas (todas para o root)',
        'GET api/v1/impersonation/current' => 'sessão',
        'POST api/v1/impersonation/stop' => 'sessão',
        'POST api/v1/integrations/meta/callback' => 'OAuth legado (empresa no nonce)',
        'POST api/v1/login' => 'público',
        'POST api/v1/logout' => 'sessão',
        'POST api/v1/register' => 'convite (exige admin)',
        'POST api/v1/register-by-invite' => 'convite',
        'GET api/v1/user-by-invite/{token}' => 'convite',
        'POST api/v1/revoke-tokens' => 'sessão',
        'GET api/v1/plans' => 'planos', 'POST api/v1/plans' => 'planos (root)', 'GET api/v1/plans/{plan}' => 'planos',
        'PUT api/v1/plans/{plan}' => 'planos (root)', 'DELETE api/v1/plans/{plan}' => 'planos (root)',
    ];

    /** Prefixos públicos (token próprio no pedido, nunca a sessão). */
    private const PUBLIC_PREFIXES = ['api/public/'];

    /** Com relação ativa, a agência recebe 403 SÓ nestas (são do cliente). */
    private const CLIENT_ONLY = [
        'POST api/v1/companies/{id}/blogs/{blog}/approve',
        'POST api/v1/companies/{id}/blogs/{blog}/request-changes',
        'POST api/v1/companies/{id}/blogs/{blog}/back-to-draft', // desfaz uma aprovação
        'POST api/v1/companies/{id}/collaborators/{collaborator}/access',
        'POST api/v1/companies/{id}/collaborators/{collaborator}/access/resend',
        'DELETE api/v1/companies/{id}/collaborators/{collaborator}/access/invite',
        'POST api/v1/companies/{id}/collaborators/{collaborator}/access/revoke',
        'POST api/v1/companies/{id}/collaborators/{collaborator}/access/restore',
        'POST api/v1/companies/{id}/editorial/approvals/approve-all',
        'PUT api/v1/companies/{id}/editorial/approvers/{userId}',
        'POST api/v1/companies/{id}/editorial/posts/{postId}/approve',
        'POST api/v1/companies/{id}/editorial/posts/{postId}/request-changes',
        'DELETE api/v1/companies/{id}/management',
        // Pedidos de gestão recebidos e a escolha sobre as ligações da agência: só os admins da empresa.
        'GET api/v1/companies/{id}/management/requests',
        'POST api/v1/companies/{id}/management/requests/{requestId}/accept',
        'POST api/v1/companies/{id}/management/requests/{requestId}/decline',
        'POST api/v1/companies/{id}/management/connections',
        'PATCH api/v1/companies/{id}/quotes/{quote}/decision',
        'POST api/v1/companies/{id}/support-tickets/quotes/approve',
        'PATCH api/v1/companies/{id}/support-tickets/{ticket}/quote-decision',
        'POST api/v1/companies/{id}/users',
        'PUT api/v1/companies/{id}/users/{user}',
        'PUT api/v1/companies/{company}',
        'DELETE api/v1/companies/{company}',
        // Cobranças da XPLENDOR: só da própria empresa (a agência gestora não as vê).
        'GET api/v1/companies/{id}/xplendor-charges',
        'GET api/v1/companies/{id}/xplendor-charges/{chargeId}/invoice',
        'POST api/v1/companies/{id}/xplendor-charges/{chargeId}/paid',
    ];

    /** Ligar, alterar e desligar integrações e credenciais: só os ADMINS da agência (um membro comum recebe 403). */
    private const AGENCY_ADMIN_ONLY = [
        'GET api/v1/companies/{id}/integrations/social/auth-url',
        'GET api/v1/companies/{id}/integrations/social/candidates',
        'PUT api/v1/companies/{id}/integrations/social/accounts',
        'DELETE api/v1/companies/{id}/integrations/social',
        'GET api/v1/companies/{id}/integrations/meta/oauth-url',
        'POST api/v1/companies/{id}/integrations/meta/connect',
        'PATCH api/v1/companies/{id}/integrations/meta/account',
        'DELETE api/v1/companies/{id}/integrations/meta',
        'POST api/v1/companies/{id}/integrations/google/connect',
        'DELETE api/v1/companies/{id}/integrations/google',
        'POST api/v1/companies/{id}/integrations/pingwin/connect',
        'POST api/v1/companies/{id}/integrations/covermanager/connect',
        'DELETE api/v1/companies/{id}/integrations/covermanager',
        'POST api/v1/companies/{id}/carmine-connection',
        'PUT api/v1/companies/{id}/carmine-connection/{carmine_connection}',
        'DELETE api/v1/companies/{id}/carmine-connection/{carmine_connection}',
        // F1c: link de configuração do cliente e contas de anúncios da autorização.
        'GET api/v1/companies/{id}/setup-link',
        'POST api/v1/companies/{id}/setup-links',
        'POST api/v1/companies/{id}/setup-links/{linkId}/extend',
        'POST api/v1/companies/{id}/setup-links/{linkId}/revoke',
        'GET api/v1/companies/{id}/integrations/meta/ad-accounts',
        // F1-3 do marketing da restauração: confirmar as categorias das famílias e pedir sugestões à IA.
        'POST api/v1/companies/{id}/integrations/pingwin/family-categories/ai-suggest',
        'PUT api/v1/companies/{id}/integrations/pingwin/family-categories',
    ];

    private Company $agency;
    private Company $otherAgency;
    private User $member;
    private User $agencyAdmin;
    private Company $active;
    private Company $unrelated;
    /** @var array<string, Company> estado → empresa */
    private array $denied = [];
    /** @var array<string, int> parâmetro → id real na empresa ativa */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Centenas de pedidos seguidos da mesma pessoa: os limites por minuto não são o que se testa aqui.
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        Queue::fake();
        Mail::fake();
        Notification::fake();
        foreach (['media', 'public', 'local', 's3'] as $disk) {
            Storage::fake($disk);
        }

        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $make = function (string $name, array $extra = []) use ($plan): Company {
            $c = Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $plan, 'subscription_status' => 'active'] + $extra);
            foreach (ModuleRegistry::keys() as $k) {
                CompanyModule::firstOrCreate(['company_id' => $c->id, 'module_key' => $k]);
            }

            return $c;
        };
        $this->agency = $make('Agência Norte');
        $this->agency->forceFill(['agency_enabled_at' => now()])->save();
        $this->otherAgency = $make('Agência Sul');
        $this->otherAgency->forceFill(['agency_enabled_at' => now()])->save();
        $this->member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $this->agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);

        $relation = function (Company $agency, Company $managed, string $status, string $scope = 'all'): void {
            CompanyManagement::create([
                'agency_company_id' => $agency->id, 'managed_company_id' => $managed->id, 'origin' => 'platform', 'status' => $status,
                'active_key' => $status === 'active' ? $managed->id : null, 'team_scope' => $scope, 'requested_at' => now(),
            ]);
        };

        $this->active = $make('Domiway Teste');
        $relation($this->agency, $this->active, 'active');
        $this->unrelated = $make('Sem relação');
        $this->denied['sem relação'] = $this->unrelated;
        foreach (['pending', 'declined', 'withdrawn', 'ended', 'expired'] as $status) {
            $relation($this->agency, $this->denied[$status] = $make("Estado {$status}"), $status);
        }
        // Pedidos de gestão (F1d) sem relação ativa: nunca dão acesso.
        foreach (['pending', 'declined', 'expired', 'withdrawn'] as $status) {
            $target = $this->denied["pedido {$status}"] = $make("Pedido {$status}");
            \App\Models\ManagementRequest::create([
                'agency_company_id' => $this->agency->id, 'requested_by_user_id' => $this->agencyAdmin->id, 'identifier_type' => 'nipc',
                'identifier' => (string) $target->nipc, 'status' => $status, 'managed_company_id' => $target->id,
                'authorization_declared_at' => now(), 'expires_at' => $status === 'expired' ? now()->subDay() : now()->addDays(14),
            ]);
        }
        $relation($this->otherAgency, $this->denied['de outra agência'] = $make('De outra agência'), 'active');
        $relation($this->agency, $this->denied['não atribuído'] = $make('Só atribuídos'), 'active', 'assigned');

        // Registos reais na empresa gerida, para as rotas do cliente chegarem à verificação de papel.
        $client = $this->active->id;
        $clientUser = User::factory()->create(['company_id' => $client, 'role' => 'user']);
        $this->children = [
            'blog' => DB::table('blogs')->insertGetId(['company_id' => $client, 'user_id' => $clientUser->id, 'title' => 'Artigo', 'slug' => 'artigo-' . $client,
                'content' => '<p>Texto</p>', 'status' => 'in_review', 'created_at' => now(), 'updated_at' => now()]),
            'collaborator' => Collaborator::create(['company_id' => $client, 'name' => 'Ana Martins', 'role_title' => 'Comercial'])->id,
            'postId' => EditorialPost::create(['company_id' => $client, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'Menu',
                'format' => 'Carrossel', 'channel' => 'instagram', 'media_format' => 'ig_carousel', 'stage' => EditorialPost::STAGE_CLIENT_REVIEW])->id,
            'userId' => $clientUser->id,
            'user' => $clientUser->id,
            'car' => Car::factory()->create(['company_id' => $client])->id,
        ];
    }

    // ── Estático ─────────────────────────────────────────────────────────────

    public function test_every_company_route_has_the_tenant_gate_and_every_other_route_is_listed(): void
    {
        $missing = [];
        $unlisted = [];
        foreach ($this->apiRoutes() as $route) {
            $key = $this->key($route);
            $mw = $route->gatherMiddleware();
            if ($this->isCompanyRoute($route)) {
                if (! in_array('tenant', $mw, true)) {
                    $missing[] = $key;
                }
            } elseif (str_starts_with($route->uri(), 'api/v1/agencies/{agency}')) {
                if (! in_array('agency', $mw, true)) {
                    $missing[] = "{$key} (sem o portão agency)";
                }
            } elseif (str_starts_with($route->uri(), 'api/v1/admin/')) {
                if (! in_array('ensure_super_admin', $mw, true)) {
                    $missing[] = "{$key} (sem ensure_super_admin)";
                }
            } elseif (! $this->startsWithAny($route->uri(), self::PUBLIC_PREFIXES) && ! isset(self::NON_COMPANY_ROUTES[$key])) {
                $unlisted[] = $key;
            }
        }
        $this->assertSame([], $missing, 'Rotas de empresa sem o portão tenant (ou do /admin sem ensure_super_admin).');
        $this->assertSame([], $unlisted, 'Rota nova sem empresa no endereço: pôr em /companies/{id} (com tenant) ou justificar em NON_COMPANY_ROUTES.');
    }

    // ── Matriz ───────────────────────────────────────────────────────────────

    public function test_agency_without_an_active_assigned_relation_gets_403_on_every_company_route(): void
    {
        $wrong = [];
        foreach ($this->denied as $state => $company) {
            foreach ($this->companyRoutes() as $route) {
                $status = $this->hit($route, $company)->getStatusCode();
                if ($status !== 403) {
                    $wrong[] = "{$state}: {$this->key($route)} → {$status}";
                }
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_agency_member_with_an_active_relation_works_everywhere_except_client_decisions_and_integrations(): void
    {
        $this->assertActiveMatrix($this->member, [...self::CLIENT_ONLY, ...self::AGENCY_ADMIN_ONLY]);
    }

    public function test_agency_admin_with_an_active_relation_also_connects_integrations_but_never_decides_for_the_client(): void
    {
        $this->assertActiveMatrix($this->agencyAdmin, self::CLIENT_ONLY);
    }

    /** Com relação ativa: 403 exatamente nas rotas indicadas e em nenhuma outra. */
    private function assertActiveMatrix(User $actor, array $expected403): void
    {
        $wrong = [];
        // As do cliente primeiro (com corpo válido) e os DELETE no fim, para os registos reais existirem quando são precisos.
        $routes = collect($this->companyRoutes())->sortBy(fn (RouteDef $r) => in_array($this->key($r), self::CLIENT_ONLY, true) ? 0
            : (str_starts_with($this->key($r), 'DELETE ') ? 2 : 1))->values();
        foreach ($routes as $route) {
            $key = $this->key($route);
            $body = in_array($key, self::CLIENT_ONLY, true) ? $this->clientBody() : [];
            $status = $this->hit($route, $this->active, $body, $actor)->getStatusCode();
            $forbidden = in_array($key, $expected403, true);
            if ($forbidden && $status !== 403) {
                $wrong[] = "devia ser 403: {$key} → {$status}";
            } elseif (! $forbidden && $status === 403) {
                $wrong[] = "403 indevido: {$key}";
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_agency_routes_refuse_other_agencies_clients_and_non_agencies(): void
    {
        $otherMember = User::factory()->create(['company_id' => $this->otherAgency->id, 'role' => 'admin']);
        $clientUser = User::factory()->create(['company_id' => $this->active->id, 'role' => 'admin']);
        $wrong = [];
        foreach (array_filter($this->apiRoutes(), fn (RouteDef $r) => str_starts_with($r->uri(), 'api/v1/agencies/{agency}')) as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            foreach ([[$otherMember, $this->agency], [$clientUser, $this->agency], [$this->member, $this->active], [$this->member, $this->otherAgency]] as [$who, $agency]) {
                $url = '/' . preg_replace_callback('#\{(\w+)\}#', fn ($m) => $m[1] === 'agency' ? (string) $agency->id : $this->valueFor($route, $m[1]), $route->uri());
                $this->app['auth']->forgetGuards();
                $status = $this->actingAs($who, 'sanctum')->json($method, $url, ['month' => '2026-10'])->getStatusCode();
                if ($status !== 403) {
                    $wrong[] = "{$this->key($route)} ({$who->id} em {$agency->id}) → {$status}";
                }
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_child_records_of_another_company_are_not_found_under_the_managed_company(): void
    {
        $other = $this->unrelated->id;
        $car = Car::factory()->create(['company_id' => $other]);
        $collaborator = Collaborator::create(['company_id' => $other, 'name' => 'Rui', 'role_title' => 'Gestor']);
        $post = EditorialPost::create(['company_id' => $other, 'publish_date' => now()->addDays(3)->toDateString(), 'title' => 'Outra',
            'format' => 'Carrossel', 'channel' => 'instagram', 'media_format' => 'ig_carousel', 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $blog = DB::table('blogs')->insertGetId(['company_id' => $other, 'user_id' => User::factory()->create(['company_id' => $other])->id, 'title' => 'Outro',
            'slug' => 'outro', 'content' => '<p>x</p>', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $base = "/api/v1/companies/{$this->active->id}";
        foreach ([
            "{$base}/cars/{$car->id}",
            "{$base}/collaborators/{$collaborator->id}",
            "{$base}/editorial/posts/{$post->id}/workflow",
            "{$base}/blogs/{$blog}",
        ] as $url) {
            $this->actingAs($this->member, 'sanctum')->getJson($url)->assertNotFound();
        }
    }

    // ── Ajudantes ────────────────────────────────────────────────────────────

    /** @return RouteDef[] */
    private function apiRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RouteDef $r) => str_starts_with($r->uri(), 'api/')));
    }

    /** @return RouteDef[] */
    private function companyRoutes(): array
    {
        return array_values(array_filter($this->apiRoutes(), fn (RouteDef $r) => $this->isCompanyRoute($r)));
    }

    private function isCompanyRoute(RouteDef $r): bool
    {
        return (bool) preg_match('#^api/v1/companies/\{(id|company)\}#', $r->uri());
    }

    private function key(RouteDef $r): string
    {
        $method = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return "{$method} {$r->uri()}";
    }

    private function startsWithAny(string $uri, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($uri, $p)) {
                return true;
            }
        }

        return false;
    }

    /** Corpo que passa a validação das rotas do cliente (para o 403 vir do papel, não da validação). */
    private function clientBody(): array
    {
        return ['note' => 'Mudar a imagem.', 'message' => 'Mudar a imagem.', 'post_ids' => [$this->children['postId']], 'ids' => [999999],
            'email' => 'novo@exemplo.pt', 'name' => 'Novo', 'decision' => 'accepted', 'can_approve' => true, 'reason' => 'Teste.'];
    }

    private function hit(RouteDef $route, Company $company, array $body = [], ?User $actor = null)
    {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $url = preg_replace_callback('#\{(\w+)\??\}#', function ($m) use ($route, $company) {
            $name = $m[1];
            if ($name === 'id' || $name === 'company') {
                return (string) $company->id;
            }
            if ($company->is($this->active) && isset($this->children[$name])) {
                return (string) $this->children[$name];
            }

            return $this->valueFor($route, $name);
        }, $route->uri());

        $this->app['auth']->forgetGuards();

        return $this->actingAs($actor ?? $this->member, 'sanctum')->json($method, '/' . $url, $body)->baseResponse;
    }

    /** Um valor que cumpre o "where" do parâmetro (para a rota corresponder). */
    private function valueFor(RouteDef $route, string $name): string
    {
        $pattern = $route->wheres[$name] ?? null;
        foreach (['999999', '0b7e6c1a-3d5f-4a8e-9c2b-1f4d6e8a0b3c', explode('|', trim((string) $pattern, '()'))[0], 'x'] as $candidate) {
            if (! $pattern || preg_match('#^(' . $pattern . ')$#', $candidate)) {
                return $candidate;
            }
        }

        return 'x';
    }
}
