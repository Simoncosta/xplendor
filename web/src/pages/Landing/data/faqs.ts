export interface FAQEntry {
    question: string;
    answer: string;
}

export const FAQS: FAQEntry[] = [
    {
        question: 'E se quiser testar antes de me comprometer?',
        answer:
            'Os primeiros 30 dias funcionam como período de avaliação. Se sentir que não é para si, sai sem custos adicionais para além do setup e da mensalidade do mês iniciado. Sem letra pequena.',
    },
    {
        question: 'Tenho de deixar os marketplaces?',
        answer:
            'Não. Pode reduzir progressivamente. Nos primeiros 90 dias mostramos os números para que decida com base em dados.',
    },
    {
        question: 'Quanto tempo até ver resultados?',
        answer:
            'Resultados mensuráveis em 60–90 dias. Configuração inicial em 2 semanas.',
    },
    {
        question: 'E se quiser sair?',
        answer:
            'Pré-aviso de 30 dias após o período mínimo de 6 meses. Sem fidelizações eternas.',
    },
    {
        question: 'Trabalham com stands fora de Portugal?',
        answer: 'Não. Foco exclusivo no mercado português.',
    },
    {
        question: 'Tenho de mudar o meu site actual?',
        answer:
            'Não é obrigatório, mas recomendado para tracking unificado. Avaliamos caso a caso.',
    },
];
