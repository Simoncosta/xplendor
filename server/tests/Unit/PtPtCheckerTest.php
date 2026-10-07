<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\PtPtChecker;
use PHPUnit\Framework\TestCase;

/** O verificador apanha as marcas do português do Brasil e os travessões, e não acusa o português de Portugal. */
class PtPtCheckerTest extends TestCase
{
    public static function brazilian(): array
    {
        return [
            ['Você vai adorar este produto.', 'você'],
            ['Para os usuários mais exigentes.', 'usuário'],
            ['Peça já pelo celular.', 'celular'],
            ['A nossa equipe está pronta.', 'equipe'],
            ['Entre em contato connosco.', 'contato'],
            ['Faça o registro no site.', 'registro'],
            ['Veja na tela do computador.', 'tela'],
            ['Envie o arquivo em PDF.', 'arquivo'],
            ['Apanhe o ônibus para o centro.', 'ônibus'],
            ['A gente adora o outono.', 'a gente'],
            ['Estou fazendo uma promoção.', 'gerúndio ("estou fazendo")'],
            ['Os preços estão subindo.', 'gerúndio ("estou fazendo")'],
            ['Novidades — só esta semana.', 'travessão'],
            ['Outono – a estação do conforto.', 'travessão'],
        ];
    }

    /** @dataProvider brazilian */
    public function test_brazilian_marks_and_dashes_are_caught(string $text, string $mark): void
    {
        $this->assertContains($mark, PtPtChecker::issues($text));
    }

    public function test_european_portuguese_passes(): void
    {
        $text = 'A sua marca merece destaque: venha conhecer a nova coleção. Estamos a preparar novidades para o seu telemóvel. '
            . 'Entre em contacto com a nossa equipa e faça o registo no ficheiro em anexo. Estou a fazer uma promoção especial.';
        $this->assertSame([], PtPtChecker::issues($text));
    }

    public function test_it_checks_every_string_of_a_json_answer(): void
    {
        $answer = ['proposals' => [['caption' => 'Olá a todos.', 'hashtags' => ['#outono']], ['caption' => 'Você vai gostar — garantido.']]];
        $this->assertEqualsCanonicalizing(['você', 'travessão'], PtPtChecker::issuesIn($answer));
    }
}
