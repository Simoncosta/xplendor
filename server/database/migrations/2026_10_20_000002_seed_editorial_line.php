<?php

use App\Models\ContentAnchor;
use App\Models\ContentSector;
use Illuminate\Database\Migrations\Migration;

/**
 * XPLENDOR — Linha Editorial (Fatia 1): SEED da árvore de setores + âncoras FIÁVEIS
 * (feriados/efemérides nacionais PT, no nó Universal). Data-migration IDEMPOTENTE
 * (updateOrCreate por chave natural) — corre no `migrate` e é seguro repetir. As
 * âncoras VARIÁVEIS (por ramo) NÃO se semeiam — criam-se no painel de curadoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Árvore de setores (só folhas selecionáveis) ──
        $universal = ContentSector::updateOrCreate(['slug' => 'universal'],
            ['name' => 'Universal', 'parent_id' => null, 'type' => 'universal', 'is_selectable' => false, 'sort' => 0]);

        $restauracao = ContentSector::updateOrCreate(['slug' => 'restauracao'],
            ['name' => 'Restauração', 'parent_id' => $universal->id, 'type' => 'folha', 'is_selectable' => true, 'sort' => 1]);

        $automovel = ContentSector::updateOrCreate(['slug' => 'automovel'],
            ['name' => 'Automóvel', 'parent_id' => $universal->id, 'type' => 'agrupador', 'is_selectable' => false, 'sort' => 2]);

        ContentSector::updateOrCreate(['slug' => 'carros'],
            ['name' => 'Carros', 'parent_id' => $automovel->id, 'type' => 'folha', 'is_selectable' => true, 'sort' => 1]);

        ContentSector::updateOrCreate(['slug' => 'autocaravanas'],
            ['name' => 'Autocaravanas', 'parent_id' => $automovel->id, 'type' => 'folha', 'is_selectable' => true, 'sort' => 2]);

        ContentSector::updateOrCreate(['slug' => 'domotica'],
            ['name' => 'Domótica', 'parent_id' => $universal->id, 'type' => 'folha', 'is_selectable' => true, 'sort' => 3]);

        // ── Âncoras FIÁVEIS (origem=fiavel, país PT, nó Universal) ──
        $seed = function (string $title, array $rule) use ($universal) {
            ContentAnchor::updateOrCreate(
                ['sector_id' => $universal->id, 'title' => $title],
                array_merge(['country' => 'PT', 'origin' => 'fiavel'], $rule)
            );
        };

        // FIXAS (feriados nacionais de data fixa)
        $fixas = [
            ['Ano Novo', 1, 1], ['Dia da Liberdade', 4, 25], ['Dia do Trabalhador', 5, 1],
            ['Dia de Portugal', 6, 10], ['Assunção de Nossa Senhora', 8, 15], ['Implantação da República', 10, 5],
            ['Todos os Santos', 11, 1], ['Restauração da Independência', 12, 1], ['Imaculada Conceição', 12, 8],
            ['Natal', 12, 25],
        ];
        foreach ($fixas as [$t, $mo, $da]) {
            $seed($t, ['rule_type' => 'fixa', 'month' => $mo, 'day' => $da]);
        }

        // RELATIVA_PASCOA (móveis) — offset em dias face ao Domingo de Páscoa.
        $seed('Carnaval', ['rule_type' => 'relativa_pascoa', 'easter_offset' => -47]);
        $seed('Sexta-feira Santa', ['rule_type' => 'relativa_pascoa', 'easter_offset' => -2]);
        $seed('Páscoa', ['rule_type' => 'relativa_pascoa', 'easter_offset' => 0]);
        $seed('Corpo de Deus', ['rule_type' => 'relativa_pascoa', 'easter_offset' => 60]);

        // NTH_WEEKDAY — ordinal (1..5 ou -1), weekday 0=dom..6=sáb.
        $seed('Dia da Mãe', ['rule_type' => 'nth_weekday', 'month' => 5, 'ordinal' => 1, 'weekday' => 0]);
        $seed('Início do horário de verão', ['rule_type' => 'nth_weekday', 'month' => 3, 'ordinal' => -1, 'weekday' => 0]);
        $seed('Fim do horário de verão', ['rule_type' => 'nth_weekday', 'month' => 10, 'ordinal' => -1, 'weekday' => 0]);

        // PERIODO — intervalo início→fim.
        $seed('Santos Populares', ['rule_type' => 'periodo', 'start_month' => 6, 'start_day' => 1, 'end_month' => 6, 'end_day' => 30]);
    }

    public function down(): void
    {
        // Remove só o que este seed introduz (âncoras fiáveis + a árvore semeada).
        ContentAnchor::where('origin', 'fiavel')->delete();
        ContentSector::whereIn('slug', ['carros', 'autocaravanas', 'automovel', 'restauracao', 'domotica', 'universal'])->delete();
    }
};
