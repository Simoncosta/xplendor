<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Services\Radar\Filter\RadarSafetyFilter;
use App\Services\Radar\Sources\RadarSources;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Radar (R1): o filtro de segurança (DESENHO.md, secção 5). Para cada regra, um título que a
 * dispara e um que não. Os títulos e os nomes são fictícios. Os temas a evitar da marca são por
 * empresa e entram no encaixe (R2), não aqui.
 */
class RadarSafetyFilterTest extends TestCase
{
    use FakesRadarFeeds;

    private RadarSafetyFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->filter = new RadarSafetyFilter();
    }

    private function assertBlocked(string $reason, string $title, array $related = [], array $categories = []): void
    {
        $r = $this->filter->check($title, $related, $categories);
        $this->assertSame('blocked', $r['verdict'], "devia bloquear: {$title}");
        $this->assertContains($reason, $r['reasons'], "motivo {$reason}: {$title}");
    }

    private function assertPassed(string $title, array $related = [], array $categories = []): array
    {
        $r = $this->filter->check($title, $related, $categories);
        $this->assertSame('passed', $r['verdict'], "devia passar: {$title} (" . implode(', ', $r['reasons']) . ')');
        $this->assertSame([], $r['reasons']);

        return $r;
    }

    public function test_tragedies_are_blocked(): void
    {
        $this->assertBlocked('tragedia', 'Incêndio destrói armazém em Aveiro');
        $this->assertBlocked('tragedia', 'Explosao em fabrica de Leiria'); // sem acentos
        $this->assertPassed('Armazém abre portas em Aveiro');
    }

    public function test_deaths_are_blocked(): void
    {
        $this->assertBlocked('morte', 'Morreu o fundador da pastelaria mais antiga de Aveiro');
        $this->assertBlocked('morte', 'Vítimas recordadas em Coimbra');
        $this->assertPassed('Pastelaria mais antiga de Aveiro faz 100 anos');
    }

    public function test_accidents_are_blocked(): void
    {
        $this->assertBlocked('acidente', 'Despiste na A1 corta o trânsito durante a manhã');
        $this->assertPassed('Obras na A1 terminam esta semana');
    }

    public function test_crimes_are_blocked(): void
    {
        $this->assertBlocked('crime', 'Assalto a joalharia no centro de Braga');
        $this->assertBlocked('crime', 'GNR fecha estrada nacional');
        $this->assertBlocked('crime', 'Operação termina sem novidades', [], ['Justiça']);
        $this->assertPassed('Joalharia no centro de Braga celebra 50 anos');
    }

    public function test_diseases_are_blocked(): void
    {
        $this->assertBlocked('doenca', 'Surto de gripe enche urgências');
        $this->assertPassed('Época das castanhas começa mais cedo');
    }

    public function test_politics_is_blocked_by_words_acronyms_and_category(): void
    {
        $this->assertBlocked('politica', 'Governo aprova novas regras para o alojamento local');
        $this->assertBlocked('politica', 'PS e PSD discutem as portagens');
        $this->assertBlocked('politica', 'Chega pede audição sobre as portagens');
        $this->assertBlocked('politica', 'Debate sobre as portagens', [], ['Política']);
        $this->assertPassed('Alojamento local cresce no verão');
        // Em minúsculas, "chega" e "ps" são palavras comuns.
        $this->assertPassed('O frio chega ao Algarve no fim de semana');
    }

    public function test_religion_is_blocked(): void
    {
        $this->assertBlocked('religiao', 'Peregrinação a Fátima junta milhares');
        $this->assertBlocked('religiao', 'Comunidade muçulmana celebra o fim do jejum');
        $this->assertPassed('Feira do livro junta milhares em Lisboa');
    }

    public function test_conflicts_are_blocked(): void
    {
        $this->assertBlocked('conflito', 'Ataque com drones atinge porto ucraniano');
        $this->assertBlocked('conflito', 'rússia', ['Conversações sobre a trégua energética continuam']); // pelas notícias do Trends
        $this->assertPassed('Porto de Sines bate recorde de contentores');
    }

    public function test_a_person_name_about_personal_life_is_blocked(): void
    {
        $r = $this->filter->check('Divórcio de Inês Montenegro surpreende os fãs');
        $this->assertSame(['blocked', ['vida_pessoal'], ['nome_pessoa']], [$r['verdict'], $r['reasons'], $r['flags']]);
        // Sem palavra de evento público, um nome bloqueia (na dúvida, bloqueia).
        $this->assertBlocked('vida_pessoal', 'A nova casa de Inês Montenegro');
        // Consulta do Trends em minúsculas: o nome reconhece-se pelas notícias relacionadas.
        $this->assertBlocked('vida_pessoal', 'inês montenegro', ['Inês Montenegro mostra fotografias do bebé']);
        // Sem nome de pessoa, a palavra de vida pessoal não bloqueia.
        $this->assertPassed('Divórcios aumentam em Portugal');
    }

    public function test_a_person_name_at_a_public_event_passes_with_the_flag(): void
    {
        $r = $this->assertPassed('Concerto de Inês Montenegro esgota o Coliseu');
        $this->assertSame(['nome_pessoa'], $r['flags']);
        $trend = $this->assertPassed('inês montenegro', ['Inês Montenegro abre a digressão no Porto']);
        $this->assertContains('nome_pessoa', $trend['flags']);
        // Sem nome, nenhuma marca.
        $this->assertSame([], $this->assertPassed('Concerto esgota o Coliseu')['flags']);
        // Lugares, marcas e instituições com maiúsculas não são nomes de pessoas.
        $this->assertSame([], $this->assertPassed('Navio da National Geographic faz escala no Funchal')['flags']);
        $this->assertSame([], $this->assertPassed('linha do douro', ['Circulação suspensa na Linha do Douro entre novembro e março'])['flags']);
    }

    public function test_a_protected_brand_passes_with_the_flag(): void
    {
        $r = $this->assertPassed('Bares preparam ecrãs gigantes para a final da Champions');
        $this->assertContains('marca_protegida', $r['flags']);
        $this->assertContains('marca_protegida', $this->assertPassed('Mundial de 2030 traz turistas ao Porto')['flags']);
        $this->assertNotContains('marca_protegida', $this->assertPassed('Bares preparam ecrãs gigantes para o verão')['flags']);
        // "mundial" em minúsculas é um adjetivo.
        $this->assertNotContains('marca_protegida', $this->assertPassed('Exportações de cortiça atingem máximo mundial')['flags']);
    }

    public function test_sport_passes_with_the_flag(): void
    {
        $this->assertSame(['desporto'], $this->assertPassed('Benfica vence e segue em frente na Taça')['flags']);
        $this->assertSame(['desporto'], $this->assertPassed('benfica - porto')['flags'], 'consulta do Trends com a forma de um jogo');
        $this->assertSame(['desporto'], $this->assertPassed('Crónica da época', [], ['Desporto'])['flags']);
        // "Porto" e "Braga" sozinhos são cidades.
        $this->assertSame([], $this->assertPassed('Porto recebe feira de vinhos')['flags']);
        $this->assertContains('desporto', $this->assertPassed('FC Porto prepara o jogo de domingo')['flags']);
    }

    /** A amostra real da noite (anonimizada): o que o filtro tira do Google Trends e porquê. */
    public function test_the_nights_trends_sample(): void
    {
        $items = app(RadarSources::class)->get('google_trends')->parse($this->fixture('google-trends/trending-rss-pt.xml'))->items;
        $verdicts = [];
        foreach ($items as $dto) {
            $r = $this->filter->check($dto->title, $dto->relatedTitles(), $dto->filterCategories);
            $verdicts[$dto->title] = [$r['verdict'], $r['reasons'], $r['flags']];
        }
        $this->assertSame(['blocked', ['conflito'], []], $verdicts['rússia']);
        $this->assertSame(['passed', [], ['desporto']], $verdicts['benfica b']);
        $this->assertSame(['passed', [], []], $verdicts['thyssenkrupp']);
        $this->assertSame(['passed', [], []], $verdicts['linha do douro']);
        $this->assertSame(['passed', [], ['desporto']], $verdicts['atalanta - venezia']);
        $this->assertSame('blocked', $verdicts['israel'][0]);
        $this->assertSame(['passed', [], []], $verdicts['navio']);
        $this->assertSame(['blocked', ['vida_pessoal'], ['nome_pessoa']], $verdicts['elias morgado']);
        $this->assertSame(['blocked', ['morte', 'vida_pessoal'], ['nome_pessoa']], $verdicts['tomé arrais']);
        $this->assertSame(4, count(array_filter($verdicts, fn ($v) => $v[0] === 'blocked')));
    }
}
