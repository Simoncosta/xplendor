import Link from "next/link";
import Image from "next/image";

import StackCards from "@/components/animation/StackCards";

export default function ServicesStack() {
  return (
    <div className="mxd-section padding-stacked-section">
      <div className="mxd-container grid-container">
        {/* Block - Services/Features Stacking Cards Start */}
        <div className="mxd-block mxd-grid-item no-margin">
          <div className="content__block">
            <StackCards className="stack-wrapper in-content-stack">
              {/* Pilar 1 — Plataforma própria */}

              <div className="mxd-services-stack__inner justify-between bg-base-opp">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-additional slide-right-up anim-no-delay"
                    href={`/servicos`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3 className="opposite">Plataforma</h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline-opposite">
                      Stock
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Faturação
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Vendas
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Relatórios
                    </span>
                  </div>
                  <p className="t-small-mobile t-opposite">
                    Uma plataforma feita à medida do seu setor para gerir o
                    negócio num só lugar: stock, faturação, vendas e relatórios.
                    Tecnologia que trabalha por si, todos os dias.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Plataforma própria XPLENDOR"
                    src="/img/services/800x800_ser-01.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Plataforma própria XPLENDOR"
                    src="/img/services/1000x1000_ser-01.webp"
                    width={1000}
                    height={1000}
                  />
                </div>
              </div>

              {/* Pilar 2 — Social Media */}

              <div className="mxd-services-stack__inner justify-between bg-accent">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-base slide-right-up anim-no-delay"
                    href={`/servicos`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3 className="opposite">Social Media</h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline-opposite">
                      Conteúdo
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Planeamento
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Marca
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Gestão
                    </span>
                  </div>
                  <p className="t-small-mobile t-opposite">
                    Gestão profissional das suas redes sociais, com conteúdo,
                    planeamento e uma presença que constrói marca e atrai
                    clientes. Estratégia com rosto humano, não posts automáticos.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Gestão de social media XPLENDOR"
                    src="/img/services/800x800_ser-02.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Gestão de social media XPLENDOR"
                    src="/img/services/1000x1000_ser-02.webp"
                    width={1000}
                    height={1000}
                  />
                </div>
              </div>

              {/* Pilar 3 — Tráfego Pago */}

              <div className="mxd-services-stack__inner radius-dark justify-between bg-base-tint">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-opposite slide-right-up anim-no-delay"
                    href={`/servicos`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3>Tráfego Pago</h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline">
                      Google Ads
                    </span>
                    <span className="tag tag-default tag-outline">
                      Meta Ads
                    </span>
                    <span className="tag tag-default tag-outline">
                      Campanhas
                    </span>
                    <span className="tag tag-default tag-outline">
                      Resultados
                    </span>
                  </div>
                  <p className="t-small-mobile">
                    Campanhas de anúncios no Google e Meta pensadas para vender,
                    não para gastar. Cada euro investido com estratégia, medição
                    e resultados que se acompanham.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Tráfego pago XPLENDOR"
                    src="/img/services/800x800_ser-03.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Tráfego pago XPLENDOR"
                    src="/img/services/1000x1000_ser-03.webp"
                    width={1000}
                    height={1000}
                  />
                </div>
              </div>

              {/* Pilar 4 — Consultoria de Tecnologia */}

              <div className="mxd-services-stack__inner justify-between bg-base-opp">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-additional slide-right-up anim-no-delay"
                    href={`/servicos`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3 className="opposite">Tecnologia</h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline-opposite">
                      Websites
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Aplicações
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      ERP
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Consultoria
                    </span>
                  </div>
                  <p className="t-small-mobile t-opposite">
                    Do desenvolvimento de aplicações e websites à consultoria de
                    ERP. A tecnologia certa para o seu negócio crescer, com quem
                    percebe do assunto do princípio ao fim.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Consultoria de tecnologia XPLENDOR"
                    src="/img/services/800x800_ser-04.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Consultoria de tecnologia XPLENDOR"
                    src="/img/services/1000x1000_ser-04.webp"
                    width={1000}
                    height={1000}
                  />
                </div>
              </div>
            </StackCards>
          </div>
        </div>
        {/* Block - Services/Features Stacking Cards End */}
      </div>
    </div>
  );
}
