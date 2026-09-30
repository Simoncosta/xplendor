import Cta from "@/components/common/Cta";
import Counter from "@/components/common/Counter";
import Footer2 from "@/components/footers/Footer2";
import Image from "next/image";
import Link from "next/link";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Serviços | XPLENDOR",
  description:
    "Os quatro pilares da XPLENDOR: plataforma própria, social media, tráfego pago e consultoria de tecnologia. Marketing e tecnologia que trabalham juntos.",
};

// Os 4 pilares desenvolvidos: parágrafo de valor + o que inclui.
const PILLARS: { title: string; paragraph: string; includes: string[] }[] = [
  {
    title: "Plataforma",
    paragraph:
      "Uma plataforma feita à medida do seu setor para gerir o negócio num só lugar. Tecnologia que trabalha por si, todos os dias, com tudo o que precisa à mão.",
    includes: [
      "Gestão de stock",
      "Faturação",
      "Vendas",
      "Relatórios",
      "Adaptada ao seu setor",
    ],
  },
  {
    title: "Social Media",
    paragraph:
      "Gestão profissional das suas redes sociais, com estratégia e rosto humano. Construímos uma presença que fortalece a marca e atrai clientes, sem posts automáticos e sem fórmulas iguais para todos.",
    includes: [
      "Gestão de Instagram e Facebook",
      "Criação de conteúdo",
      "Calendário editorial",
      "Design de publicações",
      "Relatórios de desempenho",
    ],
  },
  {
    title: "Tráfego Pago",
    paragraph:
      "Campanhas de anúncios pensadas para vender, não para gastar. Cada euro investido com estratégia, medição e resultados que se acompanham de perto.",
    includes: [
      "Campanhas Google Ads",
      "Campanhas Meta (Facebook e Instagram)",
      "Gestão de orçamento",
      "Otimização contínua",
      "Relatórios de resultados",
    ],
  },
  {
    title: "Consultoria de Tecnologia",
    paragraph:
      "A tecnologia certa para o seu negócio crescer, com quem percebe do assunto do princípio ao fim. Do desenvolvimento à consultoria, acompanhamos cada etapa.",
    includes: [
      "Desenvolvimento de websites",
      "Desenvolvimento de aplicações",
      "Consultoria de ERP",
      "Integrações",
      "Consultoria Tecnológica",
    ],
  },
];

export default function ServicosPage() {
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
                        <span>Serviços</span>
                      </p>
                    </div>
                  </div>
                  <div className="col-12 col-xl-10 mxd-grid-item no-margin">
                    <div className="mxd-block__content">
                      <div className="mxd-block__inner-headline">
                        <h1 className="inner-headline__title headline-img-before headline-img-04 loading__item">
                          Marketing e tecnologia que trabalham juntos
                        </h1>
                        <p className="inner-headline__text t-large t-bright loading__item">
                          Quatro pilares, uma só equipa. Do primeiro contacto ao
                          resultado, com quem percebe do assunto do princípio ao
                          fim.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Secção de estatísticas (números reais) */}
        <div className="mxd-section mxd-section-inner-stats overflow-hidden padding-default">
          <div className="mxd-container grid-container">
            <div className="mxd-block">
              <div className="container-fluid px-0">
                <div className="row gx-0">
                  <div className="col-12 col-xl-2 mxd-grid-item no-margin" />
                  <div className="col-12 col-xl-10">
                    <div className="mxd-block__content">
                      <div className="mxd-block__inner-stats">
                        <div className="mxd-stats-cards loading__fade">
                          <div className="container-fluid px-0">
                            <div className="row gx-0">
                              {/* cartão 1 */}
                              <div className="col-12 col-xl-7 mxd-stats-cards__item mxd-grid-item">
                                <div className="mxd-stats-cards__inner bg-base-tint radius-m padding-4">
                                  <div className="mxd-counter">
                                    <p className="mxd-counter__number mxd-stats-number">
                                      <Counter max={3} />+
                                    </p>
                                    <p className="mxd-counter__descr t-140 t-bright">
                                      Anos no mercado a fortalecer negócios
                                    </p>
                                  </div>
                                  <div className="mxd-stats-cards__btngroup">
                                    <Link
                                      className="btn btn-anim btn-default btn-outline slide-right-up"
                                      href={`/contact`}
                                    >
                                      <span className="btn-caption">
                                        Vamos falar
                                      </span>
                                      <i className="ph-bold ph-arrow-up-right" />
                                    </Link>
                                  </div>
                                  <div className="mxd-stats-cards__image mxd-stats-cards-image-3">
                                    <Image
                                      alt="Ilustração"
                                      src="/img/illustrations/800x800_card-image-03.webp"
                                      width={800}
                                      height={800}
                                    />
                                  </div>
                                </div>
                              </div>
                              {/* cartão 2 */}
                              <div className="col-12 col-xl-5 mxd-stats-cards__item mxd-grid-item">
                                <div className="mxd-stats-cards__inner bg-base-tint radius-m padding-4">
                                  <div className="mxd-counter">
                                    <p className="mxd-counter__number mxd-stats-number">
                                      <Counter max={10} />+
                                    </p>
                                    <p className="mxd-counter__descr t-140 t-bright">
                                      Clientes e projetos entregues
                                    </p>
                                  </div>
                                  <div className="mxd-stats-cards__image mxd-stats-cards-image-4">
                                    <Image
                                      alt="Ilustração"
                                      src="/img/illustrations/800x800_card-image-04.webp"
                                      width={800}
                                      height={800}
                                    />
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Os 4 pilares */}
        <div className="mxd-section padding-default">
          <div className="mxd-container grid-container">
            <div className="mxd-block">
              {PILLARS.map((p, idx) => (
                <div key={idx} className="mxd-approach-list__item">
                  <div className="mxd-approach-list__border anim-uni-in-up" />
                  <div className="mxd-approach-list__inner">
                    <div className="container-fluid px-0">
                      <div className="row gx-0">
                        <div className="col-12 col-xl-4 mxd-grid-item no-margin">
                          <div className="mxd-approach-list__title anim-uni-in-up">
                            <h2>{p.title}</h2>
                          </div>
                        </div>
                        <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                          <div className="mxd-approach-list__descr anim-uni-in-up">
                            <p className="t-large t-bright">{p.paragraph}</p>
                            <p className="t-caption xp-includes__label">
                              O que inclui
                            </p>
                            <ul className="xp-includes">
                              {p.includes.map((item, i) => (
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

        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
