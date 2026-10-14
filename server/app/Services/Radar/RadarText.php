<?php

declare(strict_types=1);

namespace App\Services\Radar;

use Illuminate\Support\Str;

/**
 * Radar: o texto dos títulos. A normalização (minúsculas, sem acentos, só letras e números)
 * serve ao filtro e às tendências, para "Política" e "politica" contarem como a mesma palavra.
 */
final class RadarText
{
    /** Palavras com 5 ou mais letras que não dizem o assunto (não juntam notícias numa tendência). */
    private const STOPWORDS = [
        'sobre', 'depois', 'antes', 'entre', 'contra', 'ainda', 'quando', 'porque', 'porqu', 'tambem', 'mesmo', 'mesma',
        'todos', 'todas', 'outro', 'outra', 'outros', 'outras', 'muito', 'muita', 'muitos', 'muitas', 'menos', 'desde',
        'durante', 'apenas', 'agora', 'assim', 'cerca', 'perto', 'atraves', 'sempre', 'nunca', 'quase', 'pelos', 'pelas',
        'deste', 'desta', 'neste', 'nesta', 'esses', 'essas', 'estes', 'estas', 'aquele', 'aquela', 'sobre', 'segundo',
        'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo', 'janeiro', 'fevereiro', 'marco', 'abril',
        'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro', 'milhoes', 'milhao', 'euros',
        'portugal', 'portugues', 'portuguesa', 'portugueses', 'portuguesas', 'pessoas', 'novos', 'novas', 'grande',
        'grandes', 'primeiro', 'primeira', 'ultimo', 'ultima', 'ultimos', 'ultimas', 'contra', 'podem', 'devem', 'estao',
        'foram', 'serao', 'tinha', 'ficam', 'fazer', 'pode', 'diz', 'dizem', 'afirma', 'garante', 'garantem', 'admite',
        'quer', 'querem', 'vai', 'vao', 'hoje', 'ontem', 'amanha', 'semana', 'semanas', 'meses', 'anos', 'horas',
        'mundo', 'aqui', 'onde', 'qual', 'quais', 'quem', 'como', 'mais', 'para', 'pela', 'pelo', 'numa', 'num',
    ];

    public static function normalize(string $text): string
    {
        $text = Str::ascii(mb_strtolower($text, 'UTF-8'));
        $text = preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /** O título como se guarda: sem marcação HTML, sem entidades, espaços simples, até 255 carateres. */
    public static function cleanTitle(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, 255);
    }

    /** As palavras que dizem o assunto: 5 ou mais letras, sem as palavras vazias (DESENHO 4.1). */
    public static function significantWords(string $title): array
    {
        $words = array_filter(explode(' ', self::normalize($title)), fn (string $w) => strlen($w) >= 5 && ! ctype_digit($w) && ! in_array($w, self::STOPWORDS, true));

        return array_values(array_unique($words));
    }
}
