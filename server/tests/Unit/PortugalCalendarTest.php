<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Services\Calendar\PortugalCalendar;
use App\Services\EditorialAnchorResolver;
use App\Services\Restaurant\Forecast\PortugalHolidays;
use App\Services\Restaurant\RestaurantSpecialDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bússola 5: os feriados de Portugal têm uma só fonte (PortugalCalendar). A previsão da restauração,
 * os dias especiais dos sinais e a Páscoa da Linha Editorial leem-na; as âncoras semeadas na Linha
 * Editorial (dados) têm de dizer o mesmo.
 */
class PortugalCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_national_holidays_and_easter(): void
    {
        $this->assertSame('2026-04-05', PortugalCalendar::easter(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', PortugalCalendar::easter(2027)->format('Y-m-d'));
        $h = PortugalCalendar::national(2026);
        $this->assertCount(13, $h);
        $this->assertSame('Sexta-feira Santa', $h['2026-04-03']);
        $this->assertSame('Corpo de Deus', $h['2026-06-04']);
        $this->assertSame('Todos os Santos', $h['2026-11-01']);
        $this->assertSame(PortugalCalendar::SOURCE_CT, PortugalCalendar::source()['source']);
        // Confirmados no Código do Trabalho, artigo 234.º, a 23/10/2026 (o Carnaval não é obrigatório).
        $this->assertSame(['confirmada', '2026-10-23'], [PortugalCalendar::source()['status'], PortugalCalendar::source()['confirmed_on']]);
        $this->assertNotContains('Carnaval', PortugalCalendar::national(2026));
    }

    public function test_the_municipal_holiday_and_between(): void
    {
        $this->assertSame('Feriado municipal', PortugalCalendar::forYear(2026, '24/06')['2026-06-24']);
        $this->assertSame('Natal', PortugalCalendar::forYear(2026, '25/12')['2026-12-25'], 'um nacional não perde o nome');
        $this->assertCount(13, PortugalCalendar::forYear(2026, '31/02'), 'uma data que não existe não entra');
        $this->assertSame(['2026-12-25' => 'Natal', '2027-01-01' => 'Ano Novo'], PortugalCalendar::between('2026-12-20', '2027-01-05'));
    }

    public function test_the_three_old_places_now_read_the_single_source(): void
    {
        foreach ([2025, 2026, 2027, 2030] as $y) {
            $this->assertSame(PortugalCalendar::forYear($y, '13/06'), PortugalHolidays::forYear($y, '13/06'));
            $this->assertSame(PortugalCalendar::national($y), app(RestaurantSpecialDays::class)->nationalHolidays($y));
            $this->assertSame(PortugalCalendar::easter($y)->format('Y-m-d'), app(EditorialAnchorResolver::class)->easter($y)->toDateString());
        }
    }

    public function test_the_seeded_editorial_holiday_anchors_match_the_single_source(): void
    {
        $universal = ContentSector::where('slug', 'universal')->value('id');
        $anchors = ContentAnchor::where('sector_id', $universal)->where('origin', 'fiavel')->get()->keyBy('title');
        foreach (PortugalCalendar::FIXED as $md => $name) {
            $this->assertTrue($anchors->has($name), "falta a âncora {$name}");
            $this->assertSame(['fixa', (int) substr($md, 0, 2), (int) substr($md, 3, 2)], [$anchors[$name]->rule_type, (int) $anchors[$name]->month, (int) $anchors[$name]->day]);
        }
        foreach (PortugalCalendar::EASTER as $offset => $name) {
            $this->assertSame(['relativa_pascoa', $offset], [$anchors[$name]->rule_type, (int) $anchors[$name]->easter_offset]);
        }
    }
}
