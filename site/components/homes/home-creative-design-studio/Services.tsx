import Image from "next/image";
import Link from "next/link";

export default function Services() {
  return (
    <div className="mxd-section overflow-hidden padding-pre-title">
      <div className="mxd-container grid-container">
        {/* Block - Services Cards #01 Start */}
        <div className="mxd-block">
          <div className="mxd-services-cards">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                {/* item */}
                <div className="col-12 col-xl-7 mxd-services-cards__item mxd-grid-item anim-uni-scale-in-right">
                  <div className="mxd-services-cards__inner align-end bg-base-opp radius-l padding-5x4">
                    <div className="mxd-services-cards__controls">
                      <Link
                        className="btn btn-round btn-round-large btn-additional slide-right-up anim-no-delay"
                        href={`/services`}
                      >
                        <i className="ph-bold ph-arrow-up-right" />
                      </Link>
                    </div>
                    <div className="mxd-services-cards__title">
                      <p className="opposite anim-uni-in-up">Graphic design</p>
                    </div>
                    <div className="mxd-services-cards__info">
                      <div className="mxd-services-cards__tags">
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          UI/UX
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Web design
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Packaging
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Motion
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          3D models
                        </span>
                      </div>
                      <p className="t-opposite anim-uni-in-up">
                        We create visually compelling designs that enhance user
                        experience. We make sure your brand’s visuals resonate
                        with your audience.
                      </p>
                    </div>
                    <div className="mxd-services-cards__image mxd-services-cards-image-1">
                      <Image
                        alt="Illustration"
                        src="/img/illustrations/800x800_service-image-01.webp"
                        width={800}
                        height={800}
                      />
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 col-xl-5 mxd-services-cards__item mxd-grid-item anim-uni-scale-in-left">
                  <div className="mxd-services-cards__inner align-end bg-accent radius-l padding-5x4">
                    <div className="mxd-services-cards__controls">
                      <Link
                        className="btn btn-round btn-round-large btn-base slide-right-up anim-no-delay"
                        href={`/services`}
                      >
                        <i className="ph-bold ph-arrow-up-right" />
                      </Link>
                    </div>
                    <div className="mxd-services-cards__title">
                      <p className="opposite anim-uni-in-up">
                        Creative development
                      </p>
                    </div>
                    <div className="mxd-services-cards__info">
                      <div className="mxd-services-cards__tags">
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Frontend
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Interactions
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Backend
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Mobile Apps
                        </span>
                      </div>
                      <p className="t-opposite anim-uni-in-up">
                        We build high-performance websites and applications
                        using modern technologies. Our solutions are designed to
                        be scalable and functional for optimal performance.
                      </p>
                    </div>
                    <div className="mxd-services-cards__image mxd-services-cards-image-2">
                      <Image
                        alt="Illustration"
                        src="/img/illustrations/800x800_service-image-02.webp"
                        width={800}
                        height={800}
                      />
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 mxd-services-cards__item mxd-grid-item anim-uni-scale-in">
                  <div className="mxd-services-cards__inner justify-between bg-base-tint radius-l padding-5x4">
                    <div className="mxd-services-cards__controls">
                      <Link
                        className="btn btn-round btn-round-large btn-opposite slide-right-up anim-no-delay"
                        href={`/services`}
                      >
                        <i className="ph-bold ph-arrow-up-right" />
                      </Link>
                    </div>
                    <div className="mxd-services-cards__title width-60">
                      <p className="anim-uni-in-up">
                        Digital marketing solutions
                      </p>
                    </div>
                    <div className="mxd-services-cards__info width-60">
                      <div className="mxd-services-cards__tags">
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Strategy
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Social media
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          SEO
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Optimization
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Campaigns
                        </span>
                      </div>
                      <p className="anim-uni-in-up">
                        SEO and content marketing, social media management and
                        paid campaigns - we help you reach and engage your
                        target audience effectively.
                      </p>
                    </div>
                    <div className="mxd-services-cards__image mxd-services-cards-image-3">
                      <Image
                        className="image-1"
                        alt="Illustration"
                        src="/img/illustrations/800x800_service-image-03.webp"
                        width={800}
                        height={800}
                      />
                      <Image
                        className="image-2"
                        alt="Illustration"
                        src="/img/illustrations/800x800_service-image-03.webp"
                        width={800}
                        height={800}
                      />
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 col-xl-5 mxd-services-cards__item mxd-grid-item anim-uni-scale-in-right">
                  <div className="mxd-services-cards__inner justify-end bg-base-opp radius-l padding-5x4">
                    <div className="mxd-services-cards__controls">
                      <Link
                        className="btn btn-round btn-round-large btn-additional slide-right-up anim-no-delay"
                        href={`/services`}
                      >
                        <i className="ph-bold ph-arrow-up-right" />
                      </Link>
                    </div>
                    <div className="mxd-services-cards__title">
                      <p className="opposite anim-uni-in-up">
                        Brand
                        <br />
                        identity
                      </p>
                    </div>
                    <div className="mxd-services-cards__info">
                      <div className="mxd-services-cards__tags">
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Brand strategy
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Logo design
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Guidelines
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Rebranding
                        </span>
                      </div>
                      <p className="t-opposite anim-uni-in-up">
                        From logo design to comprehensive brand strategies, we
                        ensure your business stands out with a unique visual
                        identity and consistent messaging across all
                        touchpoints.
                      </p>
                    </div>
                    <div className="mxd-services-cards__image mxd-services-cards-image-4">
                      <Image
                        alt="Illustration"
                        src="/img/illustrations/800x800_service-image-04.webp"
                        width={800}
                        height={800}
                      />
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 col-xl-7 mxd-services-cards__item mxd-grid-item anim-uni-scale-in-left">
                  <div className="mxd-services-cards__inner justify-between bg-accent radius-l padding-5x4">
                    <div className="mxd-services-cards__controls">
                      <Link
                        className="btn btn-round btn-round-large btn-base slide-right-up anim-no-delay"
                        href={`/services`}
                      >
                        <i className="ph-bold ph-arrow-up-right" />
                      </Link>
                    </div>
                    <div className="mxd-services-cards__title width-70">
                      <p className="opposite anim-uni-in-up">
                        Long-lasting
                        <br />
                        support
                      </p>
                    </div>
                    <div className="mxd-services-cards__info width-70">
                      <div className="mxd-services-cards__tags">
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          E-Commerce
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Maintenance
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Support
                        </span>
                      </div>
                      <p className="t-opposite anim-uni-in-up">
                        We take the time to understand your unique needs and
                        create tailored design solutions to help you make an
                        impact.
                      </p>
                    </div>
                    <div className="mxd-services-cards__image mxd-services-cards-image-5">
                      <Image
                        alt="Illustration"
                        src="/img/illustrations/800x800_service-image-05.webp"
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
        {/* Block - Services Cards #01 End */}
      </div>
    </div>
  );
}
