import RevealText from "@/components/animation/RevealText";

import experiences from "@/data/experiences.json";
import { Experience } from "@/types/experiences";

export default function Experiences() {
  return (
    <div className="mxd-section padding-grid-pre-pinned">
      <div className="mxd-container grid-container">
        {/* Block - Stack Universal Pinned Block with Section Title Start */}
        <div className="mxd-block">
          <div className="mxd-pinned-universal">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                <div className="col-12 col-xl-5 mxd-pinned-universal__static">
                  <div className="mxd-pinned-universal__static-inner no-margin">
                    {/* Section Title Start */}
                    <div className="mxd-section-title h2-only no-margin-desktop">
                      <div className="container-fluid p-0">
                        <div className="row g-0">
                          <div className="col-12 mxd-grid-item no-margin">
                            <div className="mxd-section-title__title card-split-title">
                              <RevealText as="h2" className="reveal-type">
                                Experience
                              </RevealText>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    {/* Section Title Start */}
                  </div>
                </div>
                <div className="col-12 col-xl-7 mxd-pinned-universal__scroll">
                  <div className="mxd-pinned-universal__scroll-inner mxd-grid-item no-margin">
                    <div className="mxd-res-list">
                      {experiences.map((item: Experience, idx: number) => (
                        <div className="mxd-res-list__item" key={idx}>
                          <div className="mxd-res-list__divider anim-uni-in-up" />
                          <div className="mxd-res-list__content">
                            <div className="mxd-res-list__data">
                              <div className="mxd-res-list__title">
                                <h4 className="anim-uni-in-up">{item.role}</h4>
                                <p className="mxd-res-list__source anim-uni-in-up">
                                  in the{" "}
                                  <a href={item.agencyUrl} target="_blank">
                                    {item.agency}
                                  </a>{" "}
                                  agency
                                </p>
                              </div>
                              <div className="mxd-res-list__descr">
                                <p className="anim-uni-in-up">{item.desc}</p>
                              </div>
                            </div>
                            <div className="mxd-res-list__year">
                              <p className="anim-uni-in-up">{item.year}</p>
                            </div>
                          </div>
                          <div className="mxd-res-list__divider anim-uni-in-up" />
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Stack Universal Pinned Block with Section Title Start */}
      </div>
    </div>
  );
}
