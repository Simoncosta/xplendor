<?php

declare(strict_types=1);

namespace App\Services\Radar\Relevance;

/**
 * Bússola 2: o que interessa a cada ramo, para o pedido de relevância do Radar (radar_relevancia).
 * Um texto por ramo selecionável (content_sectors.slug); um ramo sem texto usa o genérico e fica
 * marcado no relatório (missing()). Só descreve o ramo: nunca dados de uma empresa.
 */
final class SectorBriefs
{
    public const GENERIC = 'Uma empresa deste ramo publica nas redes sociais sobre o que vende e para quem; interessam-lhe tendências que '
        . 'os seus clientes estão a seguir e a que pode reagir sem sair do seu tema. Não lhe interessam polémicas, desgraças nem política.';

    public const BRIEFS = [
        'restauracao' => 'Um restaurante, café ou tasca: interessam-lhe a comida e a bebida, os produtos da época, as feiras e festas '
            . 'gastronómicas, os feriados e as datas em que se sai para comer, o tempo (calor, chuva, frio) quando muda a vontade de sair, '
            . 'e os grandes jogos e eventos que juntam pessoas à mesa. Não lhe interessam intoxicações, crimes, inspeções nem polémicas.',
        'carros' => 'Um stand de automóveis usados ou novos: interessam-lhe o mercado automóvel, os lançamentos de modelos, os preços dos '
            . 'combustíveis e da eletricidade, os incentivos ao abate e aos elétricos, o IUC e o ISV, as regras de circulação, as viagens de '
            . 'fim de semana e as feiras automóveis. Não lhe interessam acidentes, mortes na estrada nem crimes.',
        'autocaravanas' => 'Um vendedor ou alugador de autocaravanas: interessam-lhe as viagens e as escapadinhas, os parques e as áreas '
            . 'de serviço, as férias e as pontes, os festivais e as rotas pelo país, o tempo para viajar e as regras de pernoita. '
            . 'Não lhe interessam acidentes, incêndios nem crimes.',
        'domotica' => 'Uma empresa de domótica e casa inteligente: interessam-lhe a poupança de energia, a fatura da luz, os painéis '
            . 'solares e as baterias, a segurança da casa, os assistentes de voz e os lançamentos de tecnologia para a casa, e as vagas '
            . 'de calor ou frio quando se fala de climatização. Não lhe interessam assaltos com vítimas nem polémicas.',
        'software-saas' => 'Uma empresa de software por subscrição (SaaS) que vende a outras empresas: interessam-lhe a inteligência '
            . 'artificial e a produtividade, as obrigações novas das empresas (faturação, fiscalidade, proteção de dados), a transformação '
            . 'digital, os eventos de tecnologia e de empreendedorismo, e as notícias sobre trabalho remoto. Não lhe interessam ciberataques '
            . 'com vítimas, despedimentos nem polémicas de empresas concretas.',
        'agencia-comunicacao' => 'Uma agência de comunicação e marketing: interessam-lhe as tendências das redes sociais, as mudanças do '
            . 'Instagram, do Facebook, do TikTok e da Google, as campanhas e os anúncios de que se fala, a inteligência artificial na '
            . 'criação de conteúdos, e as datas do calendário de marketing. Não lhe interessam polémicas com marcas concretas nem política.',
        'imobiliario' => 'Um consultor ou agência imobiliária: interessam-lhe as taxas de juro e as decisões do BCE, o crédito à habitação, '
            . 'os preços das casas e das rendas, os apoios à compra de casa pelos jovens, as obras e a mobilidade nos bairros, e as regras '
            . 'do arrendamento. Não lhe interessam despejos com vítimas, crimes nem polémicas políticas.',
    ];

    public static function for(string $slug): string
    {
        return self::BRIEFS[$slug] ?? self::GENERIC;
    }

    /** @param  array<string, string>  $sectors  slug => nome @return list<string> os ramos sem texto próprio */
    public static function missing(array $sectors): array
    {
        return array_values(array_diff(array_keys($sectors), array_keys(self::BRIEFS)));
    }
}
