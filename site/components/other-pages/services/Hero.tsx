import Counter from "@/components/common/Counter";
import Image from "next/image";
import Link from "next/link";

export default function Hero() {
  return (
    <>
      {/* Section - Inner Page Headline Start */}
      <div className="mxd-section mxd-section-inner-headline padding-s-headline-pre-grid">
        <div className="mxd-container grid-container">
          {/* Block - Inner Page Headline Start */}
          <div className="mxd-block loading-wrap">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                {/* Inner Headline Name Start */}
                <div className="col-12 col-xl-2 mxd-grid-item no-margin">
                  <div className="mxd-block__name name-inner-headline loading__item">
                    <p className="mxd-point-subtitle">
                      <svg
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        x="0px"
                        y="0px"
                        width="20px"
                        height="20px"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                      >
                        <path
                          fill="currentColor"
                          d="M19.6,9.6c0,0-3,0-4,0c-0.4,0-1.8-0.2-1.8-0.2c-0.6-0.1-1.1-0.2-1.6-0.6c-0.5-0.3-0.9-0.8-1.2-1.2
                    c-0.3-0.4-0.4-0.9-0.5-1.4c0,0-0.1-1.1-0.2-1.5c-0.1-1.1,0-4.4,0-4.4C10.4,0.2,10.2,0,10,0S9.6,0.2,9.6,0.4c0,0,0.1,3.3,0,4.4
                    c0,0.4-0.2,1.5-0.2,1.5C9.4,6.7,9.2,7.2,9,7.6C8.7,8.1,8.2,8.5,7.8,8.9c-0.5,0.3-1,0.5-1.6,0.6c0,0-1.2,0.1-1.7,0.2
                    c-1,0.1-4.2,0-4.2,0C0.2,9.6,0,9.8,0,10c0,0.2,0.2,0.4,0.4,0.4c0,0,3.1-0.1,4.2,0c0.4,0,1.7,0.2,1.7,0.2c0.6,0.1,1.1,0.2,1.6,0.6
                    c0.4,0.3,0.8,0.7,1.1,1.1c0.3,0.5,0.5,1,0.6,1.6c0,0,0.1,1.3,0.2,1.7c0,1,0,4.1,0,4.1c0,0.2,0.2,0.4,0.4,0.4s0.4-0.2,0.4-0.4
                    c0,0,0-3.1,0-4.1c0-0.4,0.2-1.7,0.2-1.7c0.1-0.6,0.2-1.1,0.6-1.6c0.3-0.4,0.7-0.8,1.1-1.1c0.5-0.3,1-0.5,1.6-0.6
                    c0,0,1.3-0.1,1.8-0.2c1,0,4,0,4,0c0.2,0,0.4-0.2,0.4-0.4C20,9.8,19.8,9.6,19.6,9.6L19.6,9.6z"
                        />
                      </svg>
                      <span>Services</span>
                    </p>
                  </div>
                </div>
                {/* Inner Headline Name Start */}
                {/* Inner Headline Content Start */}
                <div className="col-12 col-xl-10 mxd-grid-item no-margin">
                  <div className="mxd-block__content">
                    <div className="mxd-block__inner-headline">
                      <h1 className="inner-headline__title headline-img-before headline-img-04 loading__item">
                        New standards in digital excellence
                      </h1>
                      {/* <p class="inner-headline__text t-large t-bright"></p> */}
                    </div>
                  </div>
                </div>
                {/* Inner Headline Content End */}
              </div>
            </div>
          </div>
          {/* Block - Inner Page Headline End */}
        </div>
      </div>
      {/* Section - Inner Page Headline End */}
      {/* Section - Inner Page Statistics Start */}
      <div className="mxd-section mxd-section-inner-stats overflow-hidden">
        <div className="mxd-container grid-container">
          {/* Block - Inner Page Statistics Start */}
          <div className="mxd-block">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                {/* Inner Block Name Start */}
                <div className="col-12 col-xl-2 mxd-grid-item no-margin" />
                {/* Inner Block Name Start */}
                {/* Inner Block Content Start */}
                <div className="col-12 col-xl-10">
                  <div className="mxd-block__content">
                    <div className="mxd-block__inner-stats">
                      <div className="mxd-stats-cards loading__fade">
                        <div className="container-fluid px-0">
                          <div className="row gx-0">
                            {/* item */}
                            <div className="col-12 col-xl-7 mxd-stats-cards__item mxd-grid-item">
                              <div className="mxd-stats-cards__inner bg-base-tint radius-m padding-4">
                                <div className="mxd-counter">
                                  <p
                                    id="stats-counter-3"
                                    className="mxd-counter__number mxd-stats-number"
                                  >
                                    <Counter max={5} />+
                                  </p>
                                  <p className="mxd-counter__descr t-140 t-bright">
                                    Years of professional experience in
                                    designing digital products
                                  </p>
                                </div>
                                <div className="mxd-stats-cards__btngroup">
                                  <Link
                                    className="btn btn-anim btn-default btn-outline slide-right-up"
                                    href={`/contact`}
                                  >
                                    <span className="btn-caption">
                                      Start New Project
                                    </span>
                                    <i className="ph-bold ph-arrow-up-right" />
                                  </Link>
                                </div>
                                <div className="mxd-stats-cards__image mxd-stats-cards-image-3">
                                  <Image
                                    alt="Illustration"
                                    src="/img/illustrations/800x800_card-image-03.webp"
                                    width={800}
                                    height={800}
                                  />
                                </div>
                              </div>
                            </div>
                            {/* item */}
                            <div className="col-12 col-xl-5 mxd-stats-cards__item mxd-grid-item">
                              <div className="mxd-stats-cards__inner bg-base-tint radius-m padding-4">
                                <div className="mxd-counter">
                                  <p
                                    id="stats-counter-4"
                                    className="mxd-counter__number mxd-stats-number"
                                  >
                                    <Counter max={70} />+
                                  </p>
                                  <p className="mxd-counter__descr t-140 t-bright">
                                    Successfully
                                    <br />
                                    completed projects
                                  </p>
                                </div>
                                <div className="mxd-stats-cards__btngroup">
                                  <Link
                                    className="btn btn-anim btn-default btn-outline slide-right-up"
                                    href={`/works-simple`}
                                  >
                                    <span className="btn-caption">Works</span>
                                    <i className="ph-bold ph-arrow-up-right" />
                                  </Link>
                                </div>
                                <div className="mxd-stats-cards__image mxd-stats-cards-image-4">
                                  <Image
                                    alt="Illustration"
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
                {/* Inner Block Content End */}
              </div>
            </div>
          </div>
          {/* Block - Inner Page Statistics End */}
        </div>
      </div>
      {/* Section - Inner Page Statistics End */}
    </>
  );
}
