import RevealText from "../animation/RevealText";

export default function Feedback() {
  return (
    <div className="mxd-project__block">
      <div className="container-fluid px-0">
        <div className="row gx-0">
          <div className="col-12 col-xl-5 mxd-grid-item no-margin">
            <div className="mxd-project__subtitle">
              <RevealText as="h2" className="reveal-type anim-uni-in-up">
                Client&apos;s
                <br />
                feedback
              </RevealText>
            </div>
          </div>
          <div className="col-12 col-xl-6 mxd-grid-item no-margin">
            <div className="mxd-project__content">
              <div className="mxd-project__paragraph medium-text">
                <p className="anim-uni-in-up">
                  Working with Rayo team was an absolute pleasure! They took the
                  time to understand our business needs and translated them into
                  a beautifully designed, user-friendly website. The team&apos;s
                  attention to detail, creativity, and technical expertise
                  exceeded our expectations. We&apos;ve received so much
                  positive feedback from our customers already.
                </p>
                <div className="mxd-project__client anim-uni-in-up">
                  <h5 className="anim-uni-in-up">Lea Tomato</h5>
                  <p className="t-small anim-uni-in-up">
                    Senior designer in
                    <a className="" href="#">
                      The Way
                    </a>
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
