import Link from "next/link";
import Image from "next/image";

import StackCards from "@/components/animation/StackCards";

export default function Services() {
  return (
    <div className="mxd-section padding-stacked-section">
      <div className="mxd-container grid-container">
        {/* Block - Services/Features Stacking Cards Start */}
        <div className="mxd-block mxd-grid-item no-margin">
          <div className="content__block">
            <StackCards className="stack-wrapper">
              <div className="mxd-services-stack__inner justify-between bg-base-opp">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-additional slide-right-up anim-no-delay"
                    href={`/services`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3 className="opposite">Digital art and graphic design</h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline-opposite">
                      UI/UX
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Web design
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Packaging
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Motion
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      3D models
                    </span>
                  </div>
                  <p className="t-opposite">
                    We create visually compelling designs that enhance user
                    experience. We make sure your brand&apos;s visuals resonate
                    with your audience.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Service/Feature Image"
                    src="/img/services/800x800_ser-01.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Service/Feature Image"
                    src="/img/services/1000x1000_ser-01.webp"
                    width={1000}
                    height={1000}
                  />
                </div>
              </div>

              {/* services stack single item */}

              <div className="mxd-services-stack__inner justify-between bg-accent">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-base slide-right-up anim-no-delay"
                    href={`/services`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3 className="opposite">
                    Creative
                    <br />
                    development
                  </h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline-opposite">
                      Frontend
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Interactions
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Backend
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Mobile Apps
                    </span>
                  </div>
                  <p className="t-opposite">
                    We build high-performance websites and applications using
                    modern technologies. Our solutions are designed to be
                    scalable and functional for optimal performance.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Service/Feature Image"
                    src="/img/services/800x800_ser-02.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Service/Feature Image"
                    src="/img/services/1000x1000_ser-02.webp"
                    width={1000}
                    height={1000}
                  />
                </div>
              </div>

              {/* services stack single item */}

              <div className="mxd-services-stack__inner radius-dark justify-between bg-base-tint">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-opposite slide-right-up anim-no-delay"
                    href={`/services`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3>
                    Brand
                    <br />
                    identity
                  </h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline">
                      Brand strategy
                    </span>
                    <span className="tag tag-default tag-outline">
                      Logo design
                    </span>
                    <span className="tag tag-default tag-outline">
                      Guidelines
                    </span>
                    <span className="tag tag-default tag-outline">
                      Rebranding
                    </span>
                  </div>
                  <p>
                    From logo design to comprehensive brand strategies, we
                    ensure your business stands out with a unique visual
                    identity and consistent messaging across all touchpoints.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Service/Feature Image"
                    src="/img/services/800x800_ser-03.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Service/Feature Image"
                    src="/img/services/1000x1000_ser-03.webp"
                    width={1000}
                    height={1000}
                  />
                </div>
              </div>

              {/* services stack single item */}

              <div className="mxd-services-stack__inner justify-between bg-base-opp">
                <div className="mxd-services-stack__controls">
                  <Link
                    className="btn btn-round btn-round-large btn-additional slide-right-up anim-no-delay"
                    href={`/services`}
                  >
                    <i className="ph ph-arrow-up-right" />
                  </Link>
                </div>
                <div className="mxd-services-stack__title width-60">
                  <h3 className="opposite">
                    Digital marketing
                    <br />
                    solutions
                  </h3>
                </div>
                <div className="mxd-services-stack__info width-60">
                  <div className="mxd-services-cards__tags">
                    <span className="tag tag-default tag-outline-opposite">
                      Strategy
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Social media
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      SEO
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Optimization
                    </span>
                    <span className="tag tag-default tag-outline-opposite">
                      Campaigns
                    </span>
                  </div>
                  <p className="t-opposite">
                    SEO and content marketing, social media management and paid
                    campaigns - we help you reach and engage your target
                    audience effectively.
                  </p>
                </div>
                <div className="services-stack__image">
                  <Image
                    className="service-img service-img-s"
                    alt="Service/Feature Image"
                    src="/img/services/800x800_ser-04.webp"
                    width={800}
                    height={800}
                  />
                  <Image
                    className="service-img service-img-m"
                    alt="Service/Feature Image"
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
