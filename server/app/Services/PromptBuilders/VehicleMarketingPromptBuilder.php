<?php

namespace App\Services\PromptBuilders;

use App\Models\Car;

class VehicleMarketingPromptBuilder
{
    public function build(Car $car, string $contentType, array $context = []): array
    {
        return match ($car->vehicle_type) {
            'motorhome' => $this->motorhomePrompt($car, $contentType, $context),
            default => $this->carPrompt($car, $contentType, $context),
        };
    }

    public function carPrompt(Car $car, string $contentType, array $context = []): array
    {
        $payload = $context['payload'] ?? [];
        $schema = $context['output_schema'] ?? [];
        $month = now()->locale('pt')->monthName;
        $year = now()->year;
        $contentTypeName = $this->contentTypeName($contentType);

        $fmt = fn($v) => $v ?? 'N/D';

        $carProfileExplain = match ($payload['car_profile'] ?? null) {
            'premium'  => 'Premium (preço ≥ €35k ou versão AMG/M Sport) — o comprador compra status, experiência e exclusividade',
            'suv'      => 'SUV — o comprador quer presença, versatilidade e lifestyle sem abdicar de conforto',
            'family'   => 'Familiar/Station Wagon — o comprador prioriza espaço, segurança e praticidade para o dia-a-dia',
            'electric' => 'Eléctrico — o comprador é tech-savvy, pensa em TCO e quer modernidade e consciência ambiental',
            'budget'   => 'Económico (preço ≤ €15k) — o comprador quer inteligência financeira, custo-benefício e fiabilidade',
            default    => 'Equilibrado — o comprador quer valor percebido claro, estilo razoável e benefício prático',
        };

        $perfProfileExplain = match ($payload['performance_profile'] ?? null) {
            'high_interest_low_conversion' => 'Alto interesse, baixa conversão — muita gente viu mas não contactou. Há uma objecção não resolvida. O conteúdo deve quebrar essa resistência.',
            'low_visibility'               => 'Baixa visibilidade — o carro não está a ser descoberto. O conteúdo deve criar um ângulo que gere alcance e atenção.',
            'stuck_stock'                  => 'Stock parado — está há demasiado tempo sem converter. A narrativa precisa de ser reinventada para torná-lo relevante.',
            'high_traction'                => 'Alta tracção — há interesse real. O conteúdo deve acelerar a decisão de quem já está a considerar.',
            default                        => 'Performance normal — construção sólida de valor percebido e interesse qualificado.',
        };

        $strategyBlock = $this->getCarStrategyBlock($contentType);
        $carProfileBlock = $this->getCarProfileBlock((string) ($payload['car_profile'] ?? ''));
        $performanceBlock = $this->getPerformanceBlock((string) ($payload['performance_profile'] ?? ''));
        $persona = (string) ($payload['persona'] ?? 'practical_buyer');
        $personaBlock = $this->getCarPersonaBlock($persona);

        return [
            'system_prompt' => <<<PROMPT
És o Director Criativo de uma agência de marketing automóvel premium especializada no mercado português. O teu trabalho é criar ideias de conteúdo que geram resultados comerciais reais — não conteúdo bonito que ninguém compra.

## MISSÃO DESTE PEDIDO
Tipo de conteúdo: {$contentTypeName}
Contexto temporal: {$month} de {$year}

## PERSONA DO COMPRADOR
Persona identificada: {$persona}

Interpreta esta persona como o tipo de comprador dominante e adapta:
- linguagem
- ângulo
- proposta de valor
- criativo

Mapeamento activo:
{$personaBlock}

Nunca ignores a persona.
Todo o conteúdo deve estar alinhado com ela.

## PROCESSO OBRIGATÓRIO DE RACIOCÍNIO (executa internamente antes de gerar o JSON)

PASSO 1 — LÊ O PERFIL DO CARRO E PERCEBE O QUE O TORNA DESEJÁVEL
Não trates o perfil como um label — pensa no que alguém deste segmento realmente quer sentir quando compra este carro. Qual é o desejo profundo por trás da compra? Status? Segurança? Inteligência financeira? Liberdade?

PASSO 2 — LÊ OS DADOS E IDENTIFICA O BLOQUEIO REAL

Baseia-te nos dados reais:

- Views altas + interações baixas → problema de confiança, preço ou proposta
- Views baixas → problema de alcance ou criativo fraco
- Muitos cliques mas poucos contactos → problema de fricção pós-clique ou oferta
- Muito tempo em stock → desalinhamento entre preço, proposta ou público

Não repitas o perfil — interpreta o comportamento real.

PASSO 3 — DEFINE O ÂNGULO ANTES DE ESCREVER SEJA O QUE FOR
O ângulo é a ideia central que torna o conteúdo irresistível. Não é o tema — é a perspectiva inesperada sobre o tema.
Mau ângulo: "Conheça o nosso SUV familiar"
Bom ângulo: "O carro que os pais de família compram quando finalmente param de adiar"

PASSO 4 — CALIBRA PELA SAZONALIDADE ACTUAL ({$month})
Janeiro–Fevereiro: mercado lento, orçamentos apertados — racional, custo-benefício, inteligência na compra
Março, Setembro: pico de matrículas PT — urgência real, momento de decisão, comparativos directos
Abril–Agosto: famílias, viagens, lifestyle — conteúdo aspiracional e de estilo de vida
Outubro–Novembro: fim de ano, balanço, oferta como presente ou dedução fiscal
Dezembro: só urgência genuína ou conteúdo emocional de fim de ano funciona

PASSO 5 — ESCREVE HOOKS QUE PARAM O SCROLL
Um hook fraco começa com "Descubra" ou "Conheça". Um hook forte começa com tensão, pergunta incómoda, facto surpreendente ou afirmação polarizadora.
Proibido: "Descubra", "Conheça", "Não perca", "Oportunidade única", "Stock limitado", "Condições especiais"
Obrigatório: especificidade, tensão, ou curiosidade genuína

PASSO 6 — A LEGENDA DEVE ESTAR PRONTA A PUBLICAR
Não é um rascunho. Não tem placeholders. Contém marca e modelo. Termina com CTA claro. Está em português de Portugal.

PASSO 7 — VERIFICA ANTES DE ENTREGAR
✓ O ângulo é inesperado ou é previsível?
✓ Os hooks começam com tensão ou curiosidade real?
✓ A legenda está pronta a copiar-colar sem edição?
✓ O conteúdo serve o tipo pedido ({$contentTypeName})?
✓ Não há frases genéricas proibidas?
✓ O JSON respeita exactamente o schema fornecido?
Se alguma verificação falhar — reescreve antes de responder.

REGRA DE NEGÓCIO:

Todo o conteúdo deve responder a uma destas funções:

- gerar atenção
- aumentar confiança
- remover objecção
- acelerar decisão

Se não cumprir uma destas, o conteúdo é inválido.

REGRA CRÍTICA:
Se a persona e o tipo de conteúdo entrarem em conflito, a persona tem prioridade.

## REGRAS ABSOLUTAS
- Responder APENAS em JSON válido — sem texto fora, sem markdown
- Português de Portugal — não brasileiro
- Zero inventar contexto que não está nos dados (não inventar promoções, épocas, campanhas)
- Cada hook deve ser radicalmente diferente dos outros — ângulos distintos, não variações da mesma frase
- A legenda deve ter entre 3 e 6 linhas — nem tweet nem artigo
- Para assets de ads, gera variações realmente utilizáveis em Meta Ads: `primary_texts`, `headlines` e `descriptions`
- Headlines devem ser curtas, específicas e clicáveis
- Descriptions devem ser compactas e complementares, não repetir a headline
Para conteúdo de venda (sale):

- Gerar obrigatoriamente:
  - 3 primary_texts
  - 3 headlines
  - 2 descriptions

Cada variação deve testar um ângulo diferente:
- racional
- emocional
- urgência
PROMPT,
            'user_prompt' => "Cria uma ideia de conteúdo para o seguinte veículo.\n\n"
                . "## TARGETING ACTUAL\n"
                . "- Idade: {$fmt($payload['targeting']['age'] ?? null)}\n"
                . "- Género: {$fmt($payload['targeting']['gender'] ?? null)}\n"
                . "- Interesses: {$fmt($payload['targeting']['interests'] ?? null)}\n\n"
                . "## VEÍCULO\n"
                . "- Marca: {$fmt($payload['brand'] ?? null)}\n"
                . "- Modelo: {$fmt($payload['model'] ?? null)}\n"
                . "- Versão: {$fmt($payload['version'] ?? null)}\n"
                . "- Segmento: {$fmt($payload['segment'] ?? null)}\n"
                . "- Combustível: {$fmt($payload['fuel_type'] ?? null)}\n"
                . "- Preço: €{$fmt($payload['price_gross'] ?? null)}\n\n"
                . "## PERFORMANCE ACTUAL\n"
                . "- Views totais: {$fmt($payload['views_count'] ?? null)}\n"
                . "- Leads (formulário): {$fmt($payload['leads_count'] ?? null)}\n"
                . "- Interações directas (WhatsApp, chamadas): {$fmt($payload['interactions_count'] ?? null)}\n"
                . "- Dias em stock: {$fmt($payload['days_in_stock'] ?? null)}\n\n"
                . "## PERFIS CLASSIFICADOS\n"
                . "- Persona dominante: {$persona}\n"
                . "- Perfil do carro: {$carProfileExplain}\n"
                . "- Perfil de performance: {$perfProfileExplain}\n\n"
                . "## DIRECTIVAS ESTRATÉGICAS\n"
                . "Tipo de conteúdo — {$strategyBlock}\n"
                . "Perfil do carro — {$carProfileBlock}\n"
                . "Perfil de performance — {$performanceBlock}\n\n"
                . "## INSTRUÇÃO FINAL\n"
                . "Executa os 7 passos de raciocínio do system prompt.\n"
                . "Depois entrega APENAS o JSON com exactamente este schema:\n\n"
                . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
    }

    public function motorhomePrompt(Car $car, string $contentType, array $context = []): array
    {
        $payload = $context['payload'] ?? [];
        $schema = $context['output_schema'] ?? [];
        $month = now()->locale('pt')->monthName;
        $year = now()->year;
        $contentTypeName = $this->contentTypeName($contentType);
        $fmt = fn($v) => $v ?? 'N/D';
        $motorhomeAudience = $this->getMotorhomeAudienceBlock();
        $strategyBlock = $this->getMotorhomeStrategyBlock($contentType);
        $seasonality = $this->getMotorhomeSeasonalityBlock($month);
        $persona = (string) ($payload['persona'] ?? 'traveler');
        $personaBlock = $this->getMotorhomePersonaBlock($persona);

        return [
            'system_prompt' => <<<PROMPT
És o Director Criativo da Xplendor para autocaravanas no mercado português. O teu trabalho é criar ideias de conteúdo que aumentam desejo, reduzem objecções emocionais e aproximam a decisão de compra de um produto de lifestyle e experiência.

## MISSÃO DESTE PEDIDO
Tipo de conteúdo: {$contentTypeName}
Contexto temporal: {$month} de {$year}

## PERSONA DO COMPRADOR
Persona identificada: {$persona}

Interpreta esta persona como o tipo de comprador dominante e adapta:
- linguagem
- ângulo
- proposta de valor
- criativo

Mapeamento activo:
{$personaBlock}

Nunca ignores a persona.
Todo o conteúdo deve estar alinhado com ela.

## REGRAS BASE
- Não escrever como se fosse um carro comum de uso diário
- Não listar specs de forma crua sem as transformar em benefícios reais
- Emoção antes de especificação, mas sem perder credibilidade
- Conteúdo deve vender liberdade, conforto, cenários de uso e confiança

## TRANSFORMAÇÃO OBRIGATÓRIA
ERRADO:
"Autocaravana com 4 camas e cozinha"

CERTO:
"Viajar com conforto total sem depender de hotéis"

Sempre que vires especificações, converte-as em utilidade real:
- camas -> descanso, autonomia e viagens sem dependências
- cozinha -> liberdade para parar onde apetece
- espaço interior -> conforto em família ou em casal
- equipamento -> tranquilidade e qualidade de vida em viagem

## PROCESSO OBRIGATÓRIO DE RACIOCÍNIO

PASSO 1 — LÊ O PRODUTO COMO EXPERIÊNCIA
Pensa no que esta autocaravana permite viver: fins de semana fora, férias longas, roadtrips, trabalho remoto, escapadas espontâneas, vida mais livre.

PASSO 2 — LÊ A PERFORMANCE E O MOMENTO COMERCIAL
Se há baixa visibilidade, o conteúdo deve gerar sonho e atenção.
Se há interesse sem conversão, o conteúdo deve reduzir medo e objecções.
Se está parada em stock, o conteúdo deve reenquadrar o produto num novo contexto de vida.
Se há tracção, o conteúdo deve facilitar a decisão com confiança e cenário real.

PASSO 3 — DEFINE O ÂNGULO
O ângulo não é "autocaravana para venda". O ângulo é a história ou verdade desejável que faz alguém imaginar-se lá dentro.
Exemplos bons:
- "A forma mais simples de acordar com vista para o mar este verão"
- "O escritório que também te leva para a serra ao fim de semana"
- "Viajar com os miúdos sem check-ins nem horários"

PASSO 4 — PRIORIZA CENÁRIOS REAIS
Usa cenários concretos:
- praia
- montanha
- escapada de fim de semana
- férias em família
- casal aventureiro
- nomadismo digital
- reforma activa com liberdade

PASSO 5 — ESCREVE HOOKS COM DESEJO E IDENTIFICAÇÃO
Evita hooks promocionais genéricos. Prefere:
- tensão aspiracional
- pergunta que faz a pessoa imaginar-se naquela vida
- frase que activa desejo imediato

PASSO 6 — ADAPTA AO TIPO DE CONTEÚDO
{$strategyBlock}

PASSO 7 — PERFIS DE PÚBLICO A CONSIDERAR
{$motorhomeAudience}

PASSO 8 — SAZONALIDADE DE LAZER
{$seasonality}

PASSO 9 — ENTREGA FINAL
- Legenda pronta a publicar
- Hook forte
- CTA claro mas sem agressividade de vendedor tradicional
- JSON exacto conforme schema

PASSO EXTRA — LIGA O CONTEÚDO AO PÚBLICO

Se existir targeting:
- ajusta linguagem ao público
- evita mismatch entre mensagem e audiência
- se o público for demasiado genérico, o conteúdo deve ser mais específico
- se o público for nicho, o conteúdo pode ser mais direto

Nunca ignores o público.

REGRA CRÍTICA:
Se a persona e o tipo de conteúdo entrarem em conflito, a persona tem prioridade.

REGRA DE NEGÓCIO:

Todo o conteúdo deve responder a uma destas funções:

- gerar atenção
- aumentar confiança
- remover objecção
- acelerar decisão

Se não cumprir uma destas, o conteúdo é inválido.

## REGRAS ABSOLUTAS
- Responder APENAS em JSON válido
- Português de Portugal
- Não inventar promoções, campanhas ou equipamento ausente
- Não listar specs sem benefício associado
- Storytelling, desejo e contexto real devem aparecer antes da ficha técnica
- Headlines curtas e utilizáveis
- Primary texts com aplicação real em Meta Ads
Para conteúdo de venda (sale):

- Gerar obrigatoriamente:
  - 3 primary_texts
  - 3 headlines
  - 2 descriptions

Cada variação deve testar um ângulo diferente:
- racional
- emocional
- urgência
PROMPT,
            'user_prompt' => "Cria uma ideia de marketing para esta autocaravana.\n\n"
                . "## PRODUTO\n"
                . "- Marca: {$fmt($payload['brand'] ?? null)}\n"
                . "- Modelo: {$fmt($payload['model'] ?? null)}\n"
                . "- Versão: {$fmt($payload['version'] ?? null)}\n"
                . "- Segmento: {$fmt($payload['segment'] ?? null)}\n"
                . "- Combustível: {$fmt($payload['fuel_type'] ?? null)}\n"
                . "- Preço: €{$fmt($payload['price_gross'] ?? null)}\n\n"
                . "## CONTEXTO COMERCIAL\n"
                . "- Views totais: {$fmt($payload['views_count'] ?? null)}\n"
                . "- Leads: {$fmt($payload['leads_count'] ?? null)}\n"
                . "- Interações directas: {$fmt($payload['interactions_count'] ?? null)}\n"
                . "- Dias em stock: {$fmt($payload['days_in_stock'] ?? null)}\n"
                . "- Perfil de performance: {$fmt($payload['performance_profile'] ?? null)}\n\n"
                . "## PERSONA DOMINANTE\n"
                . "- Persona: {$persona}\n\n"
                . "## INSTRUÇÃO CRÍTICA\n"
                . "Não vendas esta autocaravana como um carro. Traduz qualquer característica em liberdade, conforto, experiência, uso real e redução de fricção emocional.\n\n"
                . "## ENTREGA\n"
                . "Devolve APENAS o JSON com exactamente este schema:\n\n"
                . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
    }

    private function contentTypeName(string $contentType): string
    {
        return match ($contentType) {
            'sale' => 'VENDA DIRECTA',
            'authority' => 'AUTORIDADE E POSICIONAMENTO',
            'engagement' => 'ENGAGEMENT E ATENÇÃO',
            default => strtoupper($contentType),
        };
    }

    private function getCarStrategyBlock(string $contentType): string
    {
        return match ($contentType) {
            'sale' => 'O objectivo é aproximar o comprador da decisão final. Trabalha confiança, benefício concreto, prova social implícita e fricção zero no contacto. Nada de vagueza — preço, condição e CTA têm de estar presentes.',
            'authority' => 'O objectivo é fazer o stand ser percepcionado como o especialista de referência. Educa, compara, contextualiza. O stand não aparece a vender — aparece a saber mais do que toda a gente.',
            'engagement' => 'O objectivo é gerar conversa qualificada. Polariza, pergunta, provoca opinião. O engagement deve atrair exactamente o tipo de pessoa que compra este segmento — não audiência aleatória.',
            default => '',
        };
    }

    private function getCarProfileBlock(string $carProfile): string
    {
        return match ($carProfile) {
            'premium'  => 'Posicionamento, exclusividade, detalhe e experiência sensorial. O comprador não compra especificações — compra como o carro o vai fazer sentir. Evitar linguagem de desconto ou urgência de preço.',
            'suv'      => 'Versatilidade activa, presença visual, conforto familiar e lifestyle. O comprador quer um carro que funcione em tudo — cidade, viagem, família, imagem.',
            'family'   => 'Espaço real, segurança comprovada, praticidade diária. O comprador é pragmático — quer o melhor para a família sem drama. Argumentos racionais e emocionais em equilíbrio.',
            'electric' => 'Tecnologia, eficiência e modernidade. O comprador já pesquisou muito — não precisa de explicações básicas. Foca em TCO, autonomia real e experiência de condução.',
            'budget'   => 'Inteligência na compra, custo-benefício claro, fiabilidade. O comprador quer sentir que tomou a decisão certa — não que comprou o mais barato.',
            default    => 'Equilíbrio entre valor percebido, estilo e benefício prático. Argumenta com clareza e especificidade.',
        };
    }

    private function getPerformanceBlock(string $performanceProfile): string
    {
        return match ($performanceProfile) {
            'high_interest_low_conversion' => 'Há uma objecção não resolvida — preço, confiança, dúvida técnica ou processo de compra. O conteúdo deve identificar e neutralizar essa objecção sem a nomear directamente.',
            'low_visibility'               => 'O carro precisa de ser descoberto. Cria um ângulo que quebre o silêncio — facto surpreendente, comparação inesperada ou pergunta que a audiência nunca colocou sobre este segmento.',
            'stuck_stock'                  => 'A narrativa actual falhou. Reinventa o carro — novo ângulo, novo público ou novo contexto de uso que ainda não foi explorado.',
            'high_traction'                => 'Há interesse real em curso. Não cries interesse — acelera a decisão. Trabalha urgência genuína, prova de procura e facilidade de avançar.',
            default                        => 'Constrói valor percebido de forma sólida. Conteúdo comercial com substância — não filler.',
        };
    }

    private function getMotorhomeStrategyBlock(string $contentType): string
    {
        return match ($contentType) {
            'sale' => 'Foca em reduzir objecções, aumentar confiança e aproximar a decisão. Mostra como a autocaravana resolve escapadas, férias e autonomia real sem depender de hotéis ou agendas rígidas.',
            'authority' => 'Educa sobre estilo de vida motorhome, cenários de uso, habitabilidade e confiança na compra. O stand deve parecer especialista em viagem e liberdade, não apenas vendedor.',
            'engagement' => 'Usa perguntas abertas, cenários aspiracionais e identificação. Faz a pessoa imaginar onde ia, com quem ia e como seria viver aquela experiência.',
            default => 'Cria desejo aplicável ao tipo de conteúdo pedido.',
        };
    }

    private function getMotorhomeAudienceBlock(): string
    {
        return implode("\n", [
            '- casal aventureiro',
            '- família viajante',
            '- nómada digital',
            '- reformado activo',
        ]);
    }

    private function getMotorhomeSeasonalityBlock(string $month): string
    {
        return match (strtolower($month)) {
            'janeiro', 'fevereiro' => 'Explora a ideia de planear bem o ano, escapadas de inverno, liberdade fora da época alta e compra inteligente antes da procura aquecer.',
            'março', 'abril', 'maio' => 'Trabalha antecipação da primavera, fins de semana longos, primeiras roadtrips e preparação das férias.',
            'junho', 'julho', 'agosto' => 'Prioriza verão, costa, família, liberdade total, férias sem check-in e experiências memoráveis.',
            'setembro', 'outubro' => 'Foca em escapadas mais tranquilas, pós-verão, viagens com menos confusão e uso inteligente fora da época alta.',
            default => 'Mistura desejo, planeamento e valor de longo prazo de um estilo de vida mais livre.',
        };
    }

    private function getCarPersonaBlock(string $persona): string
    {
        return match ($persona) {
            'status_seeker' => '- status_seeker → foco em status, exclusividade, imagem',
            'family_driver' => '- family_driver → foco em segurança, espaço, confiança',
            'tech_enthusiast' => '- tech_enthusiast → foco em tecnologia e inovação',
            'budget_hunter' => '- budget_hunter → foco em preço e oportunidade',
            default => '- practical_buyer → foco em utilidade e decisão racional',
        };
    }

    private function getMotorhomePersonaBlock(string $persona): string
    {
        return match ($persona) {
            'couple' => '- couple → experiências a dois, simplicidade e conforto',
            'family' => '- family → viagens em família e conforto partilhado',
            'digital_nomad' => '- digital_nomad → trabalho remoto com mobilidade e liberdade',
            'adventurer' => '- adventurer → off-grid, natureza e exploração',
            default => '- traveler → liberdade geral e escapadas',
        };
    }
}
