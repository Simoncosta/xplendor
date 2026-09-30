import AnimatedButton from "@/components/animation/AnimatedButton";
import RevealText from "@/components/animation/RevealText";
import Link from "next/link";

export default function About() {
  return (
    <div className="mxd-section padding-default">
      <div className="mxd-container grid-container">
        {/* Block - About Description with Manifest Start */}
        <div className="mxd-block">
          <div className="container-fluid px-0">
            <div className="row gx-0 d-xl-flex justify-content-xl-center">
              <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                <div className="mxd-block__content">
                  <div className="mxd-block__manifest centered anim-uni-in-up">
                    <RevealText
                      as="p"
                      className="mxd-manifest mxd-manifest-l reveal-type"
                    >
                      Web design is evolving rapidly, and I strive to stay
                      ahead. Emerging tools and technologies enable me to create
                      visually stunning and seamlessly functional websites.
                    </RevealText>
                    <div className="mxd-manifest__controls anim-uni-in-up">
                      <div className="mxd-btngroup centered">
                        <AnimatedButton
                          text="My Works"
                          className="btn btn-anim btn-default btn-accent slide-right-up"
                          href={`/works-simple`}
                        >
                          <i className="ph-bold ph-arrow-up-right" />
                        </AnimatedButton>
                        <AnimatedButton
                          text="Download CV"
                          as={"a"}
                          className="btn btn-anim btn-default btn-outline slide-down"
                          href="#"
                        >
                          <i className="ph-bold ph-arrow-down" />
                        </AnimatedButton>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - About Description with Manifest End */}
      </div>
    </div>
  );
}
