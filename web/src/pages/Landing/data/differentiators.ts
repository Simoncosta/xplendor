export interface Differentiator {
    icon: string;
    title: string;
    description: string;
}

export const DIFFERENTIATORS: Differentiator[] = [
    {
        icon: 'ri-robot-2-line',
        title: 'Tecnologia proprietária',
        description:
            'Plataforma de análise e scoring desenvolvida exclusivamente para stands automóveis. Não é uma ferramenta genérica adaptada.',
    },
    {
        icon: 'ri-focus-3-line',
        title: 'Foco único no setor automóvel',
        description:
            'Só trabalhamos com stands em Portugal. É o único mercado que conhecemos bem o suficiente para dar resultados consistentes.',
    },
    {
        icon: 'ri-bar-chart-2-line',
        title: 'Tracking real, viatura a viatura',
        description:
            'Sabe quanto custa cada lead por viatura — não em médias. Números concretos por anúncio, por canal, por mês.',
    },
    {
        icon: 'ri-handshake-line',
        title: 'Sem letra pequena',
        description:
            'Compromisso semestral com pré-aviso de 30 dias para sair. Sem renovações automáticas anuais nem cláusulas surpresa.',
    },
];
