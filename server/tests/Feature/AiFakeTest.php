<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\AiFunctionSettings;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Bússola Master: o AI_FAKE responde sem rede fora de produção; em produção é ignorado. As
 * funções do Radar nascem com o seu modelo (Haiku na relevância) e ficam fora do "Aplicar a
 * todas" e do teste às cegas.
 */
class AiFakeTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;

    public function test_fake_answers_without_any_request(): void
    {
        Http::preventStrayRequests();
        config(['ai.fake' => true, 'ai.providers.anthropic.key' => '', 'ai.providers.openai.key' => '']);
        $this->assertTrue(AiGateway::available('radar_porque'));

        $r = app(AiGateway::class)->generate('radar_relevancia', new AiPrompt('S', 'Tendências: [{"id": 4, "titulo": "x"}, {"id": 7, "titulo": "y"}]', schema: ['type' => 'object']));
        $this->assertSame([4, 7], array_column($r->json['avaliacoes'], 'id'));
        $this->assertSame('claude-haiku-5-5', $r->model);
        $this->assertGreaterThan(0, $r->costUsd);
        Http::assertNothingSent();
    }

    public function test_fake_is_ignored_in_production(): void
    {
        $this->configureAi();
        config(['ai.fake' => true]);
        $this->app['env'] = 'production';
        $this->assertFalse(AiGateway::fake());
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse('Frase real.')]);
        app(AiGateway::class)->generate('radar_porque', new AiPrompt('S', 'T', json: false));
        Http::assertSentCount(1);
    }

    public function test_without_a_key_and_without_fake_the_function_is_not_available(): void
    {
        config(['ai.fake' => false, 'ai.providers.anthropic.key' => '']);
        $this->assertFalse(AiGateway::available('radar_relevancia'));
    }

    public function test_the_radar_functions_have_their_own_default_model_and_stay_out_of_apply_all_and_blind_tests(): void
    {
        $this->assertSame(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'effort' => 'low'], AiFunctionSettings::for('radar_relevancia'));
        $this->assertSame(['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'medium'], AiFunctionSettings::for('radar_porque'));
        $this->assertSame('claude-opus-5-5', AiFunctionSettings::for('caption')['model']);

        $root = \App\Models\User::factory()->create(['role' => 'root']);
        AiFunctionSettings::applyAll('claude-sonnet-5-5', 'low', $root);
        $this->assertSame('claude-haiku-5-5', AiFunctionSettings::for('radar_relevancia')['model']);
        $this->assertSame('claude-sonnet-5-5', AiFunctionSettings::for('caption')['model']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Ai\AiBlindTestService::class)->create('radar_porque', $root);
    }
}
