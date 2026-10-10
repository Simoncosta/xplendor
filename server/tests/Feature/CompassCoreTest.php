<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Compass\CompassPlayChooser;
use App\Services\Compass\CompassTexts;
use App\Services\Compass\CompassWhen;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Bússola comum (parte 2): o núcleo partilhado pelos ramos. No máximo uma jogada por tipo e 3
 * no total, pela força; o "Quando" nunca é uma data passada; a frase da IA é curta, sem números
 * e sem causas; o pedido leva as regras comuns e a regra própria do ramo.
 */
class CompassCoreTest extends TestCase
{
    public function test_one_play_per_type_three_at_most_strongest_first(): void
    {
        $groups = [
            'a' => [['key' => 'a1', 's' => 1], ['key' => 'a2', 's' => 5]],
            'b' => [['key' => 'b1', 's' => 3]],
            'c' => [['key' => 'c1', 's' => 4]],
            'd' => [['key' => 'd1', 's' => 2]],
        ];
        $chosen = CompassPlayChooser::choose($groups, fn (array $g) => [$g['s']]);

        $this->assertSame(['a2', 'c1', 'b1'], array_column($chosen, 'key'));
    }

    public function test_when_is_never_in_the_past(): void
    {
        $today = CarbonImmutable::parse('2026-10-08'); // quinta-feira
        $this->assertSame(['2026-10-08', true, 'Publicar hoje'], array_values(array_slice(CompassWhen::present($today->subDays(3), $today), 0, 3)));
        $this->assertSame('Publicar na sexta-feira, 09/10', CompassWhen::present($today->addDay(), $today)['label']);
        $this->assertSame('Publicar no sábado, 10/10', CompassWhen::present($today->addDays(2), $today)['label']);
        $this->assertSame('Fazer hoje', CompassWhen::present($today, $today, null, 'Fazer')['label']);
    }

    public function test_an_acceptable_sentence_and_the_prompt_frame(): void
    {
        $this->assertTrue(CompassTexts::acceptable('Mostre o veículo nas redes sociais.'));
        $this->assertFalse(CompassTexts::acceptable('Publique o carro de 2019.'), 'sem números');
        $this->assertFalse(CompassTexts::acceptable('Publique porque está parado.'), 'sem causas');

        $prompt = CompassTexts::prompt('Stand Exemplo', [], 'Abertura do ramo.', 'Não invente equipamentos.', ['Chave: x']);
        $this->assertStringContainsString("Abertura do ramo.\nRegras:", $prompt->system);
        $this->assertStringContainsString('4. Não invente equipamentos.', $prompt->system);
        $this->assertStringContainsString('Por preencher: tom próximo e profissional.', $prompt->text);
    }
}
