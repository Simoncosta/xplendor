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
    public const VERSION = 'brand-templates-v1';
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
