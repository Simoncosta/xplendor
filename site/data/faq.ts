/**
 * Perguntas frequentes da página Serviços (e do JSON-LD FAQPage e do llms.txt).
 *
 * [A VALIDAR] Todas são RASCUNHOS: confirmar com a equipa antes do deploy. Cada
 * resposta começa pela resposta direta, sem números, prazos ou promessas que não
 * estejam confirmados. Quando uma estiver validada, mudar `validated` para true.
 */
export interface FaqItem {
  question: string;
  answer: string;
  validated: boolean;
}

export const SERVICES_FAQ: FaqItem[] = [
  {
    question: "O que faz a XPLENDOR?",
    answer:
      "A XPLENDOR junta marketing e tecnologia na mesma equipa: gerimos redes sociais e campanhas de anúncios, desenvolvemos websites e aplicações e temos uma plataforma própria de gestão. Trabalhamos com empresas em Portugal.",
    validated: false,
  },
  {
    question: "Trabalham só com stands de automóveis?",
    answer:
      "Não. A plataforma tem versões feitas para autocaravanas, carros e restauração, e os serviços de social media, tráfego pago e websites servem empresas de outros setores.",
    validated: false,
  },
  {
    question: "O orçamento de anúncios está incluído no preço da gestão de campanhas?",
    answer:
      "Não. O valor investido em anúncios é pago diretamente pelo cliente às plataformas (Google e Meta); o nosso preço corresponde à gestão das campanhas.",
    validated: false,
  },
  {
    question: "Posso contratar só um serviço?",
    answer:
      "Sim. Cada serviço pode ser contratado em separado, e também pode juntar vários, por exemplo social media e tráfego pago.",
    validated: false,
  },
  {
    question: "Os preços incluem IVA?",
    answer:
      "Não. Os valores dos nossos orçamentos são apresentados sem IVA, que acresce à taxa legal em vigor.",
    validated: false,
  },
  {
    question: "Existe um período mínimo de contrato?",
    answer:
      "Nos serviços mensais pode existir um contrato mínimo, indicado em cada orçamento. Os serviços de valor único, como um website, não têm mensalidade.",
    validated: false,
  },
  {
    question: "Como peço um orçamento?",
    answer:
      "Basta contactar-nos pelo formulário da página Contacto, por email ou por telefone. Depois de percebermos o que precisa, enviamos um orçamento com os valores mensais e de valor único separados.",
    validated: false,
  },
  {
    question: "A plataforma liga-se a outras ferramentas que já uso?",
    answer:
      "Sim, em parte. A plataforma já se liga a ferramentas como o Google Analytics, a Meta e programas de faturação e reservas usados na restauração; outras integrações avaliamos caso a caso.",
    validated: false,
  },
];
