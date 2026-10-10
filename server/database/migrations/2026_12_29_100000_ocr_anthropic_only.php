<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OCR, ponto 5: só a Anthropic. Uma escolha gravada nas funções do OCR (ocr_text e ocr_image)
 * passa a claude-opus-5-5 com esforço médio, com uma linha no histórico de Modelos de IA (sem
 * autor: foi a migração). Sem escolha gravada não se insere nada: vale a reserva do .env, que
 * por omissão já é claude-opus-5-5 com esforço médio. Não apaga nada.
 */
return new class extends Migration
{
    private const TO = ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'medium'];

    public function up(): void
    {
        foreach (['ocr_text', 'ocr_image'] as $function) {
            $row = DB::table('ai_function_settings')->where('function', $function)->first();
            if (! $row || ($row->provider === self::TO['provider'] && $row->model === self::TO['model'] && $row->effort === self::TO['effort'])) {
                continue;
            }
            DB::table('ai_function_settings')->where('id', $row->id)->update(self::TO + ['updated_by_user_id' => null, 'updated_at' => now()]);
            DB::table('ai_function_setting_changes')->insert([
                'function' => $function, 'from_provider' => $row->provider, 'from_model' => $row->model, 'from_effort' => $row->effort,
                'to_provider' => self::TO['provider'], 'to_model' => self::TO['model'], 'to_effort' => self::TO['effort'],
                'applied_to_all' => false, 'user_id' => null, 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sem volta automática: a escolha anterior está no histórico e muda-se em Modelos de IA.
    }
};
