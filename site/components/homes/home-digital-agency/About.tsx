import AnimatedButton from "@/components/animation/AnimatedButton";
import RevealText from "@/components/animation/RevealText";
import Link from "next/link";

export default function About() {
  return (
    <div className="mxd-section padding-pre-grid">
      <div className="mxd-container grid-container">
        {/* Block - About Description with H2 Title and Paragraph Start */}
        <div className="mxd-block">
          <div className="container-fluid px-0">
            <div className="row gx-0">
              <div className="col-12 col-xl-5 mxd-grid-item no-margin">
                <div className="mxd-block__name">
                  <RevealText as="h2" className="reveal-type anim-uni-in-up">
                    Company
                  </RevealText>
                </div>
              </div>
              <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                <div className="mxd-block__content">
                  <div className="mxd-block__paragraph">
                    <p className="t-large t-bright anim-uni-in-up">
                      We are a creative digital agency specializing in
                      innovative design and cutting-edge development. We help
                      businesses stand out and thrive in the modern landscape.
                    </p>
                    <p className="anim-uni-in-up">
                      From pixel-perfect designs to flawless code, every aspect
                      of our projects is crafted with care to ensure the highest
                      standards of quality. We are passionate about integrating
                      the latest technologies and trends, including interactive
                      animations and mobile-first strategies.
                    </p>
                    <div className="mxd-paragraph__controls anim-uni-in-up">
                      <AnimatedButton
                        text="More About Us"
                        className="btn btn-anim btn-default btn-outline slide-right-up"
                        href={`/about-us`}
                      >
                        <i className="ph-bold ph-arrow-up-right" />
                      </AnimatedButton>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - About Description with H2 Title and Paragraph End */}
      </div>
    </div>
  );
}
