import { COMPANY } from "@/data/legal/company";

export default function Locations() {
  return (
    <div className="mxd-section padding-default">
      <div className="mxd-container grid-container">
        {/* Block - Text Block with H2 Title, Paragraph and Contact Lists Start */}
        <div className="mxd-block">
          <div className="container-fluid px-0">
            <div className="row gx-0">
              <div className="col-12 col-xl-5 mxd-grid-item no-margin">
                <div className="mxd-block__name">
                  <h2 className="reveal-type anim-uni-in-up">Fale connosco</h2>
                </div>
              </div>
              <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                <div className="mxd-block__content">
                  <div className="mxd-block__paragraph">
                    <p className="t-large t-bright anim-uni-in-up">
                      Marketing e tecnologia que trabalham juntos. Fale connosco
                      e mostramos-lhe como podemos ajudar o seu negócio a crescer.
                    </p>
                    <div className="mxd-paragraph__lists">
                      <div className="container-fluid p-0">
                        <div className="row g-0">
                          <div className="col-12 col-md-6 col-xl-5 mxd-paragraph__lists-item">
                            <div className="mxd-paragraph__lists-title">
                              <p className="t-large t-bright t-caption anim-uni-in-up">
                                Email
                              </p>
                            </div>
                            <ul>
                              <li className="anim-uni-in-up">
                                <a href={`mailto:${COMPANY.email}`}>
                                  {COMPANY.email}
                                </a>
                              </li>
                            </ul>
                          </div>
                          <div className="col-12 col-md-6 col-xl-5 mxd-paragraph__lists-item">
                            <div className="mxd-paragraph__lists-title">
                              <p className="t-large t-bright t-caption anim-uni-in-up">
                                Telefone
                              </p>
                            </div>
                            <ul>
                              <li className="anim-uni-in-up">
                                <a href="tel:+351938963526">
                                  +351 938 963 526
                                </a>
                              </li>
                            </ul>
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
        {/* Block - Text Block with H2 Title, Paragraph and Contact Lists End */}
      </div>
    </div>
  );
}
