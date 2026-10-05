<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\SocialConnectionService;
use App\Support\MetaTokenExpiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Expiração dos tokens da Meta: a Meta devolve expires_at = 0 quando o token não expira.
 * 0 ou ausente grava NULL ("sem data de expiração"), nunca 1970 (que o MariaDB recusa e
 * que deu "Server Error" ao religar os anúncios). NULL nunca é tratado como expirado.
 * Corre também no MariaDB (o SQLite aceita 1970 e não apanhava o erro).
 */
class MetaTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const LONG_TOKEN = 'EAAlong-token-xyz';

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.meta.app_id' => '111', 'services.meta.app_secret' => 'segredo',
            'services.meta.redirect_uri' => 'https://dev.exemplo.pt/api/oauth/meta/callback',
            'services.meta.social_redirect_uri' => null,
        ]);
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500080001', 'fiscal_name' => 'Stand Lopes', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    /** debug_token com a expiração indicada (null = campo ausente). */
    private function fakeMeta(mixed $expiresAt, array $scopes = ['ads_read']): void
    {
        $debug = ['user_id' => '9988', 'is_valid' => true, 'scopes' => $scopes];
        if ($expiresAt !== null) {
            $debug['expires_at'] = $expiresAt;
        }
        Http::fake(function (HttpRequest $r) use ($debug) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/oauth/access_token')) {
                return Http::response(['access_token' => self::LONG_TOKEN, 'token_type' => 'bearer']);
            }
            if (str_ends_with($path, '/debug_token')) {
                return Http::response(['data' => $debug]);
            }

            return Http::response(['data' => []]);
        });
    }

    public static function expiries(): array
    {
        return [
            'expiração normal' => ['normal'],
            'zero (não expira)' => [0],
            'ausente' => [null],
        ];
    }

    private function expected(mixed $case): ?int
    {
        return $case === 'normal' ? now()->addDays(60)->startOfSecond()->timestamp : null;
    }

    private function value(mixed $case): mixed
    {
        return $case === 'normal' ? now()->addDays(60)->timestamp : $case;
    }

    #[DataProvider('expiries')]
    public function test_ads_callback_saves_the_expiry_or_null_never_1970(mixed $case): void
    {
        $this->fakeMeta($this->value($case));
        $url = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/meta/oauth-url")->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();

        $this->get('/api/oauth/meta/callback?code=abc&state=' . $q['state'])
            ->assertRedirect('https://dev.exemplo.pt/app/companies/' . $this->company->id . '?meta=choose_account');

        $integration = CompanyIntegration::where('company_id', $this->company->id)->where('platform', 'meta')->sole();
        $this->assertSame('active', $integration->status);
        $this->assertSame($this->expected($case), $integration->token_expires_at?->timestamp);
        $raw = DB::table('company_integrations')->where('id', $integration->id)->value('token_expires_at');
        $this->assertStringNotContainsString('1970', (string) $raw);
        // NULL é "sem data", não expirado.
        $this->assertFalse($integration->isTokenExpired());
    }

    #[DataProvider('expiries')]
    public function test_legacy_callback_and_connect_save_the_expiry_or_null(mixed $case): void
    {
        $this->fakeMeta($this->value($case));
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/companies/{$this->company->id}/integrations/meta/connect", [
            'short_lived_token' => 'EAAshort', 'account_id' => '123',
        ])->assertOk();
        $this->assertSame($this->expected($case), CompanyIntegration::where('company_id', $this->company->id)->value('token_expires_at') ? CompanyIntegration::where('company_id', $this->company->id)->first()->token_expires_at->timestamp : null);

        $url = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/meta/oauth-url")->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/integrations/meta/callback', ['code' => 'x', 'state' => $q['state'], 'account_id' => '456'])->assertOk();
        $integration = CompanyIntegration::where('company_id', $this->company->id)->sole();
        $this->assertSame($this->expected($case), $integration->token_expires_at?->timestamp);
        $this->assertFalse($integration->isTokenExpired());
    }

    #[DataProvider('expiries')]
    public function test_social_callback_saves_the_expiry_or_null(mixed $case): void
    {
        $this->fakeMeta($this->value($case), SocialConnection::SCOPES);
        $url = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/social/auth-url")->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();

        $this->get('/api/oauth/meta/social/callback?code=abc&state=' . $q['state'])
            ->assertRedirect('https://dev.exemplo.pt/app/companies/' . $this->company->id . '?social=choose');

        $connection = SocialConnection::where('company_id', $this->company->id)->sole();
        $this->assertSame($this->expected($case), $connection->token_expires_at?->timestamp);
        $this->assertSame(SocialConnection::STATUS_PENDING_SELECTION, $connection->status);
        $status = app(SocialConnectionService::class)->status($this->company->id);
        $this->assertSame($case === 'normal' ? $connection->token_expires_at->toIso8601String() : null, $status['token_expires_at']);
    }

    public function test_null_expiry_is_never_treated_as_expired_by_the_sync(): void
    {
        $integration = CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'meta', 'access_token' => self::LONG_TOKEN,
            'account_id' => '123', 'status' => 'active', 'token_expires_at' => null]);
        Http::fake(fn () => Http::response(['data' => []]));

        // A listagem de campanhas devolve 401 "Token Meta expirado" quando o token está expirado.
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/meta/adsets")->assertOk();
        $this->assertSame('active', $integration->fresh()->status);

        // Com data no passado, sim.
        $integration->update(['token_expires_at' => now()->subDay()]);
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/meta/adsets")->assertStatus(401);
        $this->assertSame('expired', $integration->fresh()->status);
    }

    public function test_expiry_helper(): void
    {
        $this->assertNull(MetaTokenExpiry::fromDebug(['expires_at' => 0]));
        $this->assertNull(MetaTokenExpiry::fromDebug(['expires_at' => '0']));
        $this->assertNull(MetaTokenExpiry::fromDebug([]));
        $this->assertNull(MetaTokenExpiry::fromDebug(['expires_at' => 'nunca']));
        $this->assertNull(MetaTokenExpiry::fromDebug(['expires_at' => -5]));
        $this->assertSame(1790000000, MetaTokenExpiry::fromDebug(['expires_at' => 1790000000])->timestamp);
    }
}
