import Image from "next/image";

export default function FeatureCards() {
  return (
    <div className="mxd-section overflow-hidden">
      <div className="mxd-container grid-container">
        {/* Block - Features Cards Start */}
        <div className="mxd-block">
          <div className="mxd-features-cards">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                {/* item */}
                <div className="col-12 col-xl-8 mxd-features-cards__item features-item-01 mxd-grid-item anim-uni-scale-in-right">
                  <div className="mxd-features-cards__inner justify-between bg-base-tint radius-l padding-4">
                    <div className="mxd-features-cards__gradient features-gradient-01">
                      <svg
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 1200 1200"
                      >
                        <style
                          type="text/css"
                          dangerouslySetInnerHTML={{
                            __html:
                              "\n                            .mxd-card-bg {\n                              fill: url(#purple-radial-grad);\n                            }\n                          ",
                          }}
                        />
                        <g>
                          <radialGradient
                            id="purple-radial-grad"
                            cx={600}
                            cy={600}
                            r={600}
                            gradientUnits="userSpaceOnUse"
                          >
                            <stop
                              offset={0}
                              style={{
                                stopColor: "#9f8be7",
                                stopOpacity: "0.6",
                              }}
                            />
                            <stop
                              offset={1}
                              style={{ stopColor: "#9f8be7", stopOpacity: 0 }}
                            />
                          </radialGradient>
                          <circle
                            className="mxd-card-bg"
                            cx={600}
                            cy={600}
                            r={600}
                          />
                        </g>
                      </svg>
                    </div>
                    <div className="mxd-features-cards__image features-image-01">
                      <Image
                        alt="Rayo Illustration"
                        width={1000}
                        height={843}
                        src="/img/demo/02_fea-img.webp"
                      />
                    </div>
                    <div className="mxd-features-cards__title">
                      <h3 className="anim-uni-in-up">
                        Dynamic &amp; stylish design
                      </h3>
                    </div>
                    <div className="mxd-features-cards__info">
                      <div className="mxd-features-cards__tags">
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Design
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Layouts
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Visuals
                        </span>
                      </div>
                      <p className="anim-uni-in-up">
                        Modern, eye-catching layouts crafted to make your brand
                        stand out and keep visitors engaged.
                      </p>
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 col-xl-4 mxd-features-cards__item mxd-grid-item anim-uni-scale-in-left">
                  <div className="mxd-features-cards__inner justify-end bg-accent radius-l padding-4">
                    <div className="mxd-features-cards__image features-image-02">
                      <Image
                        alt="Rayo Illustration"
                        width={800}
                        height={513}
                        src="/img/demo/03_fea-img.webp"
                      />
                    </div>
                    <div className="mxd-features-cards__title">
                      <h3 className="opposite anim-uni-in-up">
                        Dark / light
                        <br />
                        mode
                      </h3>
                    </div>
                    <div className="mxd-features-cards__info">
                      <div className="mxd-features-cards__tags">
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Theme Choice
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Interactive
                        </span>
                      </div>
                      <p className="t-opposite anim-uni-in-up">
                        Switch effortlessly between light and dark modes for a
                        user-friendly experience.
                      </p>
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 col-xl-4 mxd-features-cards__item mxd-grid-item anim-uni-scale-in-right">
                  <div className="mxd-features-cards__inner bg-additional radius-l padding-4">
                    <div className="mxd-features-cards__title">
                      <h3 className="anim-uni-in-up">Easy to customize</h3>
                    </div>
                    <div className="mxd-features-cards__info">
                      <div className="mxd-features-cards__tags">
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Flexible
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Fast
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          User-friendly
                        </span>
                      </div>
                      <p className="t-bright anim-uni-in-up">
                        Adapt every detail to fit your vision — no fuss, just
                        simple, clear editing.
                      </p>
                    </div>
                    <div className="mxd-features-cards__image features-image-03">
                      <Image
                        alt="Illustration"
                        width={800}
                        height={482}
                        src="/img/demo/04_fea-img.webp"
                      />
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 col-xl-4 mxd-features-cards__item mxd-grid-item anim-uni-scale-in">
                  <div className="mxd-features-cards__inner bg-base-opp radius-l padding-4">
                    <div className="mxd-features-cards__title">
                      <h3 className="opposite anim-uni-in-up">
                        GSAP-powered animations
                      </h3>
                    </div>
                    <div className="mxd-features-cards__info">
                      <div className="mxd-features-cards__tags">
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Motion
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Smooth
                        </span>
                        <span className="tag tag-default tag-outline-opposite anim-uni-in-up">
                          Interactive
                        </span>
                      </div>
                      <p className="t-opposite anim-uni-in-up">
                        Adding unique movement, scroll magic, and creative depth
                        to your pages.
                      </p>
                    </div>
                    <div className="mxd-features-cards__image features-image-04">
                      <Image
                        alt="Illustration"
                        width={800}
                        height={500}
                        src="/img/demo/06_fea-img.webp"
                      />
                    </div>
                  </div>
                </div>
                {/* item */}
                <div className="col-12 col-xl-4 mxd-features-cards__item mxd-grid-item anim-uni-scale-in-left">
                  <div className="mxd-features-cards__inner justify-end bg-base-tint radius-l padding-4">
                    <div className="mxd-features-cards__gradient features-gradient-02">
                      <svg
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 1200 1200"
                      >
                        <style
                          type="text/css"
                          dangerouslySetInnerHTML={{
                            __html:
                              "\n                            .mxd-card-bg {\n                              fill: url(#purple-radial-gradient);\n                            }\n                          ",
                          }}
                        />
                        <g>
                          <radialGradient
                            id="purple-radial-gradient"
                            cx={600}
                            cy={600}
                            r={600}
                            gradientUnits="userSpaceOnUse"
                          >
                            <stop
                              offset={0}
                              style={{
                                stopColor: "#9f8be7",
                                stopOpacity: "0.6",
                              }}
                            />
                            <stop
                              offset={1}
                              style={{ stopColor: "#9f8be7", stopOpacity: 0 }}
                            />
                          </radialGradient>
                          <circle
                            className="mxd-card-bg"
                            cx={600}
                            cy={600}
                            r={600}
                          />
                        </g>
                      </svg>
                    </div>
                    <div className="mxd-features-cards__title">
                      <h3 className="anim-uni-in-up">
                        Code
                        <br />
                        excellence
                      </h3>
                    </div>
                    <div className="mxd-features-cards__info">
                      <div className="mxd-features-cards__tags">
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Clean
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Reliable
                        </span>
                        <span className="tag tag-default tag-outline anim-uni-in-up">
                          Validated
                        </span>
                      </div>
                      <p className="anim-uni-in-up">
                        Built with clean, well-structured code that&apos;s fast,
                        secure, and easy to maintain.
                      </p>
                    </div>
                    <div className="mxd-features-cards__image features-image-05">
                      <Image
                        alt="Illustration"
                        width={700}
                        height={732}
                        src="/img/demo/05_fea-img.webp"
                      />
                    </div>
                  </div>
                </div>
                {/* teaser */}
                <div className="col-12 mxd-features-cards__teaser mxd-grid-item anim-uni-in-up">
                  <p className="mxd-point-subtitle anim-uni-in-up">
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
                    <span>and much more</span>
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
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Features Cards End */}
      </div>
    </div>
  );
}
