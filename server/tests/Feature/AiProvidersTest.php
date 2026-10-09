<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\CompanyBrandProfile;
use App\Models\CompanyModule;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\MediaAsset;
use App\Models\OcrInvoice;
use App\Models\User;
use App\Services\Ai\AiFunctionSettings;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiPrompt;
use App\Services\CarDescriptionService;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * A camada de fornecedores da IA (Anthropic e OpenAI, sempre simulados): o formato dos pedidos
 * de cada um, os custos, o modelo por função e "Aplicar a todas" só pelo root (com histórico),
 * o OCR intocado, os limites (os erros com resposta do fornecedor contam), o verificador do
 * português de Portugal (pede de novo uma vez) e "Gerar legenda" com a imagem.
 */
class AiProvidersTest extends TestCase
{
    use RefreshDatabase;
    use FakesAi;

    private Company $a;
    private Company $b;
    private User $userA;
    private User $adminA;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Lisbon'));
        $this->configureAi();
        Storage::fake('media');

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500070001', 'fiscal_name' => 'Tasca do Largo Lda', 'trade_name' => 'Tasca do Largo', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500070002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $x = Company::create(['nipc' => '500070003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
            EditorialMonth::create(['company_id' => $c->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
        }
        CompanyBrandProfile::create(['company_id' => $this->a->id, 'tone_of_voice' => 'Descontraído e tradicional', 'hashtags_default' => ['#tascadolargo'],
            'cta_default' => 'Reserve a sua mesa.', 'emoji_policy' => 'light']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $x->id, 'role' => 'root']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}{$suffix}";
    }

    private function makePost(array $attrs = []): EditorialPost
    {
        $post = EditorialPost::create(array_merge(['company_id' => $this->a->id, 'publish_date' => '2026-10-20', 'title' => 'Menu de São Martinho com castanhas',
            'format' => 'Bastidores', 'channel' => 'instagram', 'media_format' => 'ig_carousel', 'keyword' => 'São Martinho', 'stage' => EditorialPost::STAGE_PRODUCTION], $attrs));
        $post->networks()->create(['company_id' => $this->a->id, 'network' => 'facebook', 'media_format' => 'fb_photos', 'position' => 1]);

        return $post->fresh();
    }

    private function imageAsset(string $bytes): MediaAsset
    {
        $dir = 'company_' . $this->a->id . '/' . uniqid('a', true);
        Storage::disk('media')->put("{$dir}/preview.webp", $bytes);

        return MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => $dir, 'original_name' => 'castanhas.jpg', 'extension' => 'jpg',
            'mime' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'width' => 1080, 'height' => 1350, 'sha256' => hash('sha256', $dir),
            'variants' => ['thumb' => 'preview.webp', 'preview' => 'preview.webp'], 'status' => MediaAsset::READY]);
    }

    private function captionAnswer(string $instagram = 'Castanhas assadas na brasa e jeropiga da casa. Venha celebrar o São Martinho connosco.'): array
    {
        return ['proposals' => [
            ['angle' => 'Tradição', 'captions' => ['instagram' => $instagram, 'facebook' => 'O São Martinho está à porta: castanhas e jeropiga.'], 'hashtags' => ['#saomartinho', 'tascadolargo'], 'cta' => 'Reserve a sua mesa.'],
            ['angle' => 'Convite direto', 'captions' => ['instagram' => 'Este fim de semana há castanhas.', 'facebook' => 'Reserve já para o São Martinho.'], 'hashtags' => [], 'cta' => 'Ligue-nos.'],
        ]];
    }

    // ── Os dois fornecedores ──────────────────────────────────────────────────

    public function test_anthropic_request_follows_the_current_docs_and_records_costs(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse(['ok' => 'Texto em português de Portugal.'], ['input_tokens' => 2000, 'cache_read_input_tokens' => 1000, 'output_tokens' => 800])]);

        $r = app(AiGateway::class)->generate('ideas', new AiPrompt('Instrução da função.', 'O pedido.', [], ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'string']]]));

        Http::assertSent(function (Request $req) {
            return $req->url() === 'https://api.anthropic.com/v1/messages'
                && $req->hasHeader('x-api-key', 'test-anthropic') && $req->hasHeader('anthropic-version', '2023-06-01')
                && $req['model'] === 'claude-opus-5-5'
                // Raciocínio sempre ligado: o esforço em output_config, sem thinking, sem temperature, sem ferramenta forçada.
                && $req['output_config']['effort'] === 'medium' && $req['output_config']['format']['type'] === 'json_schema'
                && ! isset($req['thinking']) && ! isset($req['temperature']) && ! isset($req['tool_choice'])
                && $req['max_tokens'] === 6000 + 12000
                // A instrução comum do português de Portugal vem antes da da função.
                && str_starts_with($req['system'], 'Escreva sempre em português de Portugal') && str_contains($req['system'], 'Instrução da função.');
        });
        $this->assertSame(['anthropic', 'claude-opus-5-5', 'medium', 3000, 800, null], [$r->provider, $r->model, $r->effort, $r->inputTokens, $r->outputTokens, $r->reasoningTokens]);
        // 2000 a preço normal, 1000 em cache (0,20) e 800 de saída (20) por milhão.
        $this->assertEqualsWithDelta((2000 * 4 + 1000 * 0.2 + 800 * 20) / 1_000_000, $r->costUsd, 0.0000001);
        $this->assertSame(['ok' => 'Texto em português de Portugal.'], $r->json);
    }

    public function test_openai_request_uses_the_responses_api_with_reasoning_effort_and_structured_output(): void
    {
        AiFunctionSettings::set('caption', 'gpt-6.1-sol', 'high', $this->root);
        Http::fake(['api.openai.com/v1/responses' => $this->openAiResponse(['ok' => 'Olá.'], [
            'input_tokens' => 3000, 'input_tokens_details' => ['cached_tokens' => 1000], 'output_tokens' => 900, 'output_tokens_details' => ['reasoning_tokens' => 600],
        ])]);

        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'string']]];
        $r = app(AiGateway::class)->generate('caption', new AiPrompt('Função.', 'Pedido.', [['media_type' => 'image/webp', 'data' => base64_encode('img')]], $schema, 'legendas'));

        Http::assertSent(function (Request $req) use ($schema) {
            $content = $req['input'][0]['content'];

            return $req->hasHeader('Authorization', 'Bearer test-openai') && $req['model'] === 'gpt-6.1-sol'
                && $req['reasoning'] === ['effort' => 'high'] && $req['store'] === false && $req['max_output_tokens'] === 3000 + 32000
                && $req['text']['format'] === ['type' => 'json_schema', 'name' => 'legendas', 'schema' => $schema, 'strict' => true]
                && str_starts_with($req['instructions'], 'Escreva sempre em português de Portugal')
                && $content[0] === ['type' => 'input_image', 'image_url' => 'data:image/webp;base64,' . base64_encode('img')]
                && $content[1] === ['type' => 'input_text', 'text' => 'Pedido.'] && ! isset($req['temperature']);
        });
        $this->assertSame(['openai', 'gpt-6.1-sol', 'high', 3000, 900, 600], [$r->provider, $r->model, $r->effort, $r->inputTokens, $r->outputTokens, $r->reasoningTokens]);
        $this->assertEqualsWithDelta((2000 * 2 + 1000 * 0.1 + 900 * 10) / 1_000_000, $r->costUsd, 0.0000001);
    }

    public function test_openai_without_schema_asks_for_a_json_object_and_anthropic_rejects_incomplete_answers(): void
    {
        AiFunctionSettings::set('blog', 'gpt-6.1-sol', 'low', $this->root);
        Http::fake([
            'api.openai.com/v1/responses' => $this->openAiResponse(['title' => 'x']),
            'api.anthropic.com/*' => $this->anthropicResponse('{"a":', ['input_tokens' => 10, 'output_tokens' => 10], 'max_tokens'),
        ]);
        app(AiGateway::class)->generate('blog', new AiPrompt('S', 'T'));
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'openai') && $req['text']['format'] === ['type' => 'json_object']);

        $this->expectExceptionMessage('incompleta');
        app(AiGateway::class)->generate('ideas', new AiPrompt('S', 'T'));
    }

    // ── Português de Portugal ─────────────────────────────────────────────────

    public function test_checker_asks_again_once_and_the_clean_answer_wins(): void
    {
        Http::fakeSequence('api.anthropic.com/*')
            ->pushResponse($this->anthropicResponse(['text' => 'Você vai adorar a nossa equipe — estamos fazendo tudo por si.'], ['input_tokens' => 100, 'output_tokens' => 50]))
            ->pushResponse($this->anthropicResponse(['text' => 'A sua marca vai gostar: a nossa equipa está a preparar tudo.'], ['input_tokens' => 200, 'output_tokens' => 60]));

        $r = app(AiGateway::class)->generate('creative', new AiPrompt('S', 'T'));

        Http::assertSentCount(2);
        $second = Http::recorded()[1][0];
        $this->assertStringContainsString('Resposta anterior, a corrigir', $second['messages'][0]['content'][0]['text']);
        $this->assertStringContainsString('você', $second['messages'][0]['content'][0]['text']);
        $this->assertSame([[], true, 300, 110], [$r->ptIssues, $r->ptRetried, $r->inputTokens, $r->outputTokens]);
        $this->assertSame('A sua marca vai gostar: a nossa equipa está a preparar tudo.', $r->json['text']);
    }

    public function test_marks_that_persist_after_the_second_try_show_review_the_portuguese(): void
    {
        $post = $this->makePost();
        $bad = $this->captionAnswer('Você vai adorar! Mande um contato pelo celular.');
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse($bad)]);

        $id = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions"))->assertStatus(202)->json('data.id');
        $data = $this->as($this->userA)->getJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions/{$id}"))->assertOk()->json('data');

        Http::assertSentCount(2); // pede de novo UMA vez
        $this->assertTrue($data['pt_review']);
        $this->assertSame(['você', 'celular', 'contato'], array_values(array_intersect(['você', 'celular', 'contato'], $data['pt_issues'])));
        $row = AiRequest::find($id);
        $this->assertTrue($row->pt_retried);
        $this->assertNotEmpty($row->pt_issues);
    }

    // ── Gerar legenda (com a imagem) ──────────────────────────────────────────

    public function test_generate_caption_sends_the_images_and_one_variation_per_network_and_never_saves(): void
    {
        $post = $this->makePost();
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$post->id}/content"), ['caption' => 'Rascunho atual.'])->assertOk();
        $asset = $this->imageAsset('IMAGEM-DAS-CASTANHAS');
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$post->id}/media"), ['items' => [$asset->id]])->assertOk();
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse($this->captionAnswer())]);

        $res = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions"))->assertStatus(202);
        $this->assertSame('done', $res->json('data.status'));
        $this->assertSame(1, $res->json('data.images_sent'));

        Http::assertSent(function (Request $req) {
            $content = $req['messages'][0]['content'];
            $schema = $req['output_config']['format']['schema'];

            return $req['output_config']['effort'] === 'low'
                // O modelo vê a imagem (bloco base64 antes do texto).
                && $content[0] === ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/webp', 'data' => base64_encode('IMAGEM-DAS-CASTANHAS')]]
                && $content[1]['type'] === 'text' && str_contains($content[1]['text'], 'IMAGENS: 1 em anexo')
                // Tema, Perfil da Marca (tom, hashtags, chamada à ação, emojis) e as redes escolhidas.
                && str_contains($content[1]['text'], 'Menu de São Martinho com castanhas') && str_contains($content[1]['text'], '#tascadolargo')
                && str_contains($content[1]['text'], 'Reserve a sua mesa.') && str_contains($content[1]['text'], '"emoji_policy":"light"')
                && str_contains($content[1]['text'], 'Instagram (') && str_contains($content[1]['text'], 'Facebook (')
                && $schema['properties']['proposals']['items']['properties']['captions']['required'] === ['instagram', 'facebook'];
        });

        $result = $res->json('data.result');
        $this->assertSame(['instagram', 'facebook'], $result['networks']);
        $this->assertCount(2, $result['proposals']);
        $this->assertSame(['#saomartinho', '#tascadolargo'], $result['proposals'][0]['hashtags']);
        $this->assertSame('O São Martinho está à porta: castanhas e jeropiga.', $result['proposals'][0]['captions']['facebook']);

        // Nunca grava sozinho: a versão continua com o rascunho da pessoa.
        $this->assertSame('Rascunho atual.', DB::table('editorial_post_versions')->where('id', $post->fresh()->current_version_id)->value('caption'));
        $row = AiRequest::find($res->json('data.id'));
        $this->assertSame(['caption', 'anthropic', 'claude-opus-5-5', 'low'], [$row->mode, $row->provider, $row->model, $row->effort]);
        $this->assertGreaterThan(0, $row->cost_usd);
    }

    public function test_generate_caption_with_only_the_chosen_networks_tenancy_and_monthly_cap(): void
    {
        $post = $this->makePost();
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse(['proposals' => [['angle' => 'x', 'captions' => ['facebook' => 'Só no Facebook.'], 'hashtags' => [], 'cta' => '']]])]);

        $res = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions"), ['networks' => ['facebook']])->assertStatus(202);
        $this->assertSame(['facebook'], $res->json('data.result.networks'));
        $this->assertSame(['facebook' => 'Só no Facebook.'], $res->json('data.result.proposals.0.captions'));
        $this->assertSame([1, 60], [$res->json('data.used'), $res->json('data.cap')]);

        $this->as($this->userA)->postJson($this->url($this->b, "/editorial/posts/{$post->id}/caption-suggestions"))->assertStatus(403);
        $site = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-10-21', 'title' => 'Artigo', 'format' => 'Artigo', 'channel' => 'site']);
        $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$site->id}/caption-suggestions"))->assertStatus(422);
        $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions"), ['networks' => ['tiktok']])->assertStatus(422);

        config(['services.openai.ai_monthly_caps.caption' => 1]);
        $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions"))->assertStatus(429);
    }

    // ── Limites ───────────────────────────────────────────────────────────────

    public function test_monthly_limits_per_brand_and_file_quota(): void
    {
        $caps = (array) config('services.openai.ai_monthly_caps');
        $this->assertSame(['creative' => 40, 'blog' => 6, 'ideas' => 3, 'brand_profile' => 3, 'caption' => 60],
            ['creative' => (int) $caps['creative'], 'blog' => (int) $caps['blog'], 'ideas' => (int) $caps['ideas'], 'brand_profile' => (int) $caps['brand_profile'], 'caption' => (int) $caps['caption']]);
        $this->assertSame(3072, (int) config('media.company_quota_mb'));
    }

    public function test_provider_errors_with_an_answer_count_for_the_limit_and_network_failures_do_not(): void
    {
        $post = $this->makePost();
        Http::fakeSequence('api.anthropic.com/*')
            ->pushFailedConnection()->pushFailedConnection()->pushFailedConnection()
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);

        $first = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions"))->assertStatus(202);
        $this->assertSame(['error', 0], [$first->json('data.status'), $first->json('data.used')]);
        $second = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/caption-suggestions"))->assertStatus(202);
        $this->assertSame(['error', 1], [$second->json('data.status'), $second->json('data.used')]);
        $this->assertSame(529, AiRequest::find($second->json('data.id'))->provider_status);
        $this->assertStringContainsString('não respondeu a tempo', $second->json('data.error_message'));
    }

    // ── Modelo por função: só o root ─────────────────────────────────────────

    public function test_only_root_switches_the_model_per_function_and_applies_to_all_with_history(): void
    {
        foreach ([$this->userA, $this->adminA] as $u) {
            $this->as($u)->getJson('/api/v1/admin/ai-models')->assertStatus(403);
            $this->as($u)->putJson('/api/v1/admin/ai-models/caption', ['model' => 'gpt-6.1-sol', 'effort' => 'low'])->assertStatus(403);
            $this->as($u)->postJson('/api/v1/admin/ai-models/apply-all', ['model' => 'gpt-6.1-sol', 'effort' => 'low'])->assertStatus(403);
        }

        $data = $this->as($this->root)->getJson('/api/v1/admin/ai-models')->assertOk()->json('data');
        // Valores iniciais: claude-opus-5-5, esforço baixo nas funções curtas e médio nas ideias, no blog e na análise. Sem o OCR.
        $this->assertSame([
            'caption' => 'low', 'creative' => 'low', 'ideas' => 'medium', 'blog' => 'medium', 'brand_profile' => 'low', 'car_description' => 'low', 'car_analysis' => 'medium', 'family_categories' => 'low', 'bussola_jogadas' => 'low',
        ], collect($data['functions'])->pluck('effort', 'key')->all());
        $this->assertSame(['claude-opus-5-5'], collect($data['functions'])->pluck('model')->unique()->values()->all());
        $this->assertSame(['anthropic' => true, 'openai' => true], collect($data['providers'])->pluck('configured', 'key')->all());

        $this->as($this->root)->putJson('/api/v1/admin/ai-models/blog', ['model' => 'gpt-6.1-sol', 'effort' => 'high'])->assertOk()
            ->assertJsonPath('data.functions.3.model', 'gpt-6.1-sol')->assertJsonPath('data.functions.3.provider', 'openai');
        $this->as($this->root)->putJson('/api/v1/admin/ai-models/blog', ['model' => 'gpt-6.1-sol', 'effort' => 'none'])->assertStatus(422);
        $this->as($this->root)->putJson('/api/v1/admin/ai-models/blog', ['model' => 'gpt-4o', 'effort' => 'low'])->assertStatus(422);
        $this->as($this->root)->putJson('/api/v1/admin/ai-models/ocr', ['model' => 'gpt-6.1-sol', 'effort' => 'low'])->assertStatus(422);

        $all = $this->as($this->root)->postJson('/api/v1/admin/ai-models/apply-all', ['model' => 'claude-sonnet-5-5', 'effort' => 'medium'])->assertOk()->json('data');
        $this->assertSame(['claude-sonnet-5-5'], collect($all['functions'])->pluck('model')->unique()->values()->all());
        $this->assertSame(1 + 9, DB::table('ai_function_setting_changes')->count());
        $this->assertSame(9, DB::table('ai_function_setting_changes')->where('applied_to_all', true)->where('user_id', $this->root->id)->count());
        $this->assertSame(['function' => 'blog', 'from' => 'claude-opus-5-5 (medium)', 'to' => 'gpt-6.1-sol (high)'],
            array_intersect_key(collect($all['history'])->last(), array_flip(['function', 'from', 'to'])));

        // A troca vale para o pedido seguinte da função.
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse(['ok' => 'x'])]);
        app(AiGateway::class)->generate('ideas', new AiPrompt('S', 'T'));
        Http::assertSent(fn (Request $req) => $req['model'] === 'claude-sonnet-5-5' && $req['output_config']['effort'] === 'medium');
    }

    public function test_ocr_is_untouched_by_the_model_switch(): void
    {
        $this->as($this->root)->postJson('/api/v1/admin/ai-models/apply-all', ['model' => 'gpt-6.1-sol', 'effort' => 'high'])->assertOk();
        $this->assertNotContains('ocr', array_keys((array) config('ai.functions')));

        Storage::fake('local');
        // F2a: o modelo do OCR vem do .env (OCR_MODEL_TEXT/IMAGE), não do interruptor global.
        config(['services.openai.key' => 'test-ocr', 'services.openai.ocr.model_image' => 'gpt-4o-mini']);
        // Leitura local (scraper) fora do teste: fotografia sem QR → caminho "sem QR".
        $this->app->instance(InvoiceOcrService::class, new class extends InvoiceOcrService {
            protected function analyzeFile(string $bytes, string $mime, string $images): array
            {
                return ['ok' => true, 'kind' => 'image', 'pages' => 1, 'qr' => null, 'text' => '', 'text_chars' => 0, 'images' => []];
            }
        });
        app(CompanyModuleService::class)->applyPreset($this->a->id, 'restaurant');
        $invoice = OcrInvoice::create(['company_id' => $this->a->id, 'image_path' => "ocr-invoices/{$this->a->id}/x.jpg", 'image_mime' => 'image/jpeg', 'status' => 'processing']);
        Storage::disk('local')->put($invoice->image_path, 'fake-image-bytes');
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'fornecedor' => ['nome' => 'Recheio', 'nif' => '500829993'], 'numeroFatura' => 'FT 1', 'dataEmissao' => '2026-10-01', 'linhas' => [], 'sumario' => ['total' => 10],
        ])]]], 'usage' => []])]);

        ProcessInvoiceOcrJob::dispatchSync($this->a->id, $invoice->id);

        // Continua igual: chat/completions com o gpt-4o-mini e a chave da OpenAI; nunca a Anthropic nem a Responses API.
        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.openai.com/v1/chat/completions' && $req['model'] === 'gpt-4o-mini' && $req->hasHeader('Authorization', 'Bearer test-ocr'));
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'anthropic') || str_contains($req->url(), '/v1/responses'));
        $this->assertSame('gpt-4o-mini', $invoice->fresh()->model);
        $this->assertSame(0, AiRequest::count());
    }

    // ── Descrição de viaturas (síncrona) ──────────────────────────────────────

    public function test_car_description_goes_through_the_gateway_and_is_logged_with_cost(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse('Peugeot 208 de 2021 bem conservado, com câmara de marcha-atrás.', ['input_tokens' => 900, 'output_tokens' => 120])]);

        $r = app(CarDescriptionService::class)->generate(['vehicle_type' => 'car', 'car_brand_id' => 999, 'car_model_id' => 999, 'registration_year' => 2021], $this->a->id, $this->userA->id);

        $this->assertSame('Peugeot 208 de 2021 bem conservado, com câmara de marcha-atrás.', $r->text);
        Http::assertSent(fn (Request $req) => $req['output_config']['effort'] === 'low' && ! isset($req['output_config']['format']));
        $row = AiRequest::where('mode', 'car_description')->sole();
        $this->assertSame(['done', $this->a->id, 'anthropic', 900, 120], [$row->status, $row->company_id, $row->provider, $row->input_tokens, $row->output_tokens]);
        $this->assertEqualsWithDelta((900 * 4 + 120 * 20) / 1_000_000, $row->cost_usd, 0.000001);
    }

    // ── Teste às cegas ────────────────────────────────────────────────────────

    public function test_blind_test_is_root_only_hides_the_models_and_reports_per_function(): void
    {
        $this->as($this->adminA)->postJson('/api/v1/admin/ai-blind-tests', ['function' => 'creative'])->assertStatus(403);
        $answer = ['media_format' => 'ig_reel', 'hook' => 'h', 'caption' => 'Legenda em português de Portugal.', 'hashtags' => [], 'cta' => 'x', 'why' => 'y'];
        Http::fake([
            'api.anthropic.com/*' => $this->anthropicResponse($answer, ['input_tokens' => 1000, 'output_tokens' => 500]),
            'api.openai.com/v1/responses' => $this->openAiResponse(['caption' => 'Você vai gostar — sempre.'] + $answer),
        ]);

        $test = $this->as($this->root)->postJson('/api/v1/admin/ai-blind-tests', ['function' => 'creative'])->assertStatus(201)->json('data');
        $this->assertCount(10, $test['cases']);
        $show = $this->as($this->root)->getJson("/api/v1/admin/ai-blind-tests/{$test['id']}")->assertOk();
        $this->assertSame('ready', $show->json('data.status'));
        $this->assertStringNotContainsString('claude', $show->getContent());
        $this->assertStringNotContainsString('gpt', $show->getContent());
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'anthropic') && $req['model'] === 'claude-opus-5-5' && $req['output_config']['effort'] === 'low');
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'openai') && $req['model'] === 'gpt-6.1-sol' && $req['reasoning']['effort'] === 'low');

        // Escolhe sempre a resposta da Anthropic (o lado varia), e um empate no último caso.
        $list = $this->as($this->root)->getJson('/api/v1/admin/ai-blind-tests')->json('data');
        $this->assertNull($list['tests'][0]['models']);
        $this->assertSame([], $list['report']);
        foreach ($show->json('data.cases') as $i => $case) {
            $anthropicSide = $case['left']['pt_issues'] === [] ? 'left' : 'right';
            $this->as($this->root)->postJson("/api/v1/admin/ai-blind-tests/{$test['id']}/cases/{$case['id']}/choice", ['choice' => $i === 9 ? 'tie' : $anthropicSide])->assertOk();
        }
        $this->as($this->root)->postJson("/api/v1/admin/ai-blind-tests/{$test['id']}/cases/{$case['id']}/choice", ['choice' => 'up'])->assertStatus(422);

        $list = $this->as($this->root)->getJson('/api/v1/admin/ai-blind-tests')->json('data');
        $this->assertSame(['claude-opus-5-5', 'gpt-6.1-sol'], $list['tests'][0]['models']);
        $report = $list['report'][0];
        $this->assertSame(['creative', 10, 1], [$report['function'], $report['cases'], $report['ties']]);
        $this->assertSame([9, 0, 0], [$report['a']['wins'], $report['a']['pt_failures'], $report['a']['errors']]);
        // A resposta da OpenAI tinha "você" e um travessão nas duas tentativas: falha no verificador.
        $this->assertSame([0, 10], [$report['b']['wins'], $report['b']['pt_failures']]);
        $this->assertEqualsWithDelta((1000 * 4 + 500 * 20) / 1_000_000, $report['a']['avg_cost_usd'], 0.000001);
        $this->assertSame($this->root->id, DB::table('ai_blind_cases')->value('chosen_by_user_id'));
    }
}
