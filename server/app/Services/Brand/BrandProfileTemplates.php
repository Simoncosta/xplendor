<?php

declare(strict_types=1);

namespace App\Services\Brand;

use App\Models\ContentSector;

/**
 * Modelos de Perfil da Marca por ramo (setores folha da Linha Editorial), no código e
 * versionados: qualquer alteração passa por revisão e sobe a VERSION, que fica registada
 * em cada pedido de sugestão. São pontos de partida genéricos (nunca factos da empresa):
 * a IA adapta-os aos dados disponíveis e o humano aceita, ou não, campo a campo.
 * Ramo sem modelo próprio (ou agrupador) → modelo universal.
 */
final class BrandProfileTemplates
{
    // v2: + Software (SaaS) e Agência de comunicação.
    public const VERSION = 'brand-templates-v3';
    public const FALLBACK = 'universal';

    private const TEMPLATES = [
        'restauracao' => [
            'label' => 'Restauração',
            'tone_of_voice' => 'Acolhedor e apetitoso, com frases curtas. Descreve sabores, ingredientes e o ambiente da casa; trata o cliente por você.',
            'audience' => 'Pessoas da zona e visitantes que procuram onde comer, celebrar ou reunir a família e os amigos.',
            'pillars' => [
                ['name' => 'Pratos e sabores', 'description' => 'Os pratos da casa, os ingredientes e a forma de os preparar.'],
                ['name' => 'Bastidores', 'description' => 'A cozinha, a equipa e o dia a dia do restaurante.'],
                ['name' => 'Datas e ocasiões', 'description' => 'Menus de época, datas festivas e eventos.'],
                ['name' => 'Clientes e comunidade', 'description' => 'Opiniões, momentos partilhados e a ligação ao bairro.'],
            ],
            'words_to_use' => ['sabor', 'fresco', 'da casa', 'partilhar', 'reserve'],
            'words_to_avoid' => ['barato', 'o melhor do mundo'],
            'topics_to_avoid' => ['política', 'comparações com outros restaurantes'],
            'hashtags_default' => ['#restaurante', '#gastronomia', '#comida'],
            'cta_default' => 'Reserve a sua mesa.',
            'emoji_policy' => 'light',
        ],
        'carros' => [
            'label' => 'Carros',
            'tone_of_voice' => 'Profissional e de confiança, com informação concreta sobre cada viatura. Sem exageros; trata o cliente por você.',
            'audience' => 'Pessoas que procuram comprar uma viatura com garantia e um atendimento próximo e transparente.',
            'pillars' => [
                ['name' => 'Viaturas em stock', 'description' => 'As novidades e os destaques, com os dados que importam.'],
                ['name' => 'Confiança', 'description' => 'Garantia, revisões, histórico e transparência no negócio.'],
                ['name' => 'Dicas', 'description' => 'Manutenção, escolha da viatura certa e financiamento.'],
                ['name' => 'Entregas', 'description' => 'Clientes satisfeitos e viaturas entregues.'],
            ],
            'words_to_use' => ['garantia', 'revista', 'confiança', 'visite-nos'],
            'words_to_avoid' => ['imperdível', 'barato'],
            'topics_to_avoid' => ['política', 'comparações com outros stands'],
            'hashtags_default' => ['#carrosusados', '#stand', '#automovel'],
            'cta_default' => 'Marque a sua visita ou fale connosco pelo WhatsApp.',
            'emoji_policy' => 'light',
        ],
        'autocaravanas' => [
            'label' => 'Autocaravanas',
            'tone_of_voice' => 'Próximo e técnico, com o espírito de viagem. Explica equipamentos e soluções sem jargão; trata o cliente por você.',
            'audience' => 'Famílias, casais e reformados que viajam ou querem começar a viajar de autocaravana.',
            'pillars' => [
                ['name' => 'Viaturas em stock', 'description' => 'As autocaravanas disponíveis e o que as distingue.'],
                ['name' => 'Viajar de autocaravana', 'description' => 'Destinos, rotas e a vida na estrada.'],
                ['name' => 'Dicas técnicas', 'description' => 'Equipamentos, manutenção e preparação para cada estação.'],
                ['name' => 'Comunidade', 'description' => 'Histórias de clientes e encontros.'],
            ],
            'words_to_use' => ['liberdade', 'estrada', 'conforto', 'viagem'],
            'words_to_avoid' => ['barato', 'imperdível'],
            'topics_to_avoid' => ['política'],
            'hashtags_default' => ['#autocaravana', '#autocaravanismo', '#vanlife'],
            'cta_default' => 'Venha conhecer a sua próxima autocaravana.',
            'emoji_policy' => 'light',
        ],
        'domotica' => [
            'label' => 'Domótica',
            'tone_of_voice' => 'Claro e didático, focado em conforto, segurança e poupança. Evita termos técnicos sem explicação; trata o cliente por você.',
            'audience' => 'Proprietários e famílias que querem uma casa mais confortável, segura e eficiente.',
            'pillars' => [
                ['name' => 'Soluções', 'description' => 'O que a casa inteligente resolve no dia a dia.'],
                ['name' => 'Projetos', 'description' => 'Instalações feitas e o antes e depois.'],
                ['name' => 'Poupança e segurança', 'description' => 'Energia, alarmes, câmaras e controlo à distância.'],
                ['name' => 'Perguntas frequentes', 'description' => 'Dúvidas comuns explicadas de forma simples.'],
            ],
            'words_to_use' => ['conforto', 'segurança', 'poupança', 'simples'],
            'words_to_avoid' => ['complicado', 'barato'],
            'topics_to_avoid' => ['política'],
            'hashtags_default' => ['#domotica', '#casainteligente', '#smarthome'],
            'cta_default' => 'Peça uma avaliação da sua casa.',
            'emoji_policy' => 'light',
        ],
        'software-saas' => [
            'label' => 'Software (SaaS)',
            'tone_of_voice' => 'Claro e confiante. Explica o problema que o produto resolve e mostra-o a funcionar, com exemplos concretos e sem jargão.',
            'audience' => 'Empresas e profissionais que procuram uma ferramenta para resolver um problema concreto do dia a dia e querem perceber depressa se ela serve.',
            'pillars' => [
                ['name' => 'O problema e a solução', 'description' => 'O problema que o produto resolve e a forma como o resolve.'],
                ['name' => 'O produto em ação', 'description' => 'As funcionalidades e as novidades, mostradas a funcionar.'],
                ['name' => 'Casos de clientes', 'description' => 'O que os clientes conseguiram com o produto, sempre com a autorização deles.'],
                ['name' => 'Dicas de utilização', 'description' => 'Formas simples de tirar mais partido do produto.'],
                ['name' => 'Bastidores', 'description' => 'A equipa e a forma como o produto é pensado e construído.'],
            ],
            'words_to_use' => ['simples', 'em minutos', 'automático', 'experimente'],
            'words_to_avoid' => ['revolucionário', 'disruptivo', 'garantido'],
            'topics_to_avoid' => ['clientes sem autorização', 'concorrentes pelo nome'],
            'hashtags_default' => ['#software', '#saas', '#produtividade'],
            'cta_default' => 'Peça uma demonstração.',
            'emoji_policy' => 'light',
        ],
        'agencia-comunicacao' => [
            'label' => 'Agência de comunicação',
            'tone_of_voice' => 'Criativo e profissional. Mostra o trabalho e a forma de pensar da agência com exemplos reais, num registo próximo e seguro.',
            'audience' => 'Empresas e marcas que procuram uma agência para a sua comunicação: redes sociais, digital, imagem e campanhas.',
            'pillars' => [
                ['name' => 'Trabalhos para clientes', 'description' => 'Projetos feitos pela agência, sempre com a autorização dos clientes.'],
                ['name' => 'Processo e bastidores', 'description' => 'Como a agência trabalha, da ideia à entrega.'],
                ['name' => 'Dicas de comunicação', 'description' => 'Conselhos práticos para as marcas comunicarem melhor.'],
                ['name' => 'A equipa', 'description' => 'As pessoas da agência e o que cada uma faz.'],
                ['name' => 'Resultados', 'description' => 'O que as campanhas alcançaram, com números concretos.'],
            ],
            'words_to_use' => ['ideia', 'estratégia', 'marca', 'resultados'],
            'words_to_avoid' => ['barato', 'imperdível'],
            'topics_to_avoid' => ['clientes sem autorização', 'críticas a outras agências'],
            'hashtags_default' => ['#comunicacao', '#marketingdigital', '#agencia'],
            'cta_default' => 'Fale connosco sobre o seu projeto.',
            'emoji_policy' => 'light',
        ],
        // Bússola Master (R3): o consultor imobiliário. Nunca prometer valorização nem dar conselhos
        // sobre taxas de juro; a classe energética é obrigatória em qualquer anúncio.
        'imobiliario' => [
            'label' => 'Imobiliário',
            'tone_of_voice' => 'Próximo, rigoroso e calmo. Mostra os imóveis e a zona com factos verificáveis e acompanha a pessoa em cada passo da compra, da venda ou do arrendamento.',
            'audience' => 'Pessoas e famílias que querem comprar, vender ou arrendar casa na zona onde o consultor trabalha, e proprietários que procuram quem os represente.',
            'pillars' => [
                ['name' => 'A zona', 'description' => 'O que a zona tem: serviços, transportes, escolas, comércio e o dia a dia.'],
                ['name' => 'Os imóveis', 'description' => 'Os imóveis em carteira, com as características verificáveis e a classe energética.'],
                ['name' => 'O processo', 'description' => 'Como funciona comprar, vender ou arrendar, passo a passo.'],
                ['name' => 'A confiança', 'description' => 'Testemunhos de quem fechou negócio, sempre com autorização, e a forma de trabalhar do consultor.'],
            ],
            'words_to_use' => ['zona', 'visita', 'acompanhamento', 'classe energética'],
            'words_to_avoid' => ['oportunidade única', 'imperdível', 'garantido', 'valorização certa'],
            'topics_to_avoid' => ['promessas de valorização', 'conselhos sobre taxas de juro', 'dados de proprietários', 'clientes sem autorização'],
            'hashtags_default' => ['#imobiliario', '#casa', '#imoveis'],
            'cta_default' => 'Marque uma visita.',
            'emoji_policy' => 'light',
        ],
        'universal' => [
            'label' => 'Universal',
            'tone_of_voice' => 'Próximo e profissional, com frases curtas e informação útil. Trata o cliente por você.',
            'audience' => 'Clientes atuais e potenciais da zona onde a empresa trabalha.',
            'pillars' => [
                ['name' => 'Produtos e serviços', 'description' => 'O que a empresa faz e como ajuda.'],
                ['name' => 'Bastidores', 'description' => 'A equipa e a forma de trabalhar.'],
                ['name' => 'Dicas úteis', 'description' => 'Conselhos práticos ligados à área da empresa.'],
                ['name' => 'Clientes', 'description' => 'Opiniões e casos reais.'],
            ],
            'words_to_use' => ['qualidade', 'confiança', 'proximidade'],
            'words_to_avoid' => ['barato', 'imperdível'],
            'topics_to_avoid' => ['política'],
            'hashtags_default' => [],
            'cta_default' => 'Fale connosco.',
            'emoji_policy' => 'light',
        ],
    ];

    /** Chave do modelo para o ramo da empresa (folha com modelo próprio, senão universal). */
    public static function keyFor(?ContentSector $sector): string
    {
        $slug = $sector?->slug;

        return $slug !== null && isset(self::TEMPLATES[$slug]) ? $slug : self::FALLBACK;
    }

    /** O modelo (com a chave e a versão). */
    public static function for(?ContentSector $sector): array
    {
        $key = self::keyFor($sector);

        return ['key' => $key, 'version' => self::VERSION] + self::TEMPLATES[$key];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
