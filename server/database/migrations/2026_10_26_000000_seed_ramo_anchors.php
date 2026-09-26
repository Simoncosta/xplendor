<?php

use App\Models\ContentAnchor;
use App\Models\ContentSector;
use Illuminate\Database\Migrations\Migration;

/**
 * XPLENDOR — Linha Editorial: âncoras VARIÁVEIS específicas de cada ramo (com "gancho"
 * de conteúdo em suggestion). NÃO repete as universais (Natal, Dia da Mãe/Pai, Carnaval,
 * Páscoa, Halloween, Black Friday, mudança de hora, Santos Populares, etc. — já no Universal).
 * Estações vagas viram âncoras `fixa` marcantes (datas-lembrete). Idempotente: updateOrCreate
 * por (sector_id, title). origin='variavel', country='PT'. content_anchors só recebe linhas —
 * a estrutura não muda. Carros herda de Automóvel; específicas de carros ficam para depois.
 * Datas em dia/mês (D/M).
 */
return new class extends Migration
{
    /** slug do nó → âncoras [título, dia, mês, gancho] (todas fixa). */
    private function data(): array
    {
        return [
            // AUTOMÓVEL (herdado por Carros E Autocaravanas)
            'automovel' => [
                ['Dia do 4x4', 4, 4, 'SUVs e jipes; aventuras off-road e rotas de terra em Portugal'],
                ['Dia Mundial sem Carros', 22, 9, 'lado sustentável; híbridos/elétricos; eco-condução'],
                ['Início do Verão (check-up)', 21, 6, 'check-up para o calor: pneus, líquido de refrigeração, palhetas'],
                ['Segurança de Outono', 26, 10, 'luzes do carro, pneus para piso molhado, condução com chuva'],
                ['Início do Inverno', 21, 12, 'viagens de inverno; carros na neve; balanço do ano'],
                ['Regresso às Aulas (mobilidade)', 15, 9, 'mobilidade urbana; carros económicos; revisão pós-férias'],
            ],
            // CARROS — SEM âncoras por agora (herda de Automóvel; específicas metem-se depois).
            'carros' => [],
            // AUTOCARAVANAS
            'autocaravanas' => [
                ['O Ano das Pontes', 2, 1, 'calendário de feriados e pontes; transformar 3 dias numa semana de road trip'],
                ['Preparação para a Primavera', 20, 3, 'checklist limpeza: baterias, pneus, níveis; início do calendário de campismo'],
                ['Reservas Antecipadas', 1, 6, 'contagem para o verão; reservar cedo; mínimo de diárias na época alta'],
                ['Verão dos Autocaravanistas', 1, 9, 'época média/baixa: menos trânsito, praias vazias, temperaturas amenas'],
                ['Invernamento', 15, 11, 'como invernar: esvaziar águas, proteger estofos'],
                ['Mercados de Natal', 1, 12, 'roteiros de mercados de Natal; retrospetiva dos locais visitados'],
            ],
            // DOMÓTICA
            'domotica' => [
                ['Dia da Privacidade de Dados', 28, 1, 'segurança IoT; proteger a rede de dispositivos contra invasões'],
                ['Dia da Terra', 22, 4, 'eco-domótica; sensores que evitam consumo fantasma'],
                ['Preparar o Calor (persianas)', 1, 5, 'persianas/toldos que fecham sozinhos quando o sol bate'],
                ['Férias / Segurança', 1, 7, 'simulação de presença; vigilância à distância para afastar intrusos'],
                ['Pico de Calor (AC)', 1, 8, 'ligar o AC 15 min antes de chegar via telemóvel'],
                ['Regresso às Aulas (foco)', 15, 9, 'rotinas para as crianças focarem: luz fria, sem TV'],
            ],
            // RESTAURAÇÃO
            'restauracao' => [
                ['Início da Primavera (nova carta)', 20, 3, 'pratos frescos, saladas, reabertura de esplanadas, bastidores da nova carta'],
                ['Início do Verão (esplanadas)', 21, 6, 'cocktails, bebidas refrescantes, jantares ao ar livre'],
                ['Época Balnear', 1, 7, 'refeições leves, pratos para partilhar, menus executivos rápidos, happy hour'],
                ['Regresso às Aulas (executivos)', 15, 9, 'menus de almoço executivos; marmitas; pratos rápidos do dia'],
                ['Magusto / São Martinho', 11, 11, 'castanhas assadas, água-pé, jeropiga, ambiente'],
                ['Jantares de Natal de Empresa', 1, 11, 'divulgar menus de grupo cedo; espaço decorado'],
                ['Passagem de Ano', 15, 12, 'menu de reveillon, preço fechado, limite de vagas'],
                ['Janeiro Verde', 2, 1, 'opções leves, vegetarianas, fit, recuperar dos excessos'],
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->data() as $slug => $rows) {
            $sector = ContentSector::where('slug', $slug)->first();
            if (! $sector) {
                continue; // ramo ainda não existe → ignora
            }

            foreach ($rows as [$title, $day, $month, $suggestion]) {
                ContentAnchor::updateOrCreate(
                    ['sector_id' => $sector->id, 'title' => $title],
                    [
                        'country'    => 'PT',
                        'origin'     => 'variavel',
                        'rule_type'  => 'fixa',
                        'month'      => $month,
                        'day'        => $day,
                        'suggestion' => $suggestion,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        foreach ($this->data() as $slug => $rows) {
            $sector = ContentSector::where('slug', $slug)->first();
            if (! $sector || empty($rows)) {
                continue;
            }
            $titles = array_map(static fn ($r) => $r[0], $rows);
            ContentAnchor::where('sector_id', $sector->id)
                ->where('origin', 'variavel')
                ->whereIn('title', $titles)
                ->delete();
        }
    }
};
