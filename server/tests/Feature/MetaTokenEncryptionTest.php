<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Meta Incremento 1: token cifrado em repouso + transição sem partir.
 */
class MetaTokenEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::create(['nipc' => '500001300', 'fiscal_name' => 'Stand', 'plan_id' => $planId, 'subscription_status' => 'active']);
    }

    public function test_token_is_encrypted_at_rest_and_reads_back(): void
    {
        $c = $this->company();
        $ci = CompanyIntegration::create([
            'company_id' => $c->id, 'platform' => 'meta', 'access_token' => 'SECRET-TOKEN-abc', 'status' => 'active',
        ]);

        $raw = DB::table('company_integrations')->where('id', $ci->id)->value('access_token');
        $this->assertNotSame('SECRET-TOKEN-abc', $raw);            // não está em claro
        $this->assertStringNotContainsString('SECRET-TOKEN-abc', (string) $raw);
        $this->assertSame('SECRET-TOKEN-abc', Crypt::decryptString($raw)); // é decifrável

        // O modelo devolve o valor decifrado (transparente para o pipeline).
        $this->assertSame('SECRET-TOKEN-abc', CompanyIntegration::find($ci->id)->access_token);
    }

    public function test_legacy_plaintext_token_is_still_readable(): void
    {
        $c = $this->company();
        // Token LEGADO em texto simples (insert cru, sem o cast).
        $id = DB::table('company_integrations')->insertGetId([
            'company_id' => $c->id, 'platform' => 'meta', 'access_token' => 'PLAINLEGACY', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // O cast tolerante devolve o texto simples → o pipeline continua a funcionar.
        $this->assertSame('PLAINLEGACY', CompanyIntegration::find($id)->access_token);
    }

    public function test_migration_encrypts_existing_plaintext_without_breaking_reads(): void
    {
        $c = $this->company();
        $id = DB::table('company_integrations')->insertGetId([
            'company_id' => $c->id, 'platform' => 'meta', 'access_token' => 'PLAINLEGACY2', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Corre (de novo, idempotente) a migração de cifragem.
        $migration = include base_path('database/migrations/2026_09_17_160000_encrypt_company_integration_tokens.php');
        $migration->up();

        $raw = DB::table('company_integrations')->where('id', $id)->value('access_token');
        $this->assertNotSame('PLAINLEGACY2', $raw);                 // agora cifrado na BD
        $this->assertSame('PLAINLEGACY2', CompanyIntegration::find($id)->access_token); // e ainda lê

        // Idempotente: correr outra vez não re-cifra (continua a ler igual).
        $migration->up();
        $this->assertSame('PLAINLEGACY2', CompanyIntegration::find($id)->access_token);
    }

    public function test_empty_token_on_disconnect_is_handled(): void
    {
        $c = $this->company();
        $ci = CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'meta', 'access_token' => 'x', 'status' => 'active']);
        // Desligar guarda '' — não pode rebentar.
        $ci->update(['status' => 'revoked', 'access_token' => '']);
        $this->assertSame('', CompanyIntegration::find($ci->id)->access_token);
    }
}
