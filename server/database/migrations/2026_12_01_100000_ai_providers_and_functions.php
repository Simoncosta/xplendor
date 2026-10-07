<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IA com dois fornecedores (OpenAI e Anthropic), modelo por função escolhido pelo root:
 *  · ai_requests: fornecedor, esforço de raciocínio, tokens de entrada, saída e raciocínio,
 *    custo calculado (USD, com os preços de config/ai.php), estado HTTP do fornecedor nos erros
 *    (os erros com resposta do fornecedor contam no limite) e o resultado do verificador do
 *    português de Portugal.
 *  · ai_function_settings: fornecedor, modelo e esforço de cada função (o OCR fica de fora).
 *  · ai_function_setting_changes: o histórico das alterações.
 *  · ai_blind_tests / ai_blind_cases: o teste às cegas entre dois modelos (só o root).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->string('provider', 20)->nullable();
            $table->string('effort', 10)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->json('pt_issues')->nullable();
            $table->boolean('pt_retried')->default(false);
            $table->foreignId('car_id')->nullable()->constrained('cars')->nullOnDelete();
        });

        Schema::create('ai_function_settings', function (Blueprint $table) {
            $table->id();
            $table->string('function', 30)->unique();
            $table->string('provider', 20);
            $table->string('model', 60);
            $table->string('effort', 10);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_function_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('function', 30);
            $table->string('from_provider', 20)->nullable();
            $table->string('from_model', 60)->nullable();
            $table->string('from_effort', 10)->nullable();
            $table->string('to_provider', 20);
            $table->string('to_model', 60);
            $table->string('to_effort', 10);
            $table->boolean('applied_to_all')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['function', 'created_at']);
        });

        Schema::create('ai_blind_tests', function (Blueprint $table) {
            $table->id();
            $table->string('function', 30);
            $table->string('model_a', 60);
            $table->string('model_b', 60);
            $table->string('status', 12)->default('generating'); // generating | ready
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_blind_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_blind_test_id')->constrained('ai_blind_tests')->cascadeOnDelete();
            $table->unsignedTinyInteger('case_index');
            $table->json('input');
            $table->boolean('a_on_left');
            foreach (['a', 'b'] as $side) {
                $table->longText("{$side}_text")->nullable();
                $table->json("{$side}_pt_issues")->nullable();
                $table->decimal("{$side}_cost_usd", 12, 6)->nullable();
                $table->unsignedInteger("{$side}_ms")->nullable();
                $table->string("{$side}_error", 500)->nullable();
            }
            $table->string('choice', 5)->nullable(); // a | b | tie
            $table->foreignId('chosen_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('chosen_at')->nullable();
            $table->timestamps();
            $table->unique(['ai_blind_test_id', 'case_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_blind_cases');
        Schema::dropIfExists('ai_blind_tests');
        Schema::dropIfExists('ai_function_setting_changes');
        Schema::dropIfExists('ai_function_settings');
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('car_id');
            $table->dropColumn(['provider', 'effort', 'input_tokens', 'output_tokens', 'reasoning_tokens', 'cost_usd', 'provider_status', 'pt_issues', 'pt_retried']);
        });
    }
};
