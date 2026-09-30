import RevealText from "../animation/RevealText";
import BackgroundParallax from "../animation/BackgroundParallax";

export default function Solutions() {
  return (
    <>
      {/* Project Block - Solution Description with H2 Title and Paragraph Start */}
      <div className="mxd-project__block">
        <div className="container-fluid px-0">
          <div className="row gx-0">
            <div className="col-12 col-xl-5 mxd-grid-item no-margin">
              <div className="mxd-project__subtitle">
                <RevealText as="h2" className="reveal-type anim-uni-in-up">
                  Solution
                </RevealText>
              </div>
            </div>
            <div className="col-12 col-xl-6 mxd-grid-item no-margin">
              <div className="mxd-project__content">
                <div className="mxd-project__paragraph medium-text">
                  <p className="anim-uni-in-up">
                    Orci varius natoque penatibus et magnis dis parturient
                    montes, nascetur ridiculus mus. Quisque ut arcu pulvinar,
                    rhoncus libero id, lobortis metus. Morbi tristique dolor sit
                    amet turpis faucibus malesuada.
                  </p>
                  <p className="anim-uni-in-up">
                    Morbi non ipsum vel risus scelerisque sagittis nec a ipsum.
                    Nulla odio neque, feugiat a arcu et, tristique cursus diam.
                    Duis consectetur massa nibh, ut rhoncus nibh vestibulum in.
                    Sed imperdiet metus sed arcu efficitur posuere.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      {/* Project Block - Solution Description with H2 Title and Paragraph End */}
      {/* Project Block - Parallax Fullwidth Image Start */}
      <div className="mxd-project__block mxd-grid-item no-margin">
        <div className="mxd-divider">
          <BackgroundParallax
            scale={1.5}
            className="mxd-divider__image prj-details-img-02 parallax-img"
          />
        </div>
      </div>
      {/* Project Block - Parallax Fullwidth Image End */}
    </>
  );
}
