<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Access\ShadowLog;
use App\Models\Car;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\ImpersonationSession;
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
 * ACL: a FOTOGRAFIA do varrimento. Cada ator de hoje faz um pedido a TODAS as rotas de
 * empresa (/companies/{id}|{company}) e fica registado o estado HTTP de cada uma. O que se
 * compara é o conjunto das rotas que dão 403 a cada ator: a migração para os perfis (F2)
 * tem de deixar este conjunto exatamente igual; as correções da F3 mudam-no de propósito,
 * uma a uma, e a diferença fica escrita.
 *
 *   ACL_SNAPSHOT=write  grava tests/Fixtures/acl/fotografia/<ator>.json (a fotografia de referência)
 *   (sem nada)          compara com a fotografia gravada
 *
 * Os atores são os que existem hoje: admin, utilizador e aprovador do cliente; o root na
 * própria empresa e noutra; o admin e o membro de uma agência com relação ativa; o root a
 * impersonar o admin do cliente; e o admin de uma empresa sem módulos ligados.
 */
class AccessSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public const DIR = 'tests/Fixtures/acl/fotografia';

    public const ACTORS = [
        'cliente_admin', 'cliente_utilizador', 'cliente_aprovador', 'root_propria', 'root_outra',
        'agencia_admin', 'agencia_membro', 'impersonacao_admin', 'sem_modulos_admin',
    ];

    /** Pedidos que mudam o estado da empresa inteira: sempre os últimos (por esta ordem). */
    private const LAST = [
        'DELETE api/v1/companies/{id}/management',
        'DELETE api/v1/companies/{company}',
    ];

    protected Company $platform;
    protected Company $client;
    protected Company $agency;
    protected Company $bare;
    /** @var array<string, User> */
    protected array $users = [];
    /** @var array<int, array<string, int>> empresa → parâmetro → id real */
    protected array $children = [];
    protected ?string $impersonationToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        Queue::fake();
        Mail::fake();
        Notification::fake();
        foreach (['media', 'public', 'local', 's3'] as $disk) {
            Storage::fake($disk);
        }
        $this->seedWorld();
    }

    /** @return array<string, array{0: string}> */
    public static function actors(): array
    {
        return array_combine(self::ACTORS, array_map(fn ($a) => [$a], self::ACTORS));
    }

    /** @dataProvider actors */
    public function test_the_403s_of_each_actor_match_the_snapshot(string $actor): void
    {
        [$statuses, $reasons] = $this->sweep($actor);
        $forbidden = array_keys(array_filter($statuses, fn ($s) => $s === 403));
        sort($forbidden);
        $file = base_path(self::DIR . "/{$actor}.json");

        if (getenv('ACL_SNAPSHOT') === 'write') {
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            ksort($statuses);
            ksort($reasons);
            file_put_contents($file, json_encode(['ator' => $actor, 'rotas' => count($statuses), 'proibidas' => $forbidden, 'motivos' => $reasons, 'estados' => $statuses],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestSkipped("Fotografia gravada: {$actor} (" . count($forbidden) . ' rotas com 403).');
        }

        $this->assertFileExists($file, 'Falta a fotografia: corra com ACL_SNAPSHOT=write.');
        $expected = json_decode((string) file_get_contents($file), true)['proibidas'];
        $this->assertSame(
            ['a_mais' => array_values(array_diff($forbidden, $expected)), 'a_menos' => array_values(array_diff($expected, $forbidden))],
            ['a_mais' => [], 'a_menos' => []],
            "A fotografia do ator {$actor} mudou (rotas com 403 a mais ou a menos).",
        );
    }

    /**
     * F1, modo sombra: em todos os pedidos da bateria, a decisão do Access bate com a
     * resposta de hoje (nenhuma "perda" nem "excesso"). Os inconclusivos (o Access recusa e
     * hoje a rota deu 404 ou 422) não são divergências: ficam listados no relatório.
     *
     * @dataProvider actors
     */
    public function test_shadow_mode_has_zero_divergences(string $actor): void
    {
        config(['access.mode' => 'shadow']);
        ShadowLog::reset();
        $this->sweep($actor);

        if (getenv('ACL_SNAPSHOT') === 'write') {
            $dir = base_path('tests/Fixtures/acl/sombra');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents("{$dir}/{$actor}.json", json_encode(['ator' => $actor, 'registos' => ShadowLog::$entries],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        $this->assertSame([], ShadowLog::divergences(), "Divergências do ator {$actor} em modo sombra.");
    }

    // ── O mundo de teste ─────────────────────────────────────────────────────

    protected function seedWorld(): void
    {
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $nipc = 500100000;
        $make = function (string $name, bool $modules = true) use ($plan, &$nipc): Company {
            $c = Company::create(['nipc' => (string) ++$nipc, 'fiscal_name' => $name, 'plan_id' => $plan, 'subscription_status' => 'active']);
            CompanyModule::where('company_id', $c->id)->delete(); // o observer aplica um preset; aqui controla-se à mão
            if ($modules) {
                foreach (ModuleRegistry::keys() as $k) {
                    CompanyModule::firstOrCreate(['company_id' => $c->id, 'module_key' => $k]);
                }
            }

            return $c;
        };

        $this->platform = $make('Plataforma');
        $this->client = $make('Cliente Teste');
        $this->agency = $make('Agência Teste');
        $this->agency->forceFill(['agency_enabled_at' => now()])->save();
        $this->bare = $make('Sem Módulos', false);

        CompanyManagement::create([
            'agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now(),
        ]);

        $u = fn (Company $c, string $role, array $extra = []) => User::factory()->create(['company_id' => $c->id, 'role' => $role] + $extra);
        $this->users = [
            'root' => $u($this->platform, 'root'),
            'cliente_admin' => $u($this->client, 'admin'),
            'cliente_utilizador' => $u($this->client, 'user'),
            'cliente_aprovador' => $u($this->client, 'user', ['can_approve_content' => true]),
            'agencia_admin' => $u($this->agency, 'admin'),
            'agencia_membro' => $u($this->agency, 'user'),
            'sem_modulos_admin' => $u($this->bare, 'admin'),
        ];

        foreach ([$this->platform, $this->client, $this->bare] as $company) {
            $this->children[$company->id] = $this->seedChildren($company);
        }

        $token = $this->users['cliente_admin']->createToken('impersonation');
        ImpersonationSession::create(['root_id' => $this->users['root']->id, 'target_user_id' => $this->users['cliente_admin']->id,
            'company_id' => $this->client->id, 'token_id' => $token->accessToken->getKey(), 'reason' => 'Teste', 'started_at' => now()]);
        $this->impersonationToken = $token->plainTextToken;
    }

    /** Registos reais na empresa, para as rotas com filhos chegarem às verificações de papel. @return array<string, int> */
    protected function seedChildren(Company $company): array
    {
        $author = User::factory()->create(['company_id' => $company->id, 'role' => 'user']);

        return [
            'blog' => DB::table('blogs')->insertGetId(['company_id' => $company->id, 'user_id' => $author->id, 'title' => 'Artigo', 'slug' => 'artigo-' . $company->id,
                'content' => '<p>Texto</p>', 'status' => 'in_review', 'created_at' => now(), 'updated_at' => now()]),
            'collaborator' => Collaborator::create(['company_id' => $company->id, 'name' => 'Ana Martins', 'role_title' => 'Comercial'])->id,
            'postId' => EditorialPost::create(['company_id' => $company->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'Menu',
                'format' => 'Carrossel', 'channel' => 'instagram', 'media_format' => 'ig_carousel', 'stage' => EditorialPost::STAGE_CLIENT_REVIEW])->id,
            'userId' => $author->id,
            'user' => $author->id,
            'car' => Car::factory()->create(['company_id' => $company->id])->id,
            'carId' => Car::factory()->create(['company_id' => $company->id])->id,
        ];
    }

    // ── O varrimento ─────────────────────────────────────────────────────────

    /** @return array{0: array<string, int>, 1: array<string, string>} rota → estado HTTP; rota → mensagem dos 403 */
    protected function sweep(string $actor): array
    {
        [$user, $company] = match ($actor) {
            'cliente_admin', 'cliente_utilizador', 'cliente_aprovador' => [$this->users[$actor], $this->client],
            'root_propria' => [$this->users['root'], $this->platform],
            'root_outra' => [$this->users['root'], $this->client],
            'agencia_admin', 'agencia_membro' => [$this->users[$actor], $this->client],
            'impersonacao_admin' => [null, $this->client],
            'sem_modulos_admin' => [$this->users['sem_modulos_admin'], $this->bare],
        };

        $out = [];
        $reasons = [];
        foreach ($this->orderedRoutes() as $route) {
            $response = $this->hit($route, $company, $user);
            $out[$this->key($route)] = $response->getStatusCode();
            if ($response->getStatusCode() === 403) {
                $reasons[$this->key($route)] = mb_substr((string) (json_decode((string) $response->getContent(), true)['message'] ?? ''), 0, 160);
            }
        }

        return [$out, $reasons];
    }

    /** @return RouteDef[] escritas antes dos DELETE; os que mudam a empresa inteira no fim. */
    protected function orderedRoutes(): array
    {
        return collect(self::companyRoutes())
            ->sortBy(fn (RouteDef $r) => sprintf('%d|%s', in_array($this->key($r), self::LAST, true) ? 3 + array_search($this->key($r), self::LAST, true)
                : (str_starts_with($this->key($r), 'DELETE ') ? 2 : 1), $this->key($r)))
            ->values()->all();
    }

    /** @return RouteDef[] */
    public static function companyRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(),
            fn (RouteDef $r) => (bool) preg_match('#^api/v1/companies/\{(id|company)\}#', $r->uri())));
    }

    public static function routeKey(RouteDef $r): string
    {
        $method = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return "{$method} {$r->uri()}";
    }

    protected function key(RouteDef $r): string
    {
        return self::routeKey($r);
    }

    /** Corpo que passa a validação de muitas rotas (para o 403 vir do papel e não da validação). */
    protected function body(): array
    {
        return ['note' => 'Mudar a imagem.', 'message' => 'Mudar a imagem.', 'ids' => [999999], 'email' => 'novo@exemplo.pt', 'name' => 'Novo',
            'decision' => 'accepted', 'can_approve' => true, 'reason' => 'Teste.'];
    }

    protected function hit(RouteDef $route, Company $company, ?User $user)
    {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $children = $this->children[$company->id] ?? [];
        $url = preg_replace_callback('#\{(\w+)\??\}#', function ($m) use ($route, $company, $children) {
            $name = $m[1];
            if ($name === 'id' || $name === 'company') {
                return (string) $company->id;
            }

            return isset($children[$name]) ? (string) $children[$name] : $this->valueFor($route, $name);
        }, $route->uri());

        $body = $method === 'DELETE' || $method === 'GET' ? [] : $this->body() + (isset($children['postId']) ? ['post_ids' => [$children['postId']]] : []);
        $this->app['auth']->forgetGuards();

        $request = $user ? $this->actingAs($user, 'sanctum') : $this->withToken((string) $this->impersonationToken);

        return $request->json($method, '/' . $url, $body)->baseResponse;
    }

    /** Um valor que cumpre o "where" do parâmetro (para a rota corresponder). */
    protected function valueFor(RouteDef $route, string $name): string
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
