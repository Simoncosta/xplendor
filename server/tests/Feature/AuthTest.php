<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Entrar e sair. Os testes correm no SQLite em memória (phpunit.xml): o utilizador é criado aqui,
 * em vez de se contar com o administrador da base de dev.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Empresa Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);

        return User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'email' => 'admin@xplendor.eu', 'password' => Hash::make('admin')]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = $this->user();

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'admin',
        ]);

        $response->assertStatus(200);
    }

    public function test_user_can_logout(): void
    {
        $user = $this->user();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token"
        ])->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logout successful',
            ]);
    }
}
