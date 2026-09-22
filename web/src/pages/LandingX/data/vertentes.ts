import { RamoFeature } from './ramos';

// As 3 vertentes comunicadas na landing. Autocaravanas e Carros são ambos do ramo
// Automotivo, mas públicos diferentes, por isso separados. Conteúdo REAL (ModuleRegistry).
// Mensagem transversal: a XPLENDOR trata do negócio de PONTA A PONTA (captação →
// venda → pós-venda), é um sistema robusto, não ferramentas soltas. Sem travessões.

export interface Vertente {
    key: string;
    label: string;
    icon: string;       // remixicon (ri-*), já carregado globalmente
    accent: string;     // cor do acento (rgba subtil) para a linha animada + tints
    tagline: string;    // uma linha no cartão/aba
    dor: string;        // a dor que resolve
    lead: string;       // frase de abertura do detalhe
    features: RamoFeature[];
    note: string;       // reforço "sistema de ponta a ponta"
}

export const VERTENTES: Vertente[] = [
    {
        key: 'autocaravanas',
        label: 'Autocaravanas',
        icon: 'ri-caravan-line',
        accent: 'rgba(0, 209, 198, 0.7)',
        tagline: 'Stock especializado e mercado exigente.',
        dor: 'Avaliar autocaravanas é difícil e o stock é muito especializado. Sem dados de mercado, arriscas comprar mal e deixar viaturas caras a estagnar.',
        lead: 'Do anúncio à entrega, com o pós-venda já incluído.',
        features: [
            { icon: 'ri-caravan-line', title: 'Stock de autocaravanas', desc: 'Fichas completas, fotos e especificações.' },
            { icon: 'ri-radar-line', title: 'Análise de mercado', desc: 'Preço comparável para comprar e vender melhor.' },
            { icon: 'ri-user-star-line', title: 'CRM de leads', desc: 'Cada contacto ligado à autocaravana certa.' },
            { icon: 'ri-printer-line', title: 'Ficha e impressão', desc: 'Ficha A4 pronta para o stand e o cliente.' },
            { icon: 'ri-customer-service-2-line', title: 'Pós-venda', desc: 'Acompanhamento e relatório de satisfação após a venda.' },
        ],
        note: 'Da captação à venda e ao pós-venda, tudo ligado ao cliente e à viatura.',
    },
    {
        key: 'carros',
        label: 'Carros',
        icon: 'ri-car-line',
        accent: 'rgba(139, 92, 246, 0.7)',
        tagline: 'Vender mais e organizar o comercial.',
        dor: 'O stand precisa de vender mais, mas o comercial anda no caderno e no WhatsApp. As leads perdem-se e ninguém sabe ao certo o que resulta.',
        lead: 'Um funil comercial a sério, do primeiro contacto ao pós-venda.',
        features: [
            { icon: 'ri-car-line', title: 'Stock de viaturas', desc: 'Todo o inventário organizado num só lugar.' },
            { icon: 'ri-user-star-line', title: 'CRM e funil', desc: 'Leads e vendas ligadas a cada viatura.' },
            { icon: 'ri-money-euro-circle-line', title: 'Finanças', desc: 'Despesas, fornecedores e clientes controlados.' },
            { icon: 'ri-line-chart-line', title: 'Análise de marketing', desc: 'Meta Ads e tráfego do site (GA4) num painel.' },
            { icon: 'ri-customer-service-2-line', title: 'Pós-venda', desc: 'A relação com o cliente continua depois da venda.' },
        ],
        note: 'Da captação ao pós-venda, o comercial deixa de andar espalhado.',
    },
    {
        key: 'restauracao',
        label: 'Restauração',
        icon: 'ri-restaurant-2-line',
        accent: 'rgba(233, 172, 72, 0.7)',
        tagline: 'Operação e POS sem confusão.',
        dor: 'A operação está dispersa e o POS é antigo e confuso. Faturas em papel, reservas noutro sítio e nenhuma visão real do negócio.',
        lead: 'Tudo integrado, do balcão à gestão.',
        features: [
            { icon: 'ri-store-2-line', title: 'POS PingWin', desc: 'Integração GrupoPIE com as vendas e a operação.' },
            { icon: 'ri-calendar-check-line', title: 'Faturação por loja', desc: 'Calendário de faturação dia a dia, por restaurante.' },
            { icon: 'ri-file-list-3-line', title: 'Artigos e famílias', desc: 'Catálogo, famílias e unidades sincronizados.' },
            { icon: 'ri-scan-2-line', title: 'OCR de faturas', desc: 'Leitura automática de faturas de fornecedor por IA.' },
            { icon: 'ri-truck-line', title: 'Fornecedores e cadastros', desc: 'Documentos, fornecedores e registos base.' },
            { icon: 'ri-reserved-line', title: 'CoverManager', desc: 'Reservas, lotação da sala e ticket médio.' },
        ],
        note: 'Do balcão à gestão, uma operação inteira num só sistema.',
    },
];
