<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AuthTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::where('email', 'admin@xplendor.eu')->firstOrFail();

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'admin',
        ]);

        $response->assertStatus(200);
    }

    public function test_user_can_logout(): void
    {
        $user = User::where('email', 'admin@xplendor.eu')->firstOrFail();
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
