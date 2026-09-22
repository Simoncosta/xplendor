// Conteúdo BASE dos 2 ramos — fiel ao que o produto faz (ModuleRegistry + páginas
// reais). O Simon refina a mensagem/tom depois. NÃO inventar features inexistentes.

export interface RamoFeature {
    icon: string;   // remixicon (ri-*) — já carregado globalmente pelo Velzon
    title: string;
    desc: string;
}

/** Automotivo — stands de carros e autocaravanas (módulos car_specific + transversais). */
export const AUTOMOTIVO: RamoFeature[] = [
    { icon: 'ri-car-line', title: 'Stock de viaturas', desc: 'Carros e autocaravanas, com fichas e impressão A4.' },
    { icon: 'ri-user-star-line', title: 'CRM de leads', desc: 'Leads e vendas ligadas a cada viatura.' },
    { icon: 'ri-customer-service-2-line', title: 'Pós-venda', desc: 'Acompanhamento e relatório de satisfação do cliente.' },
    { icon: 'ri-line-chart-line', title: 'Análise de marketing', desc: 'Meta Ads e tráfego do site (GA4).' },
    { icon: 'ri-money-euro-circle-line', title: 'Finanças', desc: 'Fornecedores, clientes, despesas e categorias.' },
    { icon: 'ri-radar-line', title: 'Mercado de autocaravanas', desc: 'Análise de preço comparável do mercado.' },
];

/** Restauração — restaurantes com PingWin (POS GrupoPIE) + 8 secções + CoverManager. */
export const RESTAURACAO: RamoFeature[] = [
    { icon: 'ri-store-2-line', title: 'POS PingWin', desc: 'Integração GrupoPIE com as vendas e a operação.' },
    { icon: 'ri-calendar-check-line', title: 'Faturação por loja', desc: 'Calendário de faturação dia a dia, por restaurante.' },
    { icon: 'ri-file-list-3-line', title: 'Artigos e famílias', desc: 'Catálogo, famílias e unidades sincronizados.' },
    { icon: 'ri-scan-2-line', title: 'OCR de faturas', desc: 'Leitura automática de faturas de fornecedor por IA.' },
    { icon: 'ri-reserved-line', title: 'Reservas CoverManager', desc: 'Reservas, lotação da sala e ticket médio.' },
    { icon: 'ri-truck-line', title: 'Fornecedores e cadastros', desc: 'Documentos, fornecedores e registos base.' },
];

/** Capacidades transversais — comuns aos dois ramos. */
export const TRANSVERSAIS: RamoFeature[] = [
    { icon: 'ri-apps-2-line', title: 'Módulos por empresa', desc: 'Cada empresa ativa só o que precisa e o menu adapta-se ao ramo.' },
    { icon: 'ri-line-chart-line', title: 'Marketing e tráfego', desc: 'Dados de Meta Ads e GA4 num único painel de leitura.' },
    { icon: 'ri-folder-3-line', title: 'Documentos', desc: 'Templates e documentos ao nível da empresa.' },
    { icon: 'ri-checkbox-multiple-line', title: 'Suporte e tarefas', desc: 'Tickets e um Kanban interno partilhado pela equipa.' },
    { icon: 'ri-shield-check-line', title: 'Multi-empresa seguro', desc: 'Cada empresa vê apenas os seus próprios dados.' },
    { icon: 'ri-smartphone-line', title: 'App instalável (PWA)', desc: 'Acede pelo browser ou instala como aplicação.' },
];

/** Serviços de agência — a XPLENDOR também presta serviços, não é só software. */
export const SERVICOS: RamoFeature[] = [
    { icon: 'ri-macbook-line', title: 'Criação de sites', desc: 'Sites rápidos e modernos, prontos para converter visitas em clientes.' },
    { icon: 'ri-megaphone-line', title: 'Tráfego pago', desc: 'Campanhas de Meta Ads e Google Ads geridas para trazer leads reais.' },
    { icon: 'ri-instagram-line', title: 'Social media', desc: 'Gestão de redes sociais com conteúdo que dá cara à tua marca.' },
    { icon: 'ri-flow-chart', title: 'Automações', desc: 'Fluxos automáticos que poupam horas de trabalho manual à equipa.' },
];
