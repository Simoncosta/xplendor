<?php

use App\Models\ContentAnchor;
use App\Models\ContentSector;
use Illuminate\Database\Migrations\Migration;

/**
 * Bússola 6: âncoras do ramo imobiliário com os prazos do IMI e do IRS (datas das Notas do Roteiro,
 * vistas a 23/10/2026 em fontes públicas; fonte oficial a citar: Portal das Finanças). Quando o
 * último dia calha num fim de semana ou feriado, o prazo passa para o dia útil seguinte
 * (next_business_day). O BCE não é âncora: as datas estão no OfficialDates e aparecem na grelha como
 * datas oficiais. content_anchors só recebe linhas; idempotente por (sector_id, title).
 */
return new class extends Migration
{
    private const NOTE = 'Fonte oficial: Portal das Finanças (Autoridade Tributária). Data das Notas do Roteiro, vista a 23/10/2026; por confirmar na fonte oficial. Num fim de semana ou feriado, passa para o dia útil seguinte.';

    /** @return list<array{0: string, 1: array<string, int|string>, 2: string}> título, regra, gancho */
    private function data(): array
    {
        return [
            ['IMI: pagamento único ou 1.ª prestação', ['rule_type' => 'fixa', 'month' => 5, 'day' => 31], 'lembrar o prazo do IMI; quem paga de uma vez (até 100 euros) e a 1.ª prestação'],
            ['IMI: 2.ª prestação', ['rule_type' => 'fixa', 'month' => 8, 'day' => 31], 'só para valores acima de 500 euros'],
            ['IMI: última prestação', ['rule_type' => 'fixa', 'month' => 11, 'day' => 30], 'para valores acima de 100 euros'],
            ['IRS: entrega da declaração', ['rule_type' => 'periodo', 'start_month' => 4, 'start_day' => 1, 'end_month' => 6, 'end_day' => 30], 'o que muda com a compra ou a venda de casa na declaração'],
            ['IRS: pagamento ou reembolso', ['rule_type' => 'fixa', 'month' => 8, 'day' => 31], 'prazo do pagamento do imposto e dos reembolsos'],
        ];
    }

    public function up(): void
    {
        $sector = ContentSector::where('slug', 'imobiliario')->first();
        if (! $sector) {
            return; // ramo ainda não existe → ignora
        }
        foreach ($this->data() as [$title, $rule, $suggestion]) {
            ContentAnchor::updateOrCreate(
                ['sector_id' => $sector->id, 'title' => $title],
                array_merge(['country' => 'PT', 'origin' => 'fiavel', 'next_business_day' => true, 'notes' => self::NOTE, 'suggestion' => $suggestion], $rule),
            );
        }
    }

    public function down(): void
    {
        $sector = ContentSector::where('slug', 'imobiliario')->first();
        if ($sector) {
            ContentAnchor::where('sector_id', $sector->id)->whereIn('title', array_map(static fn ($r) => $r[0], $this->data()))->delete();
        }
    }
};
