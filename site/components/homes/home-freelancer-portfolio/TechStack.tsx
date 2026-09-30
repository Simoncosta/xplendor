import RevealText from "@/components/animation/RevealText";
import Image from "next/image";

import techstack from "@/data/techstack.json";
import { Techstack } from "@/types/techStack";

export default function TechStack() {
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
                                My favorite
                                <br />
                                stack
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
                    <div className="mxd-tech-stack-cards grid-cards">
                      {techstack.map((t: Techstack, idx: number) => (
                        <div
                          className="mxd-tech-stack-cards__item animate-card-4"
                          key={idx}
                        >
                          <div className="mxd-tech-stack-cards__inner-v2">
                            <div className="mxd-tech-stack-cards__icon">
                              <Image
                                alt="Tech Stack Icon"
                                src={t.icon}
                                width={120}
                                height={90}
                              />
                            </div>
                            <div className="mxd-tech-stack-cards__title">
                              <p className="t-bright t-caption">{t.name}</p>
                            </div>
                          </div>
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
