import BrowserMockup from "@/components/common/BrowserMockup";
import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Plataforma | XPLENDOR",
  description:
    "A plataforma que gere o seu negócio num só lugar, feita à medida do seu setor: autocaravanas, carros e restauração.",
};

// MÓDULOS — screenshots reais em /img/plataforma/. Textos a afinar pelo utilizador.
const MODULES: { title: string; text: string; img: string }[] = [
  {
    title: "Dashboard",
    text: "Uma visão geral do negócio num só ecrã: vendas, stock e indicadores essenciais sempre à mão, para decidir com dados e não por intuição.",
    img: "/img/plataforma/dashboard.png",
  },
  {
    title: "Stock",
    text: "Todo o inventário organizado e atualizado. Saiba o que tem, o que falta e o que se move, sem folhas de cálculo nem surpresas.",
    img: "/img/plataforma/stock.png",
  },
  {
    title: "Leads e comercial",
    text: "Todos os contactos e oportunidades num só lugar, do primeiro interesse à venda. Acompanhe cada lead e não deixe negócios por fechar.",
    img: "/img/plataforma/leads.png",
  },
  {
    title: "Documentos",
    text: "Faturas, propostas e documentos gerados e guardados automaticamente, prontos a enviar. Menos burocracia, mais tempo para vender.",
    img: "/img/plataforma/documentos.png",
  },
  {
    title: "Ficha",
    text: "A ficha completa de cada artigo ou veículo, com toda a informação centralizada e acessível a toda a equipa.",
    img: "/img/plataforma/ficha.png",
  },
  {
    title: "Pós-venda",
    text: "O acompanhamento não acaba na venda. Faça a gestão do pós-venda e da satisfação do cliente para fidelizar e voltar a vender.",
    img: "/img/plataforma/pos-venda.png",
  },
];

// 3 RAMOS — mesmo padrão da /servicos (título + parágrafo + o que inclui). A afinar.
const BRANCHES: { title: string; paragraph: string; includes: string[] }[] = [
  {
    title: "Autocaravanas",
    paragraph:
      "Gestão completa do stock de autocaravanas, do anúncio à venda. Ligação à API de stock, gestão de veículos e captação de leads, tudo integrado.",
    includes: [
      "API de stock",
      "Gestão de veículos",
      "Leads e comercial",
      "Fichas de veículo",
    ],
  },
  {
    title: "Carros",
    paragraph:
      "A mesma força para o stand automóvel: inventário de viaturas sempre atualizado, gestão de leads e todo o processo comercial num só sítio.",
    includes: [
      "API de stock",
      "Gestão de viaturas",
      "Leads e comercial",
      "Documentos de venda",
    ],
  },
  {
    title: "Restaurante",
    paragraph:
      "Ligação direta ao PingWin BO para gerir o restaurante de ponta a ponta: stock, faturação e vendas sincronizados, sem trabalho duplicado.",
    includes: [
      "Conexão PingWin BO",
      "Gestão de stock",
      "Faturação",
      "Vendas",
    ],
  },
];

// 6 CASOS — imagens reais em site/public/img/casos/ (nomes de ficheiro exatos).
// A UZI distingue-se: é um portal tecnológico nosso, não um site de cliente.
// Textos a afinar pelo utilizador.
const CASES: {
  name: string;
  descr: string;
  img: string;
  label: string;
}[] = [
  {
    name: "Yuko",
    descr: "Restaurante com presença digital e gestão integrada.",
    img: "/img/casos/yuko.png",
    label: "Website",
  },
  {
    name: "BS Caixilharia",
    descr: "Website para o setor da caixilharia e construção.",
    img: "/img/casos/bscaixilharia.png",
    label: "Website",
  },
  {
    name: "Confidere",
    descr: "Presença digital para uma marca de serviços.",
    img: "/img/casos/confidere.png",
    label: "Website",
  },
  {
    name: "Domiway",
    descr: "Website e presença online à medida do negócio.",
    img: "/img/casos/domiway.png",
    label: "Website",
  },
  {
    name: "QueBom",
    descr: "Restauração com gestão integrada via PingWin.",
    img: "/img/casos/quebom.png",
    label: "Website",
  },
  {
    name: "UZI",
    descr:
      "Um portal tecnológico que desenvolvemos de raiz. Além de sites de clientes, criamos produtos e plataformas próprias.",
    img: "/img/casos/uzierp.png",
    label: "Portal tecnológico",
  },
];

export default function PlataformaPage() {
  return (
    <>
      <main
        id="mxd-page-content"
        className="mxd-page-content inner-page-content"
      >
        {/* Cabeçalho */}
        <div className="mxd-section mxd-section-inner-headline padding-s-headline-pre-grid">
          <div className="mxd-container grid-container">
            <div className="mxd-block loading-wrap">
              <div className="container-fluid px-0">
                <div className="row gx-0">
                  <div className="col-12 col-xl-2 mxd-grid-item no-margin">
                    <div className="mxd-block__name name-inner-headline loading__item">
                      <p className="mxd-point-subtitle">
                        <span>Plataforma</span>
                      </p>
                    </div>
                  </div>
                  <div className="col-12 col-xl-10 mxd-grid-item no-margin">
                    <div className="mxd-block__content">
                      <div className="mxd-block__inner-headline">
                        <h1 className="inner-headline__title loading__item">
                          A plataforma que gere o seu negócio num só lugar
                        </h1>
                        <p className="inner-headline__text t-large t-bright loading__item">
                          Uma plataforma feita à medida do seu setor. Autocaravanas,
                          carros ou restauração: tudo o que precisa para gerir o
                          negócio, num só sítio e a trabalhar por si.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Módulos da plataforma */}
        <div className="mxd-section padding-default">
          <div className="mxd-container grid-container">
            <div className="mxd-block">
              <div className="xp-modules">
                {MODULES.map((m, idx) => (
                  <div className="xp-modules__item" key={idx}>
                    <div className="xp-modules__media anim-uni-in-up">
                      <BrowserMockup src={m.img} alt={`Módulo ${m.title}`} />
                    </div>
                    <div className="xp-modules__body">
                      <h2 className="xp-modules__title anim-uni-in-up">
                        {m.title}
                      </h2>
                      <p className="xp-modules__text t-large anim-uni-in-up">
                        {m.text}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        {/* Os 3 ramos */}
        <div className="mxd-section padding-default">
          <div className="mxd-container grid-container">
            <div className="mxd-block">
              <div className="mxd-section-title no-margin-desktop">
                <div className="mxd-section-title__title anim-uni-in-up">
                  <h2>Um setor, uma plataforma</h2>
                </div>
              </div>
            </div>
            <div className="mxd-block">
              {BRANCHES.map((b, idx) => (
                <div key={idx} className="mxd-approach-list__item">
                  <div className="mxd-approach-list__border anim-uni-in-up" />
                  <div className="mxd-approach-list__inner">
                    <div className="container-fluid px-0">
                      <div className="row gx-0">
                        <div className="col-12 col-xl-4 mxd-grid-item no-margin">
                          <div className="mxd-approach-list__title anim-uni-in-up">
                            <h3>{b.title}</h3>
                          </div>
                        </div>
                        <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                          <div className="mxd-approach-list__descr anim-uni-in-up">
                            <p className="t-large t-bright">{b.paragraph}</p>
                            <p className="t-caption xp-includes__label">
                              O que inclui
                            </p>
                            <ul className="xp-includes">
                              {b.includes.map((item, i) => (
                                <li key={i}>
                                  <i className="ph-bold ph-check" />
                                  <span>{item}</span>
                                </li>
                              ))}
                            </ul>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div className="mxd-approach-list__border anim-uni-in-up" />
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Os 6 casos */}
        <div className="mxd-section padding-default">
          <div className="mxd-container grid-container">
            <div className="mxd-block">
              <div className="mxd-section-title no-margin-desktop">
                <div className="mxd-section-title__title anim-uni-in-up">
                  <h2>Casos reais</h2>
                </div>
                <div className="mxd-section-title__descr anim-uni-in-up">
                  <p>Negócios que já trabalham connosco.</p>
                </div>
              </div>
            </div>
            <div className="mxd-block">
              <div className="xp-cases">
                {CASES.map((c, idx) => (
                  <div
                    className={`xp-case anim-uni-in-up ${
                      c.label === "Portal tecnológico" ? "xp-case--featured" : ""
                    }`}
                    key={idx}
                  >
                    <BrowserMockup src={c.img} alt={`Caso ${c.name}`} />
                    <div className="xp-case__meta">
                      <h3 className="xp-case__name">{c.name}</h3>
                      <span className="xp-case__badge">{c.label}</span>
                    </div>
                    <p className="xp-case__descr">{c.descr}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
